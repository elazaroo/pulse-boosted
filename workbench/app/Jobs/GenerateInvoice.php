<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A slower job, so the dashboard's duration column and the slow job card have
 * something to report.
 */
class GenerateInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public int $invoiceId = 1,
        public string $currency = 'EUR',
    ) {
        //
    }

    public function handle(): void
    {
        usleep(random_int(600_000, 1_400_000));
    }
}
