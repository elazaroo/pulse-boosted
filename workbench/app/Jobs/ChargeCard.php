<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fails twice and then goes through, so the job panel has a history of
 * attempts to show: released, released, processed.
 */
class ChargeCard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    public $backoff = 0;

    public function __construct(public int $orderId)
    {
        //
    }

    public function handle(): void
    {
        $attempt = Cache::increment("demo:charge:{$this->orderId}");

        Log::info('Charging card', ['order' => $this->orderId, 'attempt' => $attempt]);

        if ($attempt < 3) {
            throw new RuntimeException("Payment gateway timed out for order {$this->orderId}");
        }
    }
}
