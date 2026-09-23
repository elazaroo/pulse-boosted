<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\TraceEvent;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Log lines, searchable, and each one linked to what the application was
 * doing when it wrote them.
 *
 * @internal
 */
#[Lazy]
class Logs extends TraceEventCard
{
    /**
     * Which level to show, or all of them.
     */
    #[Url(as: 'log_level')]
    public string $logLevel = '';

    protected function eventType(): string
    {
        return TraceEvent::LOG;
    }

    protected function title(): string
    {
        return 'Logs';
    }

    protected function level(): string
    {
        return $this->logLevel;
    }

    /**
     * @return array<string, int>
     */
    protected function levels(TraceRepository $traces): array
    {
        return $traces->countsByLevel();
    }
}
