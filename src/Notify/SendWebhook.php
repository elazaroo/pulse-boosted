<?php

namespace Elazaroo\PulseBoosted\Notify;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One webhook, sent from a worker when a queue is named for them.
 *
 * It carries the webhook's id rather than its address, which is read, and
 * decrypted, only when the job runs.
 *
 * @internal
 */
class SendWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $destination,
        public array $payload,
    ) {
        //
    }

    public function handle(Webhooks $webhooks): void
    {
        $webhooks->deliverTo($this->destination, $this->payload);
    }
}
