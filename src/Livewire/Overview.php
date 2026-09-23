<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Alerts\AlertManager;
use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Elazaroo\PulseBoosted\Alerts\Metrics;
use Elazaroo\PulseBoosted\Deployments\Deployments;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Throwable;

/**
 * The handful of numbers that say whether anything needs looking at.
 *
 * Each figure is read the same way an alert rule would read it, over the
 * selected period, so a number here and a rule set from it mean the same
 * thing. Each one links to the section that explains it.
 *
 * @internal
 */
#[Lazy(isolate: false)]
class Overview extends Card
{
    /**
     * A skeleton the same height as the row of figures, so the page does not
     * jump when it arrives.
     */
    public function placeholder(): Renderable
    {
        return View::make('pulse-boosted::livewire.overview', ['stats' => null, 'deployment' => null, 'newSinceDeploy' => null]);
    }

    /**
     * Render the component.
     */
    public function render(Metrics $metrics, IssueRepository $issues, AlertManager $alerts, Deployments $deployments): Renderable
    {
        $window = $this->periodAsInterval()->totalSeconds.' seconds';

        $read = function (string $metric, array $options = []) use ($metrics, $window): ?float {
            try {
                return $metrics->read(new AlertRule(
                    name: $metric,
                    metric: $metric,
                    threshold: 0,
                    window: $window,
                    options: $options,
                ));
            } catch (Throwable) {
                // A missing table or an unreachable queue is a dash on this
                // card, not a broken dashboard.
                return null;
            }
        };

        $deployment = $deployments->latest();

        return View::make('pulse-boosted::livewire.overview', [
            'deployment' => $deployment,
            'newSinceDeploy' => $deployment === null ? null : $this->newSince($issues, (int) $deployment->deployed_at),
            'stats' => [
                [
                    'label' => 'Error rate',
                    'value' => $read('error_rate', ['type' => 'request']),
                    'format' => 'percent',
                    'hint' => 'Of traced requests',
                    'href' => '#traces',
                    'bad' => fn (float $v) => $v >= 5,
                ],
                [
                    'label' => 'p95 request',
                    'value' => $read('p95_duration', ['type' => 'request']),
                    'format' => 'ms',
                    'hint' => 'Slowest 5% exceeded',
                    'href' => '#performance',
                    'bad' => fn (float $v) => $v >= 1000,
                ],
                [
                    'label' => 'Exceptions',
                    'value' => $read('exceptions'),
                    'format' => 'count',
                    'hint' => 'Every one, never sampled',
                    'href' => '#errors',
                    'bad' => fn (float $v) => $v > 0,
                ],
                [
                    'label' => 'Open issues',
                    'value' => $this->openIssues($issues),
                    'format' => 'count',
                    'hint' => 'Distinct bugs',
                    'href' => '#errors',
                    'bad' => fn (float $v) => $v > 0,
                ],
                [
                    'label' => 'Failed jobs',
                    'value' => $read('failed_jobs'),
                    'format' => 'count',
                    'hint' => 'In this period',
                    'href' => '#queues',
                    'bad' => fn (float $v) => $v > 0,
                ],
                [
                    'label' => 'Queue backlog',
                    'value' => $read('queue_size'),
                    'format' => 'count',
                    'hint' => 'Waiting right now',
                    'href' => '#queues',
                    'bad' => fn (float $v) => $v >= 1000,
                ],
                [
                    'label' => 'Alerts',
                    'value' => $this->activeAlerts($alerts),
                    'format' => 'count',
                    'hint' => 'Breaching now',
                    'href' => '#overview',
                    'bad' => fn (float $v) => $v > 0,
                ],
            ],
        ]);
    }

    /**
     * Issues first seen since a moment — since the last deploy, here.
     */
    protected function newSince(IssueRepository $issues, int $since): ?int
    {
        try {
            return $issues->countFirstSeenSince($since);
        } catch (Throwable) {
            return null;
        }
    }

    protected function openIssues(IssueRepository $issues): ?float
    {
        try {
            return $issues->enabled() ? (float) ($issues->countsByStatus()['open'] ?? 0) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function activeAlerts(AlertManager $alerts): ?float
    {
        try {
            return $alerts->enabled() ? (float) $alerts->active()->count() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
