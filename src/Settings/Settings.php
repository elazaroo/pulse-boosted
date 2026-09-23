<?php

namespace Elazaroo\PulseBoosted\Settings;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Settings changed from the dashboard, laid over config.
 *
 * What is saved here wins over the config file and the environment, key by
 * key, and a key that was never changed keeps whatever config says. They are
 * read once per request from the cache, and from the database at most once a
 * minute per server, so a change reaches every server within a minute and
 * costs a request nothing it would notice.
 *
 * Secrets — webhook URLs, the signing secret — are encrypted with the
 * application key before they are stored.
 *
 * @internal
 */
class Settings
{
    /**
     * Under the prefix the cache recorder leaves out, so reading the settings
     * is not counted as the application's own cache hit.
     */
    public const CACHE_KEY = 'elazaroo:pulse-boosted:settings';

    public const CACHE_SECONDS = 60;

    /**
     * What config said for each field before anything was laid over it.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $defaults = null;

    /**
     * @var array<string, array{value: mixed, updated_at: int, updated_by: ?string}>|null
     */
    protected ?array $stored = null;

    /**
     * When the saved settings were last read.
     */
    protected ?int $readAt = null;

    public function __construct(
        protected DatabaseManager $db,
        protected Repository $config,
        protected Cache $cache,
        protected StringEncrypter $encrypter,
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Lay the saved settings over config.
     */
    public function apply(): void
    {
        $this->defaults ??= $this->snapshot();

        foreach ($this->stored() as $key => $row) {
            if (Schema::field($key) !== null) {
                $this->config->set("pulse-boosted.{$key}", $row['value']);
            }
        }
    }

    /**
     * Read the saved settings again once they are a minute old, for processes
     * that outlive a request: a worker, or pulse-boosted:check.
     */
    public function refreshIfStale(): void
    {
        if ($this->readAt !== null && CarbonImmutable::now()->getTimestamp() - $this->readAt >= self::CACHE_SECONDS) {
            // What was laid over config before goes back first, so a setting
            // since removed does not linger.
            foreach (array_keys($this->stored ?? []) as $key) {
                $this->config->set("pulse-boosted.{$key}", $this->default($key));
            }

            $this->stored = null;

            $this->apply();
        }
    }

    /**
     * What config said for a field, before any saved setting.
     */
    public function default(string $key): mixed
    {
        $this->defaults ??= $this->snapshot();

        return $this->defaults[$key] ?? null;
    }

    /**
     * What a field is now, saved or not.
     */
    public function value(string $key): mixed
    {
        return $this->stored()[$key]['value'] ?? $this->default($key);
    }

    /**
     * Whether a field has been changed from the dashboard.
     */
    public function changed(string $key): bool
    {
        return array_key_exists($key, $this->stored());
    }

    /**
     * When and by whom a field was last changed.
     *
     * @return array{updated_at: int, updated_by: ?string}|null
     */
    public function change(string $key): ?array
    {
        $row = $this->stored()[$key] ?? null;

        return $row === null ? null : ['updated_at' => $row['updated_at'], 'updated_by' => $row['updated_by']];
    }

    /**
     * Save a field. A value equal to what config says is not an override, so
     * it is removed instead, and config takes over again.
     */
    public function set(string $key, mixed $value, ?string $by = null): void
    {
        $field = Schema::field($key) ?? throw new \InvalidArgumentException("[{$key}] is not a setting.");

        if ($value == $this->default($key)) {
            $this->forget($key);

            return;
        }

        $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->pulse->ignore(fn () => $this->table()->upsert([[
            'key' => $key,
            'value' => Schema::secret($field) ? $this->encrypter->encryptString($json) : $json,
            'updated_at' => CarbonImmutable::now()->getTimestamp(),
            'updated_by' => $by,
        ]], ['key'], ['value', 'updated_at', 'updated_by']));

        $this->refresh();
        $this->config->set("pulse-boosted.{$key}", $value);
    }

    /**
     * Go back to what config says.
     */
    public function forget(string $key): void
    {
        $this->pulse->ignore(fn () => $this->table()->where('key', $key)->delete());

        $this->refresh();
        $this->config->set("pulse-boosted.{$key}", $this->default($key));
    }

    /**
     * Drop every saved setting.
     */
    public function purge(): void
    {
        $this->pulse->ignore(fn () => $this->table()->delete());

        $this->refresh();
    }

    /**
     * The saved settings, from the cache when it has them.
     *
     * @return array<string, array{value: mixed, updated_at: int, updated_by: ?string}>
     */
    public function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        $this->readAt = CarbonImmutable::now()->getTimestamp();

        try {
            $rows = $this->pulse->ignore(fn () => $this->store()->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->table()
                ->get(['key', 'value', 'updated_at', 'updated_by'])
                ->map(fn (object $row) => (array) $row)
                ->all()));
        } catch (Throwable) {
            // Not migrated yet, or the database is down: config it is.
            return $this->stored = [];
        }

        $stored = [];

        foreach ((array) $rows as $row) {
            $field = Schema::field((string) $row['key']);

            if ($field === null) {
                continue;
            }

            try {
                $json = Schema::secret($field) ? $this->encrypter->decryptString((string) $row['value']) : (string) $row['value'];
            } catch (Throwable) {
                // Encrypted with an application key since rotated.
                continue;
            }

            $stored[(string) $row['key']] = [
                'value' => json_decode($json, true),
                'updated_at' => (int) $row['updated_at'],
                'updated_by' => $row['updated_by'] === null ? null : (string) $row['updated_by'],
            ];
        }

        return $this->stored = $stored;
    }

    /**
     * Forget what was read, here and in the cache.
     */
    protected function refresh(): void
    {
        $this->stored = null;

        try {
            $this->pulse->ignore(fn () => $this->store()->forget(self::CACHE_KEY));
        } catch (Throwable) {
            //
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(): array
    {
        $defaults = [];

        foreach (Schema::fields() as $field) {
            $defaults[$field['key']] = $this->config->get("pulse-boosted.{$field['key']}");
        }

        return $defaults;
    }

    protected function store(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->cache->store($this->config->get('pulse-boosted.cache'));
    }

    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_settings');
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
