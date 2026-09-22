<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job that succeeds, with a mix of ordinary and sensitive arguments so the
 * detail view has something to redact.
 */
class SendWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $email = 'ada@example.com',
        public string $locale = 'es',
        public string $apiToken = 'sk_live_should_never_be_shown',
        public array $options = ['template' => 'welcome', 'password' => 'hunter2'],
    ) {
        //
    }

    public function handle(): void
    {
        usleep(random_int(50_000, 300_000));
    }
}
