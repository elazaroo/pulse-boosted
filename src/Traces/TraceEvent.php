<?php

namespace Elazaroo\PulseBoosted\Traces;

/**
 * Something that happened inside an execution context.
 *
 * @internal
 */
class TraceEvent
{
    /**
     * The kinds of thing a trace can hold.
     */
    public const QUERY = 'query';

    public const CACHE = 'cache';

    public const JOB = 'job';

    public const HTTP = 'http';

    public const EXCEPTION = 'exception';

    public const LOG = 'log';

    public const MAIL = 'mail';

    public const NOTIFICATION = 'notification';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly float $offsetMs,
        public readonly ?float $durationMs = null,
        public readonly ?string $level = null,
        public readonly array $meta = [],
    ) {
        //
    }

    /**
     * The row to persist.
     *
     * @return array<string, mixed>
     */
    public function attributes(string $traceId): array
    {
        return [
            'trace_id' => $traceId,
            'type' => $this->type,
            'label' => $this->label,
            'offset_ms' => (int) round($this->offsetMs),
            'duration_ms' => $this->durationMs === null ? null : (int) round($this->durationMs),
            'level' => $this->level,
            'meta' => $this->meta === [] ? null : json_encode($this->meta, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ];
    }
}
