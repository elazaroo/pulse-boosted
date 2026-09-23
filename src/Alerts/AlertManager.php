<?php

namespace Elazaroo\PulseBoosted\Alerts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Elazaroo\PulseBoosted\Events\AlertResolved;
use Elazaroo\PulseBoosted\Events\AlertTriggered;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Watches the rules and keeps track of which are currently tripped.
 *
 * A dashboard tells you something is wrong once you go and look at it. The
 * point of a rule is not to have to.
 *
 * Nothing here sends anything: where an alert should go is the application's
 * business. It writes the state and fires AlertTriggered and AlertResolved for
 * the application to listen to.
 *
 * @phpstan-type AlertRow object{
 *     id: int,
 *     rule: string,
 *     metric: string,
 *     value: float,
 *     threshold: float,
 *     comparison: string,
 *     triggered_at: int,
 *     resolved_at: ?int,
 *     peak: float,
 *     last_checked_at: int,
 *     meta: ?string
 * }
 *
 * @internal
 */
class AlertManager
{
    /**
     * Create a new manager.
     */
    public function __construct(
        protected Pulse $pulse,
        protected DatabaseManager $db,
        protected Repository $config,
        protected Metrics $metrics,
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Whether alerting is switched on.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('pulse-boosted.alerts.enabled', true);
    }

    /**
     * The rules as configured.
     *
     * A rule that cannot be read is skipped rather than fatal: one typo in the
     * configuration should not take the dashboard down with it.
     *
     * @return Collection<int, AlertRule>
     */
    public function rules(): Collection
    {
        $rules = [];

        foreach ((array) $this->config->get('pulse-boosted.alerts.rules', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            try {
                $rules[] = AlertRule::fromArray($rule);
            } catch (Throwable) {
                continue;
            }
        }

        return collect($rules);
    }

    /**
     * How often the rules are worth checking.
     */
    public function interval(): CarbonInterval
    {
        return CarbonInterval::fromString(
            (string) ($this->config->get('pulse-boosted.alerts.check_every') ?? '1 minute')
        );
    }

    /**
     * Check every rule, opening and closing episodes as they change.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function evaluate(): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        return $this->rules()->map(function (AlertRule $rule) {
            try {
                $value = $this->metrics->read($rule);
            } catch (Throwable $e) {
                // A metric that blows up is worth knowing about, but not at
                // the cost of every other rule in the list.
                $this->pulse->report($e);

                return ['rule' => $rule, 'value' => null, 'breached' => false, 'changed' => false];
            }

            $breached = $rule->breached($value);

            return [
                'rule' => $rule,
                'value' => $value,
                'breached' => $breached,
                'changed' => $this->apply($rule, $value, $breached),
            ];
        });
    }

    /**
     * Read one rule's metric without acting on it.
     */
    public function read(AlertRule $rule): ?float
    {
        return $this->metrics->read($rule);
    }

    /**
     * Open, update or close a rule's episode. Returns whether it changed state.
     */
    protected function apply(AlertRule $rule, ?float $value, bool $breached): bool
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $open = $this->pulse->ignore(fn () => $this->table()
            ->where('rule', $rule->name)
            ->whereNull('resolved_at')
            ->orderByDesc('id')
            ->first());

        if ($breached && $open === null) {
            $this->pulse->ignore(fn () => $this->table()->insert([
                'rule' => $rule->name,
                'metric' => $rule->metric,
                'value' => $value,
                'threshold' => $rule->threshold,
                'comparison' => $rule->comparison,
                'triggered_at' => $now,
                'resolved_at' => null,
                'peak' => $value,
                'last_checked_at' => $now,
                'meta' => $rule->options === [] ? null : json_encode($rule->options),
            ]));

            $this->events->dispatch(new AlertTriggered($rule, (float) $value, $rule->options));

            return true;
        }

        if ($breached) {
            $this->pulse->ignore(fn () => $this->table()->where('id', $open->id)->update([
                'value' => $value,
                'peak' => $rule->worse((float) $open->peak, (float) $value),
                'last_checked_at' => $now,
            ]));

            return false;
        }

        if ($open !== null) {
            $this->pulse->ignore(fn () => $this->table()->where('id', $open->id)->update([
                'value' => $value ?? $open->value,
                'resolved_at' => $now,
                'last_checked_at' => $now,
            ]));

            $this->events->dispatch(new AlertResolved(
                rule: $rule,
                value: (float) ($value ?? $open->value),
                peak: (float) $open->peak,
                durationSeconds: max(0, $now - (int) $open->triggered_at),
            ));

            return true;
        }

        return false;
    }

    /**
     * What is wrong now.
     *
     * Rows are of the AlertRow shape documented on this class.
     *
     * @return Collection<int, object>
     */
    public function active(): Collection
    {
        return $this->pulse->ignore(fn () => $this->table()
            ->whereNull('resolved_at')
            ->orderByDesc('triggered_at')
            ->get());
    }

    /**
     * What has been wrong before.
     *
     * @return Collection<int, object>
     */
    public function history(int $limit = 20): Collection
    {
        return $this->pulse->ignore(fn () => $this->table()
            ->whereNotNull('resolved_at')
            ->orderByDesc('resolved_at')
            ->limit($limit)
            ->get());
    }

    /**
     * Drop settled episodes past the configured retention. Open ones are kept
     * however old, because they are still happening.
     */
    public function trim(): void
    {
        $keep = $this->config->get('pulse-boosted.alerts.trim.keep') ?? '7 days';

        $before = CarbonImmutable::now()->sub(CarbonInterval::fromString($keep))->getTimestamp();

        $this->pulse->ignore(fn () => $this->table()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $before)
            ->delete());
    }

    /**
     * Drop everything.
     */
    public function purge(): void
    {
        $this->pulse->ignore(fn () => $this->table()->delete());
    }

    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_alerts');
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
