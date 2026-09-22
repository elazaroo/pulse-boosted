<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Jobs\GenerateInvoice;
use Workbench\App\Jobs\SendWelcomeEmail;
use Workbench\App\Jobs\SyncToWarehouse;

/**
 * Fills the demo queue so every tab in the explorer has something in it.
 */
class SeedQueueCommand extends Command
{
    protected $signature = 'demo:seed-queue';

    protected $description = 'Queue and run a mix of jobs so the dashboard has data';

    public function handle(): int
    {
        $this->components->task('Queueing jobs that will be worked', function () {
            foreach (range(1, 6) as $i) {
                SendWelcomeEmail::dispatch("user{$i}@example.com");
            }

            GenerateInvoice::dispatch(1001);
            GenerateInvoice::dispatch(1002)->onQueue('invoices');

            // These throw, and end up on the Failed tab.
            SyncToWarehouse::dispatch('eu-west');
            SyncToWarehouse::dispatch('us-east')->onQueue('sync');
        });

        $this->components->task('Working them', function () {
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--sleep' => 0,
                '--tries' => 1,
                '--queue' => 'default,invoices,sync',
            ]);
        });

        $this->components->task('Leaving jobs waiting on the queue', function () {
            foreach (range(1, 4) as $i) {
                SendWelcomeEmail::dispatch("waiting{$i}@example.com");
            }

            GenerateInvoice::dispatch(2001)->onQueue('invoices');

            // Scheduled for later, so the Delayed tab is not empty.
            SendWelcomeEmail::dispatch('later@example.com')->delay(now()->addMinutes(30));
            GenerateInvoice::dispatch(2002)->delay(now()->addHours(2));
        });

        $this->newLine();
        $this->components->info('Queue seeded. Open /pulse-boosted/queues.');

        return self::SUCCESS;
    }
}
