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
use stdClass;
use Throwable;

/**
 * Posts what happened to the webhooks added on the settings page, each in
 * the shape its service shows best.
 *
 * Sent straight away with a short timeout, or from a queue when one is named,
 * so a slow receiver never holds up the request that caused the event. A
 * queued delivery carries the webhook's id, never its address, so the
 * address is not written to the jobs table in the clear.
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
        protected Destinations $destinations,
    ) {
        //
    }

    /**
     * Whether any webhook would get an event.
     */
    public function wants(string $event): bool
    {
        return $this->destinations->for($event)->isNotEmpty();
    }

    /**
     * Send an event to every webhook that wants it.
     *
     * @param  string  $summary  One line a person can read.
     * @param  array<string, mixed>  $data  The event's details; "fields" are shown as a list.
     * @param  string|null  $link  Where on the dashboard to look.
     */
    public function send(string $event, string $summary, array $data, ?string $link = null): void
    {
        $destinations = $this->destinations->for($event);

        if ($destinations->isEmpty()) {
            return;
        }

        $payload = $this->payload($event, $summary, $data, $link);

        foreach ($destinations as $destination) {
            if (($connection = $this->config->get('pulse-boosted.webhooks.queue')) !== null && $connection !== '') {
                $this->pulse->rescue(fn () => $this->pulse->ignore(fn () => $this->queue
                    ->connection($connection === true || $connection === 'default' ? null : (string) $connection)
                    ->push(new SendWebhook((int) $destination->id, $payload))));
            } else {
                try {
                    $this->deliver($destination, $payload);
                } catch (Throwable) {
                    // A receiver that is down is not a bug here, and is not
                    // the code that caused the event's problem: it is noted
                    // on the webhook, where the settings page shows it.
                }
            }
        }
    }

    /**
     * Send a test to one webhook, or every one, straight away whatever the
     * queue setting, and say how each answered.
     *
     * @return list<array{id: int, name: string, ok: bool, status: ?int, error: ?string}>
     */
    public function test(?int $id = null, ?string $by = null): array
    {
        $payload = $this->payload(
            'test',
            'Test from Pulse Boosted'.($by ? " by {$by}" : '').': this webhook works.',
            ['fields' => ['Sent from' => 'the settings page']],
            (Route::has('pulse-boosted.settings') ? route('pulse-boosted.settings') : DashboardUrl::to()).'#webhooks',
        );

        $results = [];

        foreach ($this->destinations->all() as $public) {
            if ($id !== null && (int) $public->id !== $id) {
                continue;
            }

            $destination = $this->destinations->find((int) $public->id);

            if ($destination === null) {
                $results[] = ['id' => (int) $public->id, 'name' => $public->name, 'ok' => false, 'status' => null, 'error' => 'Saved with a different application key: enter the address again.'];

                continue;
            }

            try {
                $this->deliver($destination, $payload);

                $results[] = ['id' => (int) $public->id, 'name' => $public->name, 'ok' => true, 'status' => 200, 'error' => null];
            } catch (RequestException $e) {
                $results[] = ['id' => (int) $public->id, 'name' => $public->name, 'ok' => false, 'status' => $e->response->status(), 'error' => Str::limit(trim(strip_tags($e->response->body())), 160)];
            } catch (Throwable $e) {
                $results[] = ['id' => (int) $public->id, 'name' => $public->name, 'ok' => false, 'status' => null, 'error' => Str::limit($e->getMessage(), 160)];
            }
        }

        return $results;
    }

    /**
     * Post an event to one webhook, noting how it went. Throws when the
     * receiver says no, so a queued delivery is retried.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(stdClass $destination, array $payload): void
    {
        try {
            $this->tracer->ignore(fn () => $this->pulse->ignore(function () use ($destination, $payload) {
                $body = Formats::body((string) $destination->type, $payload, $destination->chat_id ?? null);
                $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $headers = ['User-Agent' => 'Pulse-Boosted'];

                if ($destination->type === 'json') {
                    $headers['X-Pulse-Boosted-Event'] = (string) $payload['event'];

                    if (($destination->secret ?? null) !== null && $destination->secret !== '') {
                        $headers['X-Pulse-Boosted-Signature'] = 'sha256='.hash_hmac('sha256', $json, (string) $destination->secret);
                    }
                }

                $response = $this->http
                    ->timeout((int) $this->config->get('pulse-boosted.webhooks.timeout', 5))
                    ->withHeaders($headers)
                    ->withBody($json, 'application/json')
                    ->post((string) $destination->url)
                    ->throw();

                $this->destinations->delivered((int) $destination->id, $response->status());
            }));
        } catch (RequestException $e) {
            $this->destinations->delivered((int) $destination->id, $e->response->status(), trim(strip_tags($e->response->body())) ?: null);

            throw $e;
        } catch (Throwable $e) {
            $this->destinations->delivered((int) $destination->id, null, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Deliver to a webhook by its id, for a queued delivery.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliverTo(int $id, array $payload): void
    {
        if (($destination = $this->destinations->find($id)) !== null && $destination->enabled) {
            $this->deliver($destination, $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{event: string, summary: string, app: string, environment: string, url: ?string, sent_at: string, data: array<string, mixed>}
     */
    protected function payload(string $event, string $summary, array $data, ?string $link): array
    {
        return [
            'event' => $event,
            'summary' => $summary,
            'app' => (string) $this->config->get('app.name'),
            'environment' => (string) $this->config->get('app.env'),
            'url' => $link,
            'sent_at' => now()->toIso8601String(),
            'data' => $data,
        ];
    }
}
