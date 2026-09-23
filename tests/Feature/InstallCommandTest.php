<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pulse-boosted-install-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/database/migrations');
    file_put_contents($this->sandbox.'/.env', "APP_NAME=Shop\nPULSE_BOOSTED_JOBS_STORAGE=auto\n");

    $this->app->useDatabasePath($this->sandbox.'/database');
    $this->app->useEnvironmentPath($this->sandbox);

    // The config is already there, so the command does not publish it.
    Config::set('pulse-boosted.path', 'pulse-boosted');
    $this->app->useConfigPath($this->sandbox.'/config');
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/config');
    file_put_contents($this->sandbox.'/config/pulse-boosted.php', '<?php return [];');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->sandbox);
});

/**
 * The migrations the command published, by name.
 *
 * @return list<string>
 */
function publishedMigrations(string $sandbox): array
{
    return collect((new Filesystem)->files($sandbox.'/database/migrations'))
        ->map(fn ($file) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_|\.php$/', '', $file->getFilename()))
        ->sort()
        ->values()
        ->all();
}

it('leaves out the job history tables when the queues run on Redis', function () {
    $this->artisan('pulse-boosted:install', ['--redis' => true, '--connection' => 'queues'])
        ->expectsOutputToContain('Redis')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    $migrations = publishedMigrations($this->sandbox);

    expect($migrations)
        ->not->toContain('create_pulse_boosted_jobs_table')
        ->not->toContain('create_pulse_boosted_job_attempts_table')
        ->toContain('create_pulse_boosted_tables')
        ->toContain('create_pulse_boosted_issue_tables')
        ->toContain('create_pulse_boosted_webhooks_table');

    $env = file_get_contents($this->sandbox.'/.env');

    expect($env)
        ->toContain('PULSE_BOOSTED_JOBS_STORAGE=redis')
        ->toContain('PULSE_BOOSTED_JOBS_REDIS_CONNECTION=queues')
        ->not->toContain('PULSE_BOOSTED_JOBS_STORAGE=auto')
        ->toContain('APP_NAME=Shop');
});

it('publishes them when the history is kept in the database', function () {
    $this->artisan('pulse-boosted:install', ['--database' => true, '--no-interaction' => true])->assertSuccessful();

    expect(publishedMigrations($this->sandbox))
        ->toContain('create_pulse_boosted_jobs_table')
        ->toContain('create_pulse_boosted_job_attempts_table');

    expect(file_get_contents($this->sandbox.'/.env'))->toContain('PULSE_BOOSTED_JOBS_STORAGE=database');
});

it('asks, suggesting what the queue config says', function (string $queueDriver, bool $suggested) {
    Config::set('queue.default', 'main');
    Config::set('queue.connections.main', ['driver' => $queueDriver]);
    // One Redis connection, so there is nothing to pick between.
    Config::set('database.redis', ['client' => 'predis', 'default' => Config::get('database.redis.default')]);

    $this->artisan('pulse-boosted:install')
        ->expectsConfirmation('Do your queues run on Redis?', $suggested ? 'yes' : 'no')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->sandbox.'/.env'))->toContain('PULSE_BOOSTED_JOBS_STORAGE='.($suggested ? 'redis' : 'database'));
})->with([
    ['redis', true],
    ['database', false],
    ['sync', false],
]);

it('goes by the queue config when nobody is there to ask', function () {
    Config::set('queue.default', 'main');
    Config::set('queue.connections.main', ['driver' => 'redis']);

    $this->artisan('pulse-boosted:install', ['--no-interaction' => true])->assertSuccessful();

    expect(publishedMigrations($this->sandbox))->not->toContain('create_pulse_boosted_jobs_table');
});

it('does not publish a migration twice, and says which are left over after switching to Redis', function () {
    $this->artisan('pulse-boosted:install', ['--database' => true, '--no-interaction' => true])->assertSuccessful();
    $count = count(publishedMigrations($this->sandbox));

    $this->artisan('pulse-boosted:install', ['--redis' => true, '--no-interaction' => true])
        ->expectsOutputToContain('not needed with Redis')
        ->assertSuccessful();

    expect(publishedMigrations($this->sandbox))->toHaveCount($count);
});

it('refuses both answers at once', function () {
    $this->artisan('pulse-boosted:install', ['--redis' => true, '--database' => true])->assertFailed();
});
