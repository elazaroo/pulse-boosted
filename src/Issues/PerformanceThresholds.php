<?php

namespace Elazaroo\PulseBoosted\Issues;

use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Traces\Trace;
use Illuminate\Contracts\Config\Repository;

/**
 * Turns executions that ran over their threshold into issues.
 *
 * A slow route is a bug like any other: it should be listed, counted, marked
 * resolved when fixed, and reopen itself if it comes back — and whoever is
 * told about new exceptions should be told about it too.
 *
 * @internal
 */
class PerformanceThresholds
{
    public function __construct(
        protected Repository $config,
        protected IssueRepository $issues,
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Check a finished execution against its threshold.
     */
    public function __invoke(Trace $trace): void
    {
        $duration = $trace->durationMs();

        if ($duration === null || ($threshold = $this->threshold($trace->type, $trace->name)) === null) {
            return;
        }

        if ($duration <= $threshold) {
            return;
        }

        // The trace is the evidence, so it is written whatever the draw said.
        $trace->keep('slow');

        $this->issues->recordSlow(
            type: $trace->type,
            name: $trace->name,
            durationMs: (int) round($duration),
            thresholdMs: $threshold,
            traceId: $trace->id,
            userId: $this->pulse->resolveAuthenticatedUserId(),
        );
    }

    /**
     * The threshold for an execution, in milliseconds, if one applies.
     *
     * Checked in order: the exact name, then patterns, then '*'.
     */
    public function threshold(string $type, string $name): ?int
    {
        $rules = $this->config->get("pulse-boosted.issues.thresholds.{$type}", []);

        if (! is_array($rules) || $rules === []) {
            return null;
        }

        if (isset($rules[$name]) && is_numeric($rules[$name])) {
            return (int) $rules[$name];
        }

        foreach ($rules as $pattern => $ms) {
            if (! is_numeric($ms) || $pattern === '*' || ! is_string($pattern)) {
                continue;
            }

            if ($this->isPattern($pattern) && @preg_match($pattern, $name) === 1) {
                return (int) $ms;
            }
        }

        return isset($rules['*']) && is_numeric($rules['*']) ? (int) $rules['*'] : null;
    }

    protected function isPattern(string $value): bool
    {
        return strlen($value) > 2 && $value[0] === $value[strlen($value) - 1] && in_array($value[0], ['#', '/', '~'], true);
    }
}
