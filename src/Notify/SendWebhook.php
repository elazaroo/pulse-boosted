<?php

namespace Elazaroo\PulseBoosted\Notify;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One webhook, sent from a worker when a queue is named for them.
 *
 * @internal
 */
class SendWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public string $url,
        public array $body,
        public bool $slack,
    ) {
        //
    }

    public function handle(Webhooks $webhooks): void
    {
        $webhooks->deliver($this->url, $this->body, $this->slack, throw: true);
    }
}
