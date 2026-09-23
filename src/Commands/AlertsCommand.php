<?php

namespace Elazaroo\PulseBoosted\Commands;

use Elazaroo\PulseBoosted\Alerts\AlertManager;
use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Check the rules now and say what each one reads.
 *
 * The rules run on their own on the isolated beat; this is for finding out
 * why a rule is or is not firing without waiting for the next one.
 *
 * @internal
 */
#[AsCommand(name: 'pulse-boosted:alerts')]
class AlertsCommand extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'pulse-boosted:alerts {--dry-run : Read the metrics without opening or closing anything}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Check the alert rules and show what each one reads';

    /**
     * Handle the command.
     */
    public function handle(AlertManager $alerts): int
    {
        if (! $alerts->enabled()) {
            $this->components->warn('Alerting is switched off in the configuration.');

            return self::SUCCESS;
        }

        $rules = $alerts->rules();

        if ($rules->isEmpty()) {
            $this->components->warn('No rules are configured. Add them under [alerts.rules].');

            return self::SUCCESS;
        }

        $readings = $this->option('dry-run')
            ? $rules->map(fn (AlertRule $rule) => [
                'rule' => $rule,
                'value' => $value = $alerts->read($rule),
                'breached' => $rule->breached($value),
                'changed' => false,
            ])
            : $alerts->evaluate();

        $this->table(
            ['Rule', 'Metric', 'Reading', 'Threshold', 'State'],
            $readings->map(fn (array $reading) => [
                $reading['rule']->name,
                $reading['rule']->metric,
                $reading['value'] === null ? '—' : (string) round($reading['value'], 2),
                ($reading['rule']->comparison === 'above' ? '> ' : '< ').round($reading['rule']->threshold, 2),
                $this->state($reading),
            ])->all(),
        );

        return $readings->contains('breached', true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * How a reading reads, in a word.
     *
     * @param  array<string, mixed>  $reading
     */
    protected function state(array $reading): string
    {
        return match (true) {
            $reading['value'] === null => '<fg=gray>unknown</>',
            $reading['breached'] => '<fg=red>breached</>',
            default => '<fg=green>ok</>',
        };
    }
}
