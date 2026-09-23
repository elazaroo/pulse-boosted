<?php

namespace Elazaroo\PulseBoosted\Notify;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * The webhooks added from the settings page.
 *
 * The address of a Slack, Discord or Teams webhook is all it takes to post
 * to that channel, so it is encrypted with the application key before it is
 * stored and never handed back to the page: a saved webhook can be renamed,
 * pointed somewhere else, switched off or removed, but not read.
 *
 * @internal
 */
class Destinations
{
    /**
     * The services a webhook can be for, and what to call them.
     */
    public const TYPES = [
        'slack' => 'Slack',
        'discord' => 'Discord',
        'teams' => 'Microsoft Teams',
        'google_chat' => 'Google Chat',
        'mattermost' => 'Mattermost',
        'telegram' => 'Telegram',
        'json' => 'Any URL (JSON)',
    ];

    public function __construct(
        protected DatabaseManager $db,
        protected Repository $config,
        protected StringEncrypter $encrypter,
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Every webhook, without its address.
     *
     * @return Collection<int, stdClass>
     */
    public function all(): Collection
    {
        return $this->rows()->map(fn (stdClass $row) => $this->withoutSecrets($row));
    }

    /**
     * The webhooks that want an event, with their addresses, for sending.
     *
     * @return Collection<int, stdClass>
     */
    public function for(string $event): Collection
    {
        $destinations = collect();

        foreach ($this->rows() as $row) {
            if ((bool) $row->enabled && ($row->events === null || in_array($event, $row->events, true)) && ($row = $this->decrypt($row)) !== null) {
                $destinations->push($row);
            }
        }

        return $destinations;
    }

    /**
     * One webhook with its address, for sending.
     */
    public function find(int $id): ?stdClass
    {
        $row = $this->rows()->firstWhere('id', $id);

        return $row === null ? null : $this->decrypt($row);
    }

    /**
     * @param  array{name: string, type: string, url: string, secret?: ?string, chat_id?: ?string, events?: ?list<string>}  $webhook
     */
    public function create(array $webhook, ?string $by = null): int
    {
        $now = CarbonImmutable::now()->getTimestamp();

        return (int) $this->pulse->ignore(fn () => $this->table()->insertGetId([
            'name' => Str::limit(trim($webhook['name']), 120, ''),
            'type' => $webhook['type'],
            'url' => $this->encrypter->encryptString(trim($webhook['url'])),
            'secret' => ($webhook['secret'] ?? null) ? $this->encrypter->encryptString((string) $webhook['secret']) : null,
            'chat_id' => ($webhook['chat_id'] ?? null) ?: null,
            'events' => ($webhook['events'] ?? null) === null ? null : json_encode(array_values($webhook['events'])),
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $by,
        ]));
    }

    /**
     * Change what can be changed without seeing the address: its name, its
     * events, whether it is on — and, when given, a new address or secret.
     *
     * @param  array{name?: string, events?: ?list<string>, enabled?: bool, url?: string, secret?: ?string, chat_id?: ?string}  $changes
     */
    public function update(int $id, array $changes): void
    {
        $values = ['updated_at' => CarbonImmutable::now()->getTimestamp()];

        if (array_key_exists('name', $changes)) {
            $values['name'] = Str::limit(trim((string) $changes['name']), 120, '');
        }

        if (array_key_exists('events', $changes)) {
            $values['events'] = $changes['events'] === null ? null : json_encode(array_values($changes['events']));
        }

        if (array_key_exists('enabled', $changes)) {
            $values['enabled'] = (bool) $changes['enabled'];
        }

        if (($changes['url'] ?? '') !== '') {
            $values['url'] = $this->encrypter->encryptString(trim((string) $changes['url']));
            // A new address has not been tried yet.
            $values['last_status'] = null;
            $values['last_error'] = null;
            $values['last_sent_at'] = null;
        }

        if (array_key_exists('secret', $changes)) {
            $values['secret'] = $changes['secret'] ? $this->encrypter->encryptString((string) $changes['secret']) : null;
        }

        if (array_key_exists('chat_id', $changes) && $changes['chat_id'] !== null && $changes['chat_id'] !== '') {
            $values['chat_id'] = $changes['chat_id'];
        }

        $this->pulse->ignore(fn () => $this->table()->where('id', $id)->update($values));
    }

    public function delete(int $id): void
    {
        $this->pulse->ignore(fn () => $this->table()->where('id', $id)->delete());
    }

    /**
     * Note how a delivery went.
     */
    public function delivered(int $id, ?int $status, ?string $error = null): void
    {
        rescue(fn () => $this->pulse->ignore(fn () => $this->table()->where('id', $id)->update([
            'last_sent_at' => CarbonImmutable::now()->getTimestamp(),
            'last_status' => $status,
            'last_error' => $error === null ? null : Str::limit($error, 250, '…'),
        ])), report: false);
    }

    public function purge(): void
    {
        $this->pulse->ignore(fn () => $this->table()->delete());
    }

    /**
     * The service a webhook address belongs to, going by its host.
     */
    public static function detect(string $url): ?string
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return match (true) {
            $host === 'hooks.slack.com' => 'slack',
            in_array($host, ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'], true) => 'discord',
            str_ends_with($host, '.webhook.office.com') || str_ends_with($host, '.logic.azure.com') || str_contains($host, 'powerplatform.com') || str_contains($host, 'powerautomate') => 'teams',
            $host === 'chat.googleapis.com' => 'google_chat',
            $host === 'api.telegram.org' => 'telegram',
            default => null,
        };
    }

    /**
     * What the page shows of a webhook's address: the host, which says where
     * it goes without being enough to post there.
     */
    public static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: 'address');
    }

    /**
     * @return Collection<int, stdClass>
     */
    protected function rows(): Collection
    {
        try {
            return $this->pulse->ignore(fn () => $this->table()->orderBy('id')->get())
                ->map(function (stdClass $row) {
                    $events = $row->events === null ? null : json_decode((string) $row->events, true);
                    $row->events = is_array($events) ? $events : null;

                    return $row;
                });
        } catch (Throwable) {
            // Not migrated yet.
            return collect();
        }
    }

    protected function withoutSecrets(stdClass $row): stdClass
    {
        $host = null;

        try {
            $host = self::host($this->encrypter->decryptString((string) $row->url));
        } catch (Throwable) {
            //
        }

        $public = clone $row;
        $public->host = $host;
        $public->readable = $host !== null;
        $public->signed = $row->secret !== null;

        unset($public->url, $public->secret);

        return $public;
    }

    protected function decrypt(stdClass $row): ?stdClass
    {
        try {
            $row->url = $this->encrypter->decryptString((string) $row->url);
            $row->secret = $row->secret === null ? null : $this->encrypter->decryptString((string) $row->secret);
        } catch (Throwable) {
            // Encrypted with an application key since rotated.
            return null;
        }

        return $row;
    }

    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_webhooks');
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
