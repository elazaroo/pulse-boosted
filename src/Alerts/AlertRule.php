<?php

namespace Elazaroo\PulseBoosted\Alerts;

use Carbon\CarbonInterval;
use InvalidArgumentException;

/**
 * One thing worth being told about, as configured.
 *
 * A rule is a metric, a threshold, and a window: "more than 50 exceptions in
 * five minutes", "the default queue over a thousand deep". It holds no state —
 * whether it is currently tripped lives in the alerts table.
 */
class AlertRule
{
    /**
     * @param  array<string, mixed>  $options  Whatever the metric needs: a queue name, an execution type.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $metric,
        public readonly float $threshold,
        public readonly string $comparison = 'above',
        public readonly string $window = '5 minutes',
        public readonly array $options = [],
        public readonly ?string $description = null,
    ) {
        if (! in_array($comparison, ['above', 'below'], true)) {
            throw new InvalidArgumentException("Alert [{$name}] has an unknown comparison [{$comparison}]. Use 'above' or 'below'.");
        }
    }

    /**
     * Build a rule from a line of configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        foreach (['name', 'metric', 'threshold'] as $required) {
            if (! isset($config[$required])) {
                throw new InvalidArgumentException("An alert rule is missing its [{$required}].");
            }
        }

        return new self(
            name: (string) $config['name'],
            metric: (string) $config['metric'],
            threshold: (float) $config['threshold'],
            comparison: (string) ($config['comparison'] ?? 'above'),
            window: (string) ($config['window'] ?? '5 minutes'),
            options: (array) ($config['options'] ?? []),
            description: isset($config['description']) ? (string) $config['description'] : null,
        );
    }

    /**
     * Whether a reading breaches this rule.
     *
     * A metric that cannot be read returns null — a driver that cannot count
     * its queue, say — and a rule never trips on an unknown, because "we do
     * not know" is not the same as "it is bad".
     */
    public function breached(?float $value): bool
    {
        if ($value === null) {
            return false;
        }

        return $this->comparison === 'above'
            ? $value > $this->threshold
            : $value < $this->threshold;
    }

    /**
     * Of two readings, the one further into breach.
     */
    public function worse(float $a, float $b): float
    {
        return $this->comparison === 'above' ? max($a, $b) : min($a, $b);
    }

    /**
     * How far back the metric should look.
     */
    public function interval(): CarbonInterval
    {
        return CarbonInterval::fromString($this->window);
    }

    /**
     * An option the metric needs.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
