<?php

namespace Elazaroo\PulseBoosted\Notify;

use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Support\DashboardUrl;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Posts what happened to the addresses configured for it: Slack's incoming
 * webhooks, or any URL that takes JSON.
 *
 * A plain webhook gets the whole event as JSON, signed with the configured
 * secret so the receiver can tell it came from here. Slack gets a message it
 * can show. Either way it is sent straight away with a short timeout, or
 * through a queue if one is named, so a slow receiver never holds up the
 * request that caused the event.
 *
 * @internal
 */
class Webhooks
{
    /**
     * Every event that can be sent.
     */
    public const EVENTS = [
        'issue.opened',
        'issue.regressed',
        'issue.assigned',
        'alert.triggered',
        'alert.resolved',
        'schedule.missed',
    ];

    public function __construct(
        protected Repository $config,
        protected Http $http,
        protected Queue $queue,
        protected Pulse $pulse,
        protected Tracer $tracer,
    ) {
        //
    }

    /**
     * Whether anything would be sent for an event.
     */
    public function wants(string $event): bool
    {
        if ($this->targets() === []) {
            return false;
        }

        $events = $this->config->get('pulse-boosted.webhooks.events');

        return ! is_array($events) || in_array($event, $events, true);
    }

    /**
     * Send an event to every configured address.
     *
     * @param  string  $summary  One line a person can read, used as Slack's message.
     * @param  array<string, mixed>  $data  The event's details.
     * @param  string|null  $link  Where on the dashboard to look.
     */
    public function send(string $event, string $summary, array $data, ?string $link = null): void
    {
        if (! $this->wants($event)) {
            return;
        }

        $payload = [
            'event' => $event,
            'summary' => $summary,
            'app' => (string) $this->config->get('app.name'),
            'environment' => (string) $this->config->get('app.env'),
            'url' => $link,
            'sent_at' => now()->toIso8601String(),
            'data' => $data,
        ];

        foreach ($this->targets() as [$url, $slack]) {
            $body = $slack ? $this->slackMessage($payload) : $payload;

            if (($connection = $this->config->get('pulse-boosted.webhooks.queue')) !== null && $connection !== '') {
                $this->pulse->rescue(fn () => $this->pulse->ignore(fn () => $this->queue
                    ->connection($connection === true || $connection === 'default' ? null : (string) $connection)
                    ->push(new SendWebhook($url, $body, $slack))));
            } else {
                $this->deliver($url, $body, $slack);
            }
        }
    }

    /**
     * Send a test to every configured address, straight away whatever the
     * queue setting, and say how each one answered.
     *
     * @return list<array{target: string, slack: bool, ok: bool, status: ?int, error: ?string}>
     */
    public function test(?string $by = null): array
    {
        $payload = [
            'event' => 'test',
            'summary' => 'Test from Pulse Boosted'.($by ? " by {$by}" : '').': webhooks are set up.',
            'app' => (string) $this->config->get('app.name'),
            'environment' => (string) $this->config->get('app.env'),
            'url' => (Route::has('pulse-boosted.settings') ? route('pulse-boosted.settings') : DashboardUrl::to()).'#webhooks',
            'sent_at' => now()->toIso8601String(),
            'data' => ['fields' => ['Sent from' => 'the settings page']],
        ];

        $results = [];

        foreach ($this->targets() as [$url, $slack]) {
            $body = $slack ? $this->slackMessage($payload) : $payload;

            try {
                $this->deliver($url, $body, $slack, throw: true);

                $results[] = ['target' => self::mask($url), 'slack' => $slack, 'ok' => true, 'status' => 200, 'error' => null];
            } catch (RequestException $e) {
                $results[] = ['target' => self::mask($url), 'slack' => $slack, 'ok' => false, 'status' => $e->response->status(), 'error' => Str::limit(trim($e->response->body()), 120)];
            } catch (\Throwable $e) {
                $results[] = ['target' => self::mask($url), 'slack' => $slack, 'ok' => false, 'status' => null, 'error' => Str::limit($e->getMessage(), 120)];
            }
        }

        return $results;
    }

    /**
     * An address with its secret part hidden: the host and the start of the
     * path are enough to tell which one it is.
     */
    public static function mask(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return Str::mask($url, '•', 6);
        }

        $path = $parts['path'] ?? '';

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(strlen($path) > 12 ? substr($path, 0, 12).'…'.substr($path, -4) : $path);
    }

    /**
     * Post one body to one address.
     *
     * The dashboard's own work, so never recorded, and sent straight away it
     * is never allowed to fail the code that caused it: a receiver that is
     * down is not an issue here.
     *
     * @param  array<string, mixed>  $body
     */
    public function deliver(string $url, array $body, bool $slack, bool $throw = false): void
    {
        $send = fn () => $this->tracer->ignore(fn () => $this->pulse->ignore(function () use ($url, $body, $slack) {
            $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $headers = ['User-Agent' => 'Pulse-Boosted'];

            if (! $slack && ($secret = $this->config->get('pulse-boosted.webhooks.secret')) !== null && $secret !== '') {
                $headers['X-Pulse-Boosted-Signature'] = 'sha256='.hash_hmac('sha256', $json, (string) $secret);
            }

            $this->http
                ->timeout((int) $this->config->get('pulse-boosted.webhooks.timeout', 5))
                ->withHeaders($headers)
                ->withBody($json, 'application/json')
                ->post($url)
                ->throw();
        }));

        // From a queue, a failure is left to surface so the job is retried.
        $throw ? $send() : $this->pulse->rescue($send);
    }

    /**
     * Where to send, and whether each address is Slack's.
     *
     * @return list<array{0: string, 1: bool}>
     */
    protected function targets(): array
    {
        $targets = [];

        foreach ($this->list($this->config->get('pulse-boosted.webhooks.urls')) as $url) {
            $targets[] = [$url, false];
        }

        foreach ($this->list($this->config->get('pulse-boosted.webhooks.slack')) as $url) {
            $targets[] = [$url, true];
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    protected function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(fn ($url) => trim((string) $url), (array) $value)));
    }

    /**
     * The event as a Slack message: the summary, what it is about, and a
     * link back.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function slackMessage(array $payload): array
    {
        $text = $this->escape($payload['summary']);

        if ($payload['url'] !== null) {
            $text .= "\n<".$payload['url'].'|Open in Pulse Boosted>';
        }

        /** @var array<string, mixed> $fields */
        $fields = $payload['data']['fields'] ?? [];

        $context = collect($fields)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $label) => "*{$label}:* ".$this->escape(Str::limit((string) $value, 200)))
            ->values()
            ->all();

        return [
            'text' => $payload['summary'],
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
                ...($context === [] ? [] : [['type' => 'context', 'elements' => array_map(
                    fn (string $item) => ['type' => 'mrkdwn', 'text' => $item],
                    array_slice($context, 0, 10),
                )]]),
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $this->escape($payload['app'].' · '.$payload['environment'])]]],
            ],
        ];
    }

    protected function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
