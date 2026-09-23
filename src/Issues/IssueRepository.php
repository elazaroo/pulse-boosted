<?php

namespace Elazaroo\PulseBoosted\Issues;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Elazaroo\PulseBoosted\Events\IssueOpened;
use Elazaroo\PulseBoosted\Events\IssueRegressed;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Groups exceptions into issues.
 *
 * A count of exceptions says four hundred things went wrong. An issue says
 * one thing went wrong four hundred times, when it started, whether it is
 * still happening, how many people it reached, and whether anyone has marked
 * it dealt with.
 *
 * @phpstan-type IssueRow object{
 *     id: int,
 *     fingerprint: string,
 *     class: string,
 *     kind: string,
 *     level: ?string,
 *     message: ?string,
 *     handled: bool,
 *     trace: ?string,
 *     php_version: ?string,
 *     laravel_version: ?string,
 *     first_seen_deploy: ?string,
 *     last_seen_deploy: ?string,
 *     file: ?string,
 *     line: ?int,
 *     status: string,
 *     first_seen_at: int,
 *     last_seen_at: int,
 *     occurrences: int,
 *     resolved_at: ?int
 * }
 *
 * @internal
 */
class IssueRepository
{
    /**
     * Pending writes, keyed by fingerprint.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $buffer = [];

    /**
     * Create a new repository instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected DatabaseManager $db,
        protected Repository $config,
        protected Container $container,
    ) {
        //
    }

    /**
     * Whether issue tracking is switched on.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('pulse-boosted.issues.enabled', true);
    }

    /**
     * Note an exception against its issue.
     */
    public function record(Throwable $exception, ?string $traceId, string|int|null $userId, bool $handled = false): void
    {
        if (! $this->enabled()) {
            return;
        }

        $exception = StackTrace::unwrap($exception);

        $fingerprint = $this->fingerprint($exception);

        $existing = $this->buffer[$fingerprint] ?? null;

        $this->buffer[$fingerprint] = [
            'class' => $exception::class,
            'kind' => $exception instanceof \Error ? 'error' : 'exception',
            'level' => null,
            'message' => Str::limit($exception->getMessage(), 500),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'handled' => $handled,
            // Worked out once per issue per flush: the same bug a hundred
            // times in one loop needs its stack read once, not a hundred times.
            'trace' => $existing['trace'] ?? StackTrace::serialize($exception),
            'at' => CarbonImmutable::now()->getTimestamp(),
            'count' => ($existing['count'] ?? 0) + 1,
            'occurrences' => array_merge($existing['occurrences'] ?? [], [[
                'trace_id' => $traceId,
                'user_id' => $userId === null ? null : (string) $userId,
                'handled' => $handled,
            ]]),
        ];
    }

    /**
     * Note a log line at warning or above against its issue.
     *
     * Lines are grouped by their level, where they were written from, and
     * their message with the parts that vary taken out, so "Order 7 could not
     * be charged" and "Order 9 could not be charged" are one issue.
     *
     * @param  array{0: string, 1: int}|null  $origin
     */
    public function recordLog(string $level, string $message, ?array $origin, ?string $traceId, string|int|null $userId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $pattern = self::normalizeMessage($message);
        $location = $origin === null ? '' : $origin[0].':'.$origin[1];

        $fingerprint = md5('log|'.$level.'|'.$location.'|'.$pattern);

        $existing = $this->buffer[$fingerprint] ?? null;

        $this->buffer[$fingerprint] = [
            'class' => Str::limit($pattern, 250, '…'),
            'kind' => 'log',
            'level' => $level,
            'message' => Str::limit($message, 500),
            'file' => $origin[0] ?? null,
            'line' => $origin[1] ?? null,
            // Written on purpose, so neither handled nor not.
            'handled' => true,
            'trace' => null,
            'at' => CarbonImmutable::now()->getTimestamp(),
            'count' => ($existing['count'] ?? 0) + 1,
            'occurrences' => array_merge($existing['occurrences'] ?? [], [[
                'trace_id' => $traceId,
                'user_id' => $userId === null ? null : (string) $userId,
                'handled' => true,
            ]]),
        ];
    }

