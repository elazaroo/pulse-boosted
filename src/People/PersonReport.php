<?php

namespace Elazaroo\PulseBoosted\People;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Everything the dashboard knows about one user: what they did, what went
 * wrong for them, and when.
 *
 * "A customer says checkout is broken" starts here: their requests, the
 * errors they hit, and the trace of each, without searching for them.
 *
 * @internal
 */
class PersonReport
{
    public function __construct(
        protected DatabaseManager $db,
        protected Repository $config,
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * @return array{
     *     counts: array<string, int>,
     *     failed: int,
     *     firstSeen: ?int,
     *     lastSeen: ?int,
     *     timeline: list<array{start: int, count: int, errors: int}>,
     *     recent: Collection<int, stdClass>,
     *     issues: Collection<int, stdClass>,
     *     logs: Collection<int, stdClass>,
     *     routes: Collection<int, array{name: string, count: int, failed: int}>
     * }
     */
    public function for(string $userId, int $since, int $buckets = 24): array
    {
        return $this->pulse->ignore(function () use ($userId, $since, $buckets) {
            $traces = $this->connection()->table('pulse_boosted_traces')
                ->where('user_id', $userId)
                ->where('started_at', '>=', $since)
                ->orderByDesc('id')
                ->limit(5000)
                ->get(['trace_id', 'type', 'name', 'status', 'duration_ms', 'started_at', 'meta']);

            $recent = $traces->take(30)->values();

            $issues = $this->connection()->table('pulse_boosted_issue_occurrences as o')
                ->join('pulse_boosted_issues as i', 'i.fingerprint', '=', 'o.fingerprint')
                ->where('o.user_id', $userId)
                ->groupBy('i.fingerprint', 'i.class', 'i.message', 'i.kind', 'i.level', 'i.status')
                ->selectRaw('i.fingerprint, i.class, i.message, i.kind, i.level, i.status, count(*) as times, max(o.occurred_at) as last_at')
                ->orderByDesc('last_at')
                ->limit(20)
                ->get();

            $logs = $this->connection()->table('pulse_boosted_trace_events as e')
                ->join('pulse_boosted_traces as t', 't.trace_id', '=', 'e.trace_id')
                ->where('t.user_id', $userId)
                ->where('e.type', 'log')
                ->whereIn('e.level', ['emergency', 'alert', 'critical', 'error', 'warning'])
                ->orderByDesc('e.id')
                ->limit(15)
                ->get(['e.label', 'e.level', 'e.trace_id', 't.name as execution', 't.started_at']);

            $all = $this->connection()->table('pulse_boosted_traces')->where('user_id', $userId);

            return [
                'counts' => $traces->groupBy('type')->map(fn (Collection $group) => $group->count())->all(),
                'failed' => $traces->where('status', 'failed')->count(),
                'firstSeen' => ($first = (clone $all)->min('started_at')) === null ? null : (int) $first,
                'lastSeen' => ($last = (clone $all)->max('started_at')) === null ? null : (int) $last,
                'timeline' => $this->timeline($traces, $since, $buckets),
                'recent' => $recent,
                'issues' => $issues,
                'logs' => $logs,
                'routes' => $traces->where('type', 'request')
                    ->groupBy('name')
                    ->map(fn (Collection $group, string $name) => [
                        'name' => $name,
                        'count' => $group->count(),
                        'failed' => $group->where('status', 'failed')->count(),
                    ])
                    ->sortByDesc('count')
                    ->take(8)
                    ->values(),
            ];
        });
    }

    /**
     * @param  Collection<int, stdClass>  $traces
     * @return list<array{start: int, count: int, errors: int}>
     */
    protected function timeline(Collection $traces, int $since, int $buckets): array
    {
        $width = max(1, (int) ceil((CarbonImmutable::now()->getTimestamp() - $since) / $buckets));
        $grouped = $traces->groupBy(fn (stdClass $trace) => min($buckets - 1, max(0, intdiv((int) $trace->started_at - $since, $width))));

        $timeline = [];

        for ($i = 0; $i < $buckets; $i++) {
            $group = $grouped->get($i, collect());

            $timeline[] = [
                'start' => $since + $i * $width,
                'count' => $group->count(),
                'errors' => $group->where('status', 'failed')->count(),
            ];
        }

        return $timeline;
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
