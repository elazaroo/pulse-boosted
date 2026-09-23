<?php

namespace Elazaroo\PulseBoosted\Logging;

use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Support\Location;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Log lines and exceptions as one stream, newest first.
 *
 * They are the same question — what went wrong, and where — asked of two
 * places. Log lines come from traces, so they follow the trace sample rate.
 * Exceptions come from issue occurrences, which are never sampled, so every
 * one is here even when the request it happened in was not traced.
 *
 * @phpstan-type Entry object{
 *     key: string,
 *     kind: 'log'|'exception',
 *     level: ?string,
 *     message: string,
 *     class: ?string,
 *     location: ?string,
 *     context: ?string,
 *     isError: bool,
 *     handled: ?bool,
 *     traceId: ?string,
 *     execution: ?string,
 *     at: int
 * }
 *
 * @internal
 */
class LogStream
{
    /**
     * The filter that selects exceptions rather than a log level.
     */
    public const EXCEPTIONS = 'exception';

    /**
     * Create a new stream.
     */
    public function __construct(
        protected Pulse $pulse,
        protected DatabaseManager $db,
        protected Repository $config,
    ) {
        //
    }

    /**
     * One page of the stream.
     *
     * Each source is read up to the end of the page and the two are merged in
     * PHP, because they live in different tables with different notions of
     * time. Pages are small, so reading a little more than needed is cheaper
     * than a union every database would spell differently.
     *
     * @return Collection<int, stdClass>
     */
    public function entries(string $level = '', string $search = '', int $limit = 20, int $offset = 0, string $user = ''): Collection
    {
        $wanted = $offset + $limit;

        $logs = $level === self::EXCEPTIONS ? collect() : $this->logs($level, $search, $wanted, $user);
        $exceptions = $level === '' || $level === self::EXCEPTIONS ? $this->exceptions($search, $wanted, $user) : collect();

        return $logs
            ->concat($exceptions)
            ->sortByDesc(fn (stdClass $entry) => [$entry->at, $entry->kind === 'exception' ? 1 : 0])
            ->slice($offset, $limit)
            ->values();
    }

    /**
     * How many entries sit under each filter.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->pulse->ignore(function () {
            $levels = $this->connection()
                ->table('pulse_boosted_trace_events')
                ->where('type', 'log')
                ->whereNotNull('level')
                ->groupBy('level')
                ->selectRaw('level, count(*) as aggregate')
                ->pluck('aggregate', 'level')
                ->map(fn ($count) => (int) $count)
                ->all();

            return [
                self::EXCEPTIONS => (int) $this->connection()->table('pulse_boosted_issue_occurrences')->count(),
                ...$levels,
            ];
        });
    }

    /**
     * Log lines, most recent first.
     *
     * @return Collection<int, stdClass>
     */
    protected function logs(string $level, string $search, int $limit, string $user = ''): Collection
    {
        return $this->pulse->ignore(fn () => $this->connection()
            ->table('pulse_boosted_trace_events as e')
            ->join('pulse_boosted_traces as t', 't.trace_id', '=', 'e.trace_id')
            ->where('e.type', 'log')
            ->when($level !== '', fn (Builder $query) => $query->where('e.level', $level))
            ->when($search !== '', fn (Builder $query) => $query->where('e.label', 'like', $this->like($search)))
            ->when($user !== '', fn (Builder $query) => $query->where('t.user_id', $user))
            ->orderByDesc('e.id')
            ->limit($limit)
            ->get(['e.id', 'e.trace_id', 'e.label', 'e.level', 'e.meta', 'e.offset_ms', 't.name as execution', 't.started_at'])
            ->map(function (object $row) {
                $meta = json_decode($row->meta ?? '', true);

                $context = is_array($meta) ? ($meta['context'] ?? null) : null;

                return (object) [
                    'key' => 'log-'.$row->id,
                    'kind' => 'log',
                    'level' => $row->level,
                    'message' => (string) $row->label,
                    'class' => null,
                    'location' => null,
                    'context' => $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : null,
                    'isError' => false,
                    'handled' => null,
                    'traceId' => $row->trace_id,
                    'execution' => $row->execution,
                    'at' => (int) $row->started_at + intdiv((int) $row->offset_ms, 1000),
                ];
            }));
    }

    /**
     * Exceptions, most recent first.
     *
     * @return Collection<int, stdClass>
     */
    protected function exceptions(string $search, int $limit, string $user = ''): Collection
    {
        return $this->pulse->ignore(fn () => $this->connection()
            ->table('pulse_boosted_issue_occurrences as o')
            ->join('pulse_boosted_issues as i', 'i.fingerprint', '=', 'o.fingerprint')
            // Traces are kept for a day and occurrences for a month, so an
            // older exception may have no timeline left to open.
            ->leftJoin('pulse_boosted_traces as t', 't.trace_id', '=', 'o.trace_id')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('i.class', 'like', $this->like($search))
                ->orWhere('i.message', 'like', $this->like($search))))
            ->when($user !== '', fn (Builder $query) => $query->where('o.user_id', $user))
            ->orderByDesc('o.id')
            ->limit($limit)
            ->get(['o.id', 'o.occurred_at', 'o.handled', 'i.class', 'i.kind', 'i.message', 'i.file', 'i.line', 't.trace_id', 't.name as execution'])
            ->map(fn (object $row) => (object) [
                'key' => 'exception-'.$row->id,
                'kind' => 'exception',
                'level' => null,
                'message' => (string) ($row->message ?? ''),
                'class' => $row->class,
                'location' => Location::relative($row->file, $row->line),
                'context' => null,
                'isError' => $row->kind === 'error',
                'handled' => (bool) $row->handled,
                'traceId' => $row->trace_id,
                'execution' => $row->execution,
                'at' => (int) $row->occurred_at,
            ]));
    }

    /**
     * A LIKE pattern that matches the search literally.
     */
    protected function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
