<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * A job that always throws, so the Failed tab and the stack trace on the
 * detail view have something in them.
 */
class SyncToWarehouse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $warehouse = 'eu-west',
        public int $batchSize = 500,
    ) {
        //
    }

    public function handle(): void
    {
        throw new RuntimeException(
            "Warehouse [{$this->warehouse}] refused the sync: connection reset by peer."
        );
    }
}
