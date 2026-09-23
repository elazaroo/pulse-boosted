<?php

namespace Elazaroo\PulseBoosted\Queues;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Recorders\Jobs;
use Elazaroo\PulseBoosted\Support\RedisAdapter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Keeps the job history in Redis, for applications whose queues already run
 * on it and would rather not add tables for it.
 *
 * Each job is a hash, kept in a sorted set for the order they were first seen
 * in and in one per status, so a tab is one ZREVRANGE and its count one
 * ZCARD. Attempts are a list per job. Everything expires after the same
 * retention the database version keeps, and trim() drops what has expired
 * from the indexes too.
 *
 * Filters other than the status are applied while reading, over at most
 * SCAN_LIMIT of the newest jobs: Redis has no WHERE, and a dashboard would
 * rather be quick than exhaustive over a week of history.
 *
 * @phpstan-import-type RecordedJob from JobRepository
 * @phpstan-import-type JobFilters from JobRepository
 *
 * @internal
 */
class RedisJobRepository implements JobRepository
{
    /**
     * The most jobs a filtered read looks through.
     */
    public const SCAN_LIMIT = 20_000;

    public const PREFIX = 'elazaroo:pulse-boosted:jobs:';

    /**
     * Columns read back as integers, as the database returns them.
     */
    protected const INTEGERS = ['id', 'queued_at', 'started_at', 'finished_at', 'duration_ms', 'attempts', 'max_tries', 'timeout'];

