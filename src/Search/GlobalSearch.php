<?php

namespace Elazaroo\PulseBoosted\Search;

use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Support\DashboardUrl;
use Elazaroo\PulseBoosted\Support\People;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * One box that finds anything the dashboard has: issues, traces, jobs,
 * routes, scheduled tasks and users.
 *
 * Each result is a link that opens the thing it found, so a search result,
 * a bookmark and a link pasted into a chat all work the same way.
 *
 * @internal
 */
class GlobalSearch
{
    /**
     * Results per kind.
     */
    public const LIMIT = 5;

    public function __construct(
        protected DatabaseManager $db,
        protected Repository $config,
        protected Pulse $pulse,
        protected People $people,
    ) {
        //
    }

    /**
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->pulse->ignore(fn () => [
            ...$this->safely(fn () => $this->issues($query)),
            ...$this->safely(fn () => $this->traces($query)),
            ...$this->safely(fn () => $this->jobs($query)),
            ...$this->safely(fn () => $this->routes($query)),
            ...$this->safely(fn () => $this->tasks($query)),
            ...$this->safely(fn () => $this->users($query)),
        ]);
    }

    /**
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    protected function issues(string $query): array
    {
        $like = $this->like($query);

        return $this->connection()->table('pulse_boosted_issues')
            ->where(fn ($where) => $where
                ->where('class', 'like', $like)
                ->orWhere('message', 'like', $like)
                ->orWhere('fingerprint', $query))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('last_seen_at')
            ->limit(self::LIMIT)
            ->get(['fingerprint', 'class', 'message', 'status', 'kind', 'occurrences'])
            ->map(fn (object $issue) => [
                'kind' => 'Issue',
                'title' => in_array($issue->kind, ['exception', 'error'], true) ? class_basename((string) $issue->class) : (string) $issue->class,
                'detail' => trim($issue->status.' · '.number_format((int) $issue->occurrences).'× · '.Str::limit((string) $issue->message, 80), ' ·'),
                'url' => DashboardUrl::to(['issue' => $issue->fingerprint], 'errors'),
            ])
            ->all();
    }

    /**
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    protected function traces(string $query): array
    {
        $byId = preg_match('/^[0-9a-f-]{8,36}$/i', $query) === 1;

        return $this->connection()->table('pulse_boosted_traces')
            ->when($byId,
                fn ($where) => $where->where('trace_id', 'like', strtolower($query).'%'),
                fn ($where) => $where->where('name', 'like', $this->like($query)))
            ->orderByDesc('id')
            ->limit($byId ? self::LIMIT : 50)
            ->get(['trace_id', 'type', 'name', 'status', 'duration_ms'])
            // By name, the latest of each is enough; the Routes and Traces
            // cards have the rest.
            ->unique(fn (object $trace) => $byId ? $trace->trace_id : $trace->type.'|'.$trace->name)
            ->take(self::LIMIT)
            ->map(fn (object $trace) => [
                'kind' => 'Trace',
                'title' => (string) $trace->name,
                'detail' => Str::headline($trace->type).' · '.$trace->status.' · '.number_format((int) $trace->duration_ms).'ms'.($byId ? ' · '.$trace->trace_id : ' · latest'),
                'url' => DashboardUrl::to(['trace' => $trace->trace_id]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    protected function jobs(string $query): array
    {
        // Wherever the job history is kept, database or Redis.
        return app(JobRepository::class)
            ->jobs(['search' => $query], self::LIMIT)
            ->map(fn (object $job) => [
                'kind' => 'Job',
                'title' => (string) $job->name,
                'detail' => $job->status.' · '.$job->queue.' · '.$job->uuid,
                'url' => DashboardUrl::to(['job' => $job->uuid], 'queues'),
            ])
            ->all();
    }

    /**
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    protected function routes(string $query): array
    {
        return $this->connection()->table('pulse_boosted_traces')
            ->where('type', 'request')
            ->where('name', 'like', $this->like($query))
            ->groupBy('name')
            ->selectRaw('name, count(*) as aggregate')
            ->orderByDesc('aggregate')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (object $route) => [
                'kind' => 'Route',
                'title' => (string) $route->name,
                'detail' => number_format((int) $route->aggregate).' requests kept',
                'url' => DashboardUrl::to(['route' => $route->name], 'performance'),
            ])
            ->all();
    }

    /**
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    protected function tasks(string $query): array
    {
        return $this->connection()->table('pulse_boosted_scheduled_tasks')
            ->where('name', 'like', $this->like($query))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['name', 'expression'])
            ->map(fn (object $task) => [
                'kind' => 'Scheduled task',
                'title' => (string) $task->name,
                'detail' => (string) $task->expression,
                'url' => DashboardUrl::to([], 'traces'),
            ])
            ->all();
    }

    /**
     * Users are found among those the dashboard has seen, by name, email or
     * id: the application's user table is not the dashboard's to scan.
     *
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    protected function users(string $query): array
    {
        $ids = $this->connection()->table('pulse_boosted_traces')
            ->whereNotNull('user_id')
            ->orderByDesc('id')
            ->limit(5000)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->take(500);

        $needle = mb_strtolower($query);

        return collect($this->people->resolve($ids))
            ->filter(fn (object $person) => $person->id === $query
                || str_contains(mb_strtolower($person->name), $needle)
                || ($person->email !== null && str_contains(mb_strtolower($person->email), $needle)))
            ->take(self::LIMIT)
            ->map(fn (object $person) => [
                'kind' => 'User',
                'title' => $person->name,
                'detail' => $person->email ?? 'id '.$person->id,
                'url' => DashboardUrl::to(['person' => $person->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * A kind that cannot be searched — a table not migrated yet — is left
     * out rather than failing the rest.
     *
     * @param  callable(): array<int, array{kind: string, title: string, detail: ?string, url: string}>  $search
     * @return array<int, array{kind: string, title: string, detail: ?string, url: string}>
     */
    protected function safely(callable $search): array
    {
        try {
            return $search();
        } catch (Throwable) {
            return [];
        }
    }

    protected function like(string $query): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query).'%';
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
