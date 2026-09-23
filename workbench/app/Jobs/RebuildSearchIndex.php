<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Slower than its threshold, so it opens a performance issue.
 */
class RebuildSearchIndex implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        foreach (range(1, 20) as $i) {
            DB::table('users')->where('id', $i)->first();
        }

        usleep(600_000);
    }
}
