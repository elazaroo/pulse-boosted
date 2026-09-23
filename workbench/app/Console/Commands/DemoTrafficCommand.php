<?php

namespace Workbench\App\Console\Commands;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Deployments\Deployments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Workbench\App\Jobs\SyncToWarehouse;

/**
 * Exercises everything the dashboard can show, against the running demo.
 *
 * Requests go over HTTP to the server, and the worker, the scheduler and the
 * server snapshot run as their own processes — the way they would in a real
 * application, so each gets traced the way it would there.
 */
class DemoTrafficCommand extends Command
{
    protected $signature = 'demo:traffic {--url=http://127.0.0.1:8000 : Where the demo is being served} {--rounds=3 : How many times to go round the routes}';

    protected $description = 'Generate traffic that exercises every card on the dashboard';

    public function handle(Deployments $deployments): int
    {
        $url = rtrim((string) $this->option('url'), '/');
        $rounds = max(1, (int) $this->option('rounds'));

        $this->components->task('Creating demo users', fn () => $this->users());

        $this->components->task('Marking two deployments', function () use ($deployments) {
            $deployments->note('v2.3.0', CarbonImmutable::now()->subDays(2)->getTimestamp());
            $deployments->note('v2.4.0', CarbonImmutable::now()->subMinutes(20)->getTimestamp());
        });

        $users = DB::table('users')->pluck('id')->all();

        $this->components->task("Sending requests to {$url}", function () use ($url, $rounds, $users) {
            $http = Http::timeout(15)->withoutVerifying();

            for ($round = 1; $round <= $rounds; $round++) {
                foreach (['checkout', 'checkout', 'broken', 'coupon', 'profile', 'report', 'cart', 'search', 'reindex'] as $path) {
                    rescue(fn () => $http->get("{$url}/demo/{$path}", ['as' => $users[array_rand($users)]]), report: false);
                }

                foreach (range(1, 6) as $i) {
                    rescue(fn () => $http->get("{$url}/demo/orders/".random_int(1, 40), ['as' => $users[array_rand($users)]]), report: false);
                }

                rescue(fn () => $http->withHeaders(['Authorization' => 'Bearer demo-secret', 'X-Request-Id' => uniqid('req_')])
                    ->asForm()
                    ->post("{$url}/demo/orders?as={$users[array_rand($users)]}", [
                        'sku' => 'TSHIRT-M',
                        'quantity' => 2,
                        'email' => 'buyer@example.com',
                        'password' => 'hunter2hunter2',
                        'card_number' => '4242424242424242',
                    ]), report: false);
            }
        });

        $this->components->task('Queueing jobs that will fail', function () {
            SyncToWarehouse::dispatch('eu-west');
            SyncToWarehouse::dispatch('us-east')->onQueue('sync');
        });

        // Found from the package root rather than the skeleton Testbench boots.
        $testbench = realpath(__DIR__.'/../../../../vendor/bin/testbench') ?: 'vendor/bin/testbench';
        $artisan = [PHP_BINARY, $testbench];

        $this->components->task('Working the queue, in its own process', function () use ($artisan) {
            Process::timeout(180)->run([...$artisan, 'queue:work', '--stop-when-empty', '--sleep=0', '--queue=default,invoices,sync']);
        });

        $this->components->task('Running the scheduler, in its own process', function () use ($artisan) {
            Process::timeout(60)->run([...$artisan, 'schedule:run']);
        });

        $this->components->task('Taking a server snapshot', function () use ($artisan) {
            Process::timeout(60)->run([...$artisan, 'pulse-boosted:check', '--once']);
        });

        $this->components->task('Checking the alert rules', function () use ($artisan) {
            Process::timeout(60)->run([...$artisan, 'pulse-boosted:alerts']);
        });

        $this->newLine();
        $this->components->info("Done. Open {$url}/pulse-boosted");

        return self::SUCCESS;
    }

    /**
     * A handful of people for the requests to be signed in as.
     */
    protected function users(): void
    {
        if (DB::table('users')->count() > 0) {
            return;
        }

        foreach (['Ada Lovelace', 'Grace Hopper', 'Alan Turing', 'Katherine Johnson', 'Linus Torvalds'] as $i => $name) {
            DB::table('users')->insert([
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
                'password' => Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
