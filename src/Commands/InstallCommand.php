<?php

namespace Elazaroo\PulseBoosted\Commands;

use Elazaroo\PulseBoosted\Queues\JobStorage;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Sets Pulse Boosted up: the config, where the job history is kept, and only
 * the migrations that choice needs.
 *
 * The job history — completed and failed jobs and every attempt at each —
 * can live in Redis, alongside queues that already run there, or in the
 * database. Kept in Redis it needs no tables, so the two migrations for them
 * are not published at all.
 *
 * @internal
 */
#[AsCommand(name: 'pulse-boosted:install')]
class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pulse-boosted:install
                            {--redis : Keep the job history in Redis, without its tables}
                            {--database : Keep the job history in the database}
                            {--connection= : The Redis connection to keep it on}
                            {--migrate : Run the migrations when done}';

    /**
     * @var string
     */
    protected $description = 'Install Pulse Boosted: config, job history storage, and the migrations it needs';

    public function handle(Filesystem $files): int
    {
        $this->publishConfig($files);

        $redis = $this->choosesRedis();

        if ($redis === null) {
            $this->components->error('Pick one of --redis and --database.');

            return self::FAILURE;
        }

        $connection = $redis ? $this->redisConnection() : null;

        $this->writeEnvironment($files, [
            'PULSE_BOOSTED_JOBS_STORAGE' => $redis ? 'redis' : 'database',
            ...($connection !== null && $connection !== 'default' ? ['PULSE_BOOSTED_JOBS_REDIS_CONNECTION' => $connection] : []),
        ]);

        [$published, $skipped, $leftover] = $this->publishMigrations($files, $redis);

        $this->components->twoColumnDetail('Job history', $redis ? "Redis (<fg=gray>{$connection}</>)" : 'Database');

        foreach ($published as $migration) {
            $this->components->twoColumnDetail("<fg=gray>Published</> {$migration}", '<fg=green;options=bold>DONE</>');
        }

        foreach ($skipped as $migration) {
            $this->components->twoColumnDetail("<fg=gray>Not needed</> {$migration}", '<fg=yellow;options=bold>SKIPPED</>');
        }

        if ($leftover !== []) {
            $this->components->warn('These were published before and are not needed with Redis. Remove them, and their tables if they were migrated: '.implode(', ', $leftover));
        }

        if ($this->option('migrate') || ($this->input->isInteractive() && confirm('Run the migrations now?', default: true))) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->info('Pulse Boosted is installed.');
        $this->components->bulletList([
            'Run <fg=yellow>php artisan pulse-boosted:check</> on each server, under your process manager.',
            'Open '.url((string) config('pulse-boosted.path', 'pulse-boosted')).', and '.url((string) config('pulse-boosted.path', 'pulse-boosted')).'/settings for webhooks and thresholds.',
            'Outside the local environment, define the <fg=yellow>viewPulseBoosted</> gate.',
        ]);

        return self::SUCCESS;
    }

    /**
     * Ask whether the queues run on Redis, suggesting what the queue config
     * says.
     */
    protected function choosesRedis(): ?bool
    {
        if ($this->option('redis') && $this->option('database')) {
            return null;
        }

        if ($this->option('redis') || $this->option('database')) {
            return (bool) $this->option('redis');
        }

        $detected = config('queue.connections.'.config('queue.default').'.driver') === 'redis';

        if (! $this->input->isInteractive()) {
            return $detected;
        }

        // No hint: the oldest Prompts Laravel 10 allows has no such argument.
        $this->components->info('If your queues run on Redis, the job history is kept there too and its two tables are not created.');

        return confirm('Do your queues run on Redis?', default: $detected);
    }

    protected function redisConnection(): string
    {
        if (is_string($connection = $this->option('connection')) && $connection !== '') {
            return $connection;
        }

        $connections = array_values(array_diff(array_keys((array) config('database.redis', [])), ['client', 'options', 'clusters']));

        if (! $this->input->isInteractive() || count($connections) <= 1) {
            return $connections[0] ?? 'default';
        }

        return (string) select(
            label: 'Which Redis connection?',
            options: $connections,
            default: in_array('default', $connections, true) ? 'default' : $connections[0],
        );
    }

    protected function publishConfig(Filesystem $files): void
    {
        if (! $files->exists(config_path('pulse-boosted.php'))) {
            $this->callSilently('vendor:publish', ['--tag' => 'pulse-boosted-config']);
            $this->components->twoColumnDetail('Config', '<fg=green;options=bold>PUBLISHED</>');
        }
    }

    /**
     * Copy the package's migrations the app does not have yet, leaving out
     * the job history's when it is kept in Redis.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    protected function publishMigrations(Filesystem $files, bool $redis): array
    {
        $source = __DIR__.'/../../database/migrations';
        $target = database_path('migrations');

        $files->ensureDirectoryExists($target);

        $existing = collect($files->files($target))->map(fn ($file) => $this->name($file->getFilename()));

        $published = $skipped = $leftover = [];

        foreach ($files->files($source) as $file) {
            $name = $this->name($file->getFilename());
            $forJobs = in_array($name, JobStorage::migrations(), true);

            if ($redis && $forJobs) {
                $existing->contains($name) ? $leftover[] = $name : $skipped[] = $name;

                continue;
            }

            if ($existing->contains($name)) {
                continue;
            }

            $files->copy($file->getPathname(), $target.DIRECTORY_SEPARATOR.$file->getFilename());
            $published[] = $name;
        }

        return [$published, $skipped, $leftover];
    }

    /**
     * A migration's name without its timestamp, so one published before under
     * another timestamp is still recognised.
     */
    protected function name(string $filename): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_|\.php$/', '', $filename);
    }

    /**
     * Set keys in .env, replacing them when they are there already.
     *
     * @param  array<string, string>  $values
     */
    protected function writeEnvironment(Filesystem $files, array $values): void
    {
        $path = app()->environmentFilePath();

        if (! $files->exists($path)) {
            $this->components->warn('No .env file: add '.collect($values)->map(fn ($v, $k) => "{$k}={$v}")->implode(' and ').' to your environment.');

            return;
        }

        $contents = $files->get($path);

        foreach ($values as $key => $value) {
            $line = "{$key}={$value}";

            $contents = preg_match("/^{$key}=.*$/m", $contents)
                ? (string) preg_replace("/^{$key}=.*$/m", $line, $contents)
                : rtrim($contents, "\n")."\n{$line}\n";
        }

        $files->put($path, $contents);

        foreach ($values as $key => $value) {
            config()->set($key === 'PULSE_BOOSTED_JOBS_STORAGE' ? 'pulse-boosted.queues.storage.driver' : 'pulse-boosted.queues.storage.redis.connection', $value);
        }
    }
}