    /**
     * Every column a job has, so a record read back has them all.
     */
    protected const COLUMNS = [
        'id', 'uuid', 'job_id', 'connection', 'queue', 'name', 'class', 'status', 'queued_at', 'started_at',
        'finished_at', 'duration_ms', 'attempts', 'max_tries', 'timeout', 'payload', 'tags', 'exception',
        'exception_class', 'batch_id', 'worker',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $buffer = [];

    /**
     * @var list<array<string, mixed>>
     */
    protected array $attemptBuffer = [];

    public function __construct(
        protected Pulse $pulse,
        protected RedisManager $redis,
        protected Repository $config,
    ) {
        //
    }

    public function record(string $uuid, array $attributes): void
    {
        $this->buffer[$uuid] = array_merge(
            $this->buffer[$uuid] ?? [],
            array_filter($attributes, fn ($value) => $value !== null),
        );
    }

    public function recordAttempt(string $uuid, array $attempt): void
    {
        $this->attemptBuffer[] = ['uuid' => $uuid] + $attempt;
    }

    public function flush(): void
    {
        $jobs = $this->buffer;
        $attempts = $this->attemptBuffer;

        $this->buffer = [];
        $this->attemptBuffer = [];

        if ($jobs === [] && $attempts === []) {
            return;
        }

        $this->pulse->ignore(function () use ($jobs, $attempts) {
            $uuids = array_keys($jobs);
            $ttl = (string) $this->ttl();

            // What is known already: whether each job has an id yet, and the
            // status it is filed under.
            $known = $uuids === [] ? [] : $this->adapter()->pipeline(function (RedisAdapter $pipeline) use ($uuids) {
                foreach ($uuids as $uuid) {
                    $pipeline->command('HMGET', $this->key("job:{$uuid}"), 'id', 'status');
                }
            });

            $new = [];

            foreach ($uuids as $i => $uuid) {
                if (($known[$i][0] ?? null) === null) {
                    $new[] = $uuid;
                }
            }

            $ids = $new === [] ? [] : array_combine($new, array_map('intval', $this->adapter()->pipeline(function (RedisAdapter $pipeline) use ($new) {
                foreach ($new as $uuid) {
                    $pipeline->command('INCR', $this->key('seq'));
                }
            })));

            $this->adapter()->pipeline(function (RedisAdapter $pipeline) use ($jobs, $uuids, $known, $ids, $attempts, $ttl) {
                foreach ($uuids as $i => $uuid) {
                    $attributes = $jobs[$uuid];
                    $jobKey = $this->key("job:{$uuid}");
                    $id = (int) ($ids[$uuid] ?? $known[$i][0]);
                    $previous = $known[$i][1] ?? null;

                    if (isset($ids[$uuid])) {
                        $attributes['id'] = $id;
                        $attributes['uuid'] = $uuid;

                        $pipeline->command('ZADD', $this->key('all'), (string) $id, $uuid);
                        $pipeline->command('ZADD', $this->key('time'), (string) ($attributes['queued_at'] ?? $attributes['started_at'] ?? CarbonImmutable::now()->getTimestamp()), $uuid);
                    }

                    $fields = [];

                    foreach ($attributes as $column => $value) {
                        $fields[] = (string) $column;
                        $fields[] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    }

                    if ($fields !== []) {
                        $pipeline->command('HSET', $jobKey, ...$fields);
                    }

                    if (isset($attributes['status']) && $attributes['status'] !== $previous) {
                        if ($previous !== null) {
                            $pipeline->command('ZREM', $this->key("status:{$previous}"), $uuid);
                        }

                        $pipeline->command('ZADD', $this->key("status:{$attributes['status']}"), (string) $id, $uuid);
                    }

                    if (isset($attributes['connection'], $attributes['queue'])) {
                        $pipeline->command('SADD', $this->key('queues'), $attributes['connection']."\x1f".$attributes['queue']);
                    }

                    $pipeline->command('EXPIRE', $jobKey, $ttl);
                }

                foreach ($attempts as $attempt) {
                    $listKey = $this->key("attempts:{$attempt['uuid']}");

                    $pipeline->command('RPUSH', $listKey, (string) json_encode($attempt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $pipeline->command('EXPIRE', $listKey, $ttl);
                }
            });
        });
    }

    public function trim(): void
    {
        $cutoff = CarbonImmutable::now()->subSeconds($this->ttl())->getTimestamp();

        $this->pulse->ignore(function () use ($cutoff) {
            do {
                $expired = $this->list($this->adapter()->command('ZRANGEBYSCORE', $this->key('time'), '-inf', (string) $cutoff, 'LIMIT', '0', '500'));

                if ($expired === []) {
                    break;
                }

                $this->forgetJobs($expired);
            } while (count($expired) === 500);
        });
    }

    public function purge(): void
    {
        $this->pulse->ignore(function () {
            $prefix = (string) $this->config->get('database.redis.options.prefix');
            $cursor = '0';

            do {
                [$cursor, $keys] = $this->adapter()->raw('SCAN', $cursor, 'MATCH', $prefix.self::PREFIX.'*', 'COUNT', '500');

                if ($keys !== []) {
                    // Already prefixed, as SCAN returns them.
                    $this->adapter()->raw('DEL', ...$keys);
                }
            } while ((string) $cursor !== '0');
        });
    }

    /**
     * @return RecordedJob|null
     */
    public function find(string $uuid): ?object
    {
        // @phpstan-ignore return.type
        return $this->pulse->ignore(fn () => $this->hydrate($this->adapter()->command('HGETALL', $this->key("job:{$uuid}"))));
    }

    /**
     * @param  JobFilters  $filters
     * @return Collection<int, RecordedJob>
     */
    public function jobs(array $filters = [], int $limit = 50, int $offset = 0): Collection
    {
        // @phpstan-ignore return.type
        return $this->pulse->ignore(function () use ($filters, $limit, $offset) {
            $set = $this->setFor($filters);

            if (! $this->needsScan($filters)) {
                return $this->fetch($this->list($this->adapter()->command('ZREVRANGE', $set, (string) $offset, (string) ($offset + $limit - 1))));
            }

            return $this->scan($set, $filters, $offset + $limit)->slice($offset, $limit)->values();
        });
    }

    /**
     * @param  JobFilters  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->pulse->ignore(fn () => $this->needsScan($filters)
            ? $this->scan($this->setFor($filters), $filters)->count()
            : (int) $this->adapter()->command('ZCARD', $this->setFor($filters)));
    }

    /**
     * @param  JobFilters  $filters
     * @return array<string, int>
     */
    public function countsByStatus(array $filters = []): array
    {
        $filters = array_diff_key($filters, ['status' => null]);

        return $this->pulse->ignore(function () use ($filters) {
            if ($this->needsScan($filters)) {
                $counts = $this->scan($this->key('all'), $filters)->countBy('status')->all();
            } else {
                $statuses = array_map(fn (JobStatus $status) => $status->value, JobStatus::cases());

                $counts = array_combine($statuses, $this->adapter()->pipeline(function (RedisAdapter $pipeline) use ($statuses) {
                    foreach ($statuses as $status) {
                        $pipeline->command('ZCARD', $this->key("status:{$status}"));
                    }
                }));
            }

            return collect(JobStatus::cases())
                ->mapWithKeys(fn (JobStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
                ->all();
        });
    }

    public function attempts(string $uuid): Collection
    {
        return $this->pulse->ignore(fn () => collect($this->list($this->adapter()->command('LRANGE', $this->key("attempts:{$uuid}"), '0', '99')))
            ->map(function (string $json, int $i) {
                $attempt = json_decode($json, true);

                return (object) ['id' => $i + 1, ...(is_array($attempt) ? $attempt : [])];
            })
            ->sortBy(fn (object $attempt) => [(int) ($attempt->attempt ?? 0), $attempt->id])
            ->values());
    }

    public function recordedQueues(): Collection
    {
        return $this->pulse->ignore(fn () => collect($this->list($this->adapter()->command('SMEMBERS', $this->key('queues'))))
            ->map(function (string $pair) {
                [$connection, $queue] = array_pad(explode("\x1f", $pair, 2), 2, '');

                return (object) ['connection' => $connection, 'queue' => $queue];
            })
            ->sortBy(fn (object $row) => [$row->connection, $row->queue])
            ->values());
    }

    /**
     * Read jobs newest first, keeping those that match, until enough are
     * found or SCAN_LIMIT have been looked at.
     *
     * @param  array<string, string|null>  $filters
     * @return Collection<int, object>
     */
    protected function scan(string $set, array $filters, ?int $enough = null): Collection
    {
        $found = collect();

        for ($start = 0; $start < self::SCAN_LIMIT; $start += 500) {
            $uuids = $this->list($this->adapter()->command('ZREVRANGE', $set, (string) $start, (string) ($start + 499)));

            foreach ($this->fetch($uuids) as $job) {
                if ($this->matches($job, $filters)) {
                    $found->push($job);

                    if ($enough !== null && $found->count() >= $enough) {
                        return $found;
                    }
                }
            }

            if (count($uuids) < 500) {
                break;
            }
        }

        return $found;
    }

    /**
     * The same filters as the database version, applied in PHP.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function matches(object $job, array $filters): bool
    {
        foreach (['status', 'connection', 'queue', 'class', 'batch_id'] as $column) {
            if (($value = $filters[$column] ?? null) !== null && $value !== '' && (string) ($job->{$column} ?? '') !== $value) {
                return false;
            }
        }

        if (($after = $filters['finished_after'] ?? null) !== null && $after !== '' && (int) ($job->finished_at ?? 0) < (int) $after) {
            return false;
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $haystack = strtolower(($job->name ?? '').' '.($job->uuid ?? '').' '.($job->exception_class ?? ''));

            if (! str_contains($haystack, strtolower($search))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    protected function needsScan(array $filters): bool
    {
        foreach (['connection', 'queue', 'class', 'batch_id', 'search', 'finished_after'] as $filter) {
            if (($filters[$filter] ?? null) !== null && $filters[$filter] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    protected function setFor(array $filters): string
    {
        $status = $filters['status'] ?? null;

        return $status !== null && $status !== '' ? $this->key("status:{$status}") : $this->key('all');
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, object>
     */
    protected function fetch(array $uuids): Collection
    {
        if ($uuids === []) {
            return collect();
        }

        $hashes = $this->adapter()->pipeline(function (RedisAdapter $pipeline) use ($uuids) {
            foreach ($uuids as $uuid) {
                $pipeline->command('HGETALL', $this->key("job:{$uuid}"));
            }
        });

        $jobs = collect();
        $gone = [];

        foreach ($uuids as $i => $uuid) {
            $job = $this->hydrate($hashes[$i] ?? []);

            $job === null ? $gone[] = $uuid : $jobs->push($job);
        }

        // Expired before trim() got to it: out of the indexes too.
        if ($gone !== []) {
            $this->forgetJobs($gone);
        }

        return $jobs;
    }

    /**
     * @param  list<string>  $uuids
     */
    protected function forgetJobs(array $uuids): void
    {
        $this->adapter()->pipeline(function (RedisAdapter $pipeline) use ($uuids) {
            foreach ($uuids as $uuid) {
                $pipeline->command('DEL', $this->key("job:{$uuid}"));
                $pipeline->command('DEL', $this->key("attempts:{$uuid}"));
                $pipeline->command('ZREM', $this->key('all'), $uuid);
                $pipeline->command('ZREM', $this->key('time'), $uuid);

                foreach (JobStatus::cases() as $status) {
                    $pipeline->command('ZREM', $this->key("status:{$status->value}"), $uuid);
                }
            }
        });
    }

    /**
     * A hash as a job record, or null when there is none.
     */
    protected function hydrate(mixed $hash): ?stdClass
    {
        $fields = $this->pairs($hash);

        if ($fields === [] || ! isset($fields['uuid'])) {
            return null;
        }

        $job = new stdClass;

        foreach (self::COLUMNS as $column) {
            $value = $fields[$column] ?? null;

            $job->{$column} = $value !== null && in_array($column, self::INTEGERS, true) ? (int) $value : $value;
        }

        return $job;
    }

    /**
     * HGETALL comes back as a flat list or an associative array depending on
     * the client; this is always the associative array.
     *
     * @return array<string, string>
     */
    protected function pairs(mixed $hash): array
    {
        if (! is_array($hash) || $hash === []) {
            return [];
        }

        if (array_is_list($hash)) {
            $pairs = [];

            for ($i = 0; $i + 1 < count($hash); $i += 2) {
                $pairs[(string) $hash[$i]] = (string) $hash[$i + 1];
            }

            return $pairs;
        }

        return array_map('strval', $hash);
    }

    /**
     * @return list<string>
     */
    protected function list(mixed $result): array
    {
        return is_array($result) ? array_values(array_map('strval', $result)) : [];
    }

    /**
     * How long a job is kept, in seconds.
     */
    protected function ttl(): int
    {
        $keep = $this->config->get('pulse-boosted.recorders.'.Jobs::class.'.trim.keep') ?? '7 days';

        return max(60, (int) CarbonInterval::fromString((string) $keep)->totalSeconds);
    }

    protected function key(string $key): string
    {
        return self::PREFIX.$key;
    }

    protected function adapter(): RedisAdapter
    {
        return new RedisAdapter(
            $this->redis->connection($this->config->get('pulse-boosted.queues.storage.redis.connection') ?? 'default'),
            $this->config,
        );
    }
}