    /**
     * A log message with the parts that change between writes folded away.
     */
    public static function normalizeMessage(string $message): string
    {
        $message = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '{uuid}', $message) ?? $message;
        $message = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '{email}', $message) ?? $message;
        $message = preg_replace('/"[^"]*"|\'[^\']*\'/', '"…"', $message) ?? $message;
        $message = preg_replace('/\d+(?:\.\d+)?/', '{n}', $message) ?? $message;

        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }

    /**
     * Note an execution that ran over its threshold against its issue.
     */
    public function recordSlow(string $type, string $name, int $durationMs, int $thresholdMs, ?string $traceId, string|int|null $userId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $fingerprint = md5('performance|'.$type.'|'.$name);

        $existing = $this->buffer[$fingerprint] ?? null;

        $this->buffer[$fingerprint] = [
            'class' => Str::limit('Slow '.$type.': '.$name, 250, ''),
            'kind' => 'performance',
            'level' => null,
            'message' => "{$name} took ".number_format($durationMs)."ms, over its {$thresholdMs}ms threshold",
            'file' => null,
            'line' => null,
            // Not an exception at all, so neither handled nor not.
            'handled' => true,
            'trace' => null,
            'at' => CarbonImmutable::now()->getTimestamp(),
            'count' => ($existing['count'] ?? 0) + 1,
            'occurrences' => array_merge($existing['occurrences'] ?? [], [[
                'trace_id' => $traceId,
                'user_id' => $userId === null ? null : (string) $userId,
                'handled' => true,
            ]]),
        ];
    }

    /**
     * Write everything buffered.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $issues = $this->buffer;

        $this->buffer = [];

        $this->pulse->ignore(function () use ($issues) {
            foreach ($issues as $fingerprint => $issue) {
                $this->persist($fingerprint, $issue);
            }
        });
    }

    /**
     * Upsert one issue and its occurrences.
     *
     * @param  array<string, mixed>  $issue
     */
    protected function persist(string $fingerprint, array $issue): void
    {
        $existing = $this->table()->where('fingerprint', $fingerprint)->first();

        $deploy = $this->config->get('pulse-boosted.deployment');
        $deploy = is_scalar($deploy) && (string) $deploy !== '' ? Str::limit((string) $deploy, 191, '') : null;

        $latest = [
            'last_seen_deploy' => $deploy,
            'message' => $issue['message'],
            'handled' => $issue['handled'],
            'trace' => $issue['trace'],
            'php_version' => PHP_VERSION,
            'laravel_version' => Application::VERSION,
        ];

        if ($existing === null) {
            $this->table()->insert([
                'fingerprint' => $fingerprint,
                'class' => $issue['class'],
                'kind' => $issue['kind'],
                'level' => $issue['level'] ?? null,
                'file' => $issue['file'],
                'line' => $issue['line'],
                'status' => 'open',
                'first_seen_at' => $issue['at'],
                'last_seen_at' => $issue['at'],
                'occurrences' => $issue['count'],
                'first_seen_deploy' => $deploy,
                ...$latest,
            ]);

            $event = IssueOpened::class;
        } else {
            $this->table()->where('fingerprint', $fingerprint)->update([
                // A resolved issue that happens again is a regression, and
                // saying so is the point of having marked it resolved.
                'status' => $existing->status === 'ignored' ? 'ignored' : 'open',
                'last_seen_at' => $issue['at'],
                'occurrences' => $existing->occurrences + $issue['count'],
                ...$latest,
            ]);

            $event = $existing->status === 'resolved' ? IssueRegressed::class : null;
        }

        $occurrences = is_array($issue['occurrences']) ? $issue['occurrences'] : [];

        $rows = array_map(fn (array $occurrence) => [
            'fingerprint' => $fingerprint,
            'trace_id' => $occurrence['trace_id'],
            'user_id' => $occurrence['user_id'],
            'handled' => $occurrence['handled'] ?? false,
            'occurred_at' => $issue['at'],
        ], $occurrences);

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->occurrences()->insert($chunk);
        }

        if ($event !== null && ($row = $this->table()->where('fingerprint', $fingerprint)->first()) !== null) {
            // Only the first sighting and a return after being resolved: an
            // issue already open happening again is not news.
            // Resolved at the moment of firing rather than held from boot, so
            // a dispatcher swapped in later — Event::fake() — is the one used.
            $this->container->make('events')->dispatch(new $event($row));
        }
    }

    /**
     * What makes two exceptions the same problem.
     */
    public function fingerprint(Throwable $exception): string
    {
        return md5($exception::class.'|'.$exception->getFile().'|'.$exception->getLine());
    }

    /**
     * Issues, newest activity first.
     *
     * @param  array<string, string|null>  $filters
     * @return Collection<int, IssueRow>
     */
    public function issues(array $filters = [], int $limit = 20, int $offset = 0, string $orderBy = 'latest'): Collection
    {
        return $this->pulse->ignore(fn () => $this->filtered($filters)
            ->when($orderBy === 'count', fn (Builder $query) => $query->orderByDesc('occurrences'))
            ->orderByDesc('last_seen_at')
            ->offset($offset)
            ->limit($limit)
            ->get());
    }

    /**
     * How many issues match.
     *
     * @param  array<string, string|null>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->pulse->ignore(fn () => $this->filtered($filters)->count());
    }

    /**
     * How many issues were first seen at or after a moment.
     */
    public function countFirstSeenSince(int $timestamp): int
    {
        return $this->pulse->ignore(fn () => $this->table()->where('first_seen_at', '>=', $timestamp)->count());
    }

    /**
     * How many issues sit in each state.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = $this->pulse->ignore(fn () => $this->table()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all());

        return collect(['open', 'resolved', 'ignored'])
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /**
     * How many issues are exceptions and how many are errors, under whatever
     * else is being filtered on.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    public function countsByKind(array $filters = []): array
    {
        $counts = $this->pulse->ignore(fn () => $this->filtered(array_diff_key($filters, ['kind' => null]))
            ->groupBy('kind')
            ->selectRaw('kind, count(*) as aggregate')
            ->pluck('aggregate', 'kind')
            ->all());

        $exceptions = (int) ($counts['exception'] ?? 0);
        $errors = (int) ($counts['error'] ?? 0);
        $performance = (int) ($counts['performance'] ?? 0);
        $logs = (int) ($counts['log'] ?? 0);

        return ['' => $exceptions + $errors + $logs + $performance, 'exception' => $exceptions, 'error' => $errors, 'log' => $logs, 'performance' => $performance];
    }

    /**
     * One issue.
     *
     * @return IssueRow|null
     */
    public function find(string $fingerprint): ?object
    {
        return $this->pulse->ignore(fn () => $this->table()->where('fingerprint', $fingerprint)->first());
    }

    /**
     * How many distinct users an issue has reached, within whatever window of
     * occurrences has not been trimmed away.
     */
    public function affectedUsers(string $fingerprint): int
    {
        return $this->pulse->ignore(fn () => $this->occurrences()
            ->where('fingerprint', $fingerprint)
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id'));
    }

    /**
     * The most recent occurrences of an issue.
     *
     * @return Collection<int, object>
     */
    public function recentOccurrences(string $fingerprint, int $limit = 10): Collection
    {
        return $this->pulse->ignore(fn () => $this->occurrences()
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('id')
            ->limit($limit)
            ->get());
    }

    /**
     * Move an issue to a state.
     */
    public function setStatus(string $fingerprint, string $status): void
    {
        $this->pulse->ignore(fn () => $this->table()
            ->where('fingerprint', $fingerprint)
            ->update([
                'status' => $status,
                'resolved_at' => $status === 'resolved' ? CarbonImmutable::now()->getTimestamp() : null,
            ]));
    }

    /**
     * Resolve open issues that have not happened for the given interval.
     *
     * @return int How many were resolved.
     */
    public function resolveQuietFor(string $interval): int
    {
        $now = CarbonImmutable::now();
        $before = $now->sub(CarbonInterval::fromString($interval))->getTimestamp();

        return $this->pulse->ignore(fn () => $this->table()
            ->where('status', 'open')
            ->where('last_seen_at', '<=', $before)
            ->update([
                'status' => 'resolved',
                'resolved_at' => $now->getTimestamp(),
            ]));
    }

    /**
     * Drop occurrences, and issues nothing has been heard from, past the
     * configured retention.
     */
    public function trim(): void
    {
        $keep = $this->config->get('pulse-boosted.issues.trim.keep') ?? '30 days';

        $before = CarbonImmutable::now()->sub(CarbonInterval::fromString($keep))->getTimestamp();

        $this->pulse->ignore(function () use ($before) {
            $this->occurrences()->where('occurred_at', '<=', $before)->delete();

            // A resolved issue nobody has seen since is finished with; an open
            // one is kept however old, because it is still a bug.
            $this->table()
                ->where('last_seen_at', '<=', $before)
                ->whereIn('status', ['resolved', 'ignored'])
                ->delete();
        });
    }

    /**
     * Drop everything.
     */
    public function purge(): void
    {
        $this->pulse->ignore(function () {
            $this->occurrences()->delete();
            $this->table()->delete();
        });
    }

    /**
     * Apply the list's filters.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function filtered(array $filters): Builder
    {
        $query = $this->table();

        foreach (['status', 'kind'] as $column) {
            if (($value = $filters[$column] ?? null) !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        if (($handled = $filters['handled'] ?? null) !== null && $handled !== '') {
            $query->where('handled', $handled === 'handled');
        }

        // Issues this user ran into, going by the occurrences still kept.
        if (($user = $filters['user'] ?? null) !== null && $user !== '') {
            $query->whereIn('fingerprint', $this->occurrences()->select('fingerprint')->where('user_id', $user));
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

            $query->where(fn (Builder $query) => $query
                ->where('class', 'like', "%{$escaped}%")
                ->orWhere('message', 'like', "%{$escaped}%"));
        }

        return $query;
    }

    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_issues');
    }

    protected function occurrences(): Builder
    {
        return $this->connection()->table('pulse_boosted_issue_occurrences');
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
