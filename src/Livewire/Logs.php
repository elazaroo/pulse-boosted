<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Logging\LogStream;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Log lines and exceptions in one stream, each linked to what the application
 * was doing when it happened.
 *
 * The grouped view of exceptions — which bugs, how often, dealt with or not —
 * is the Issues card. This is the other half: the individual entries, in the
 * order they happened.
 *
 * @internal
 */
#[Lazy]
class Logs extends Card
{
    public const PER_PAGE = 20;

    /**
     * A log level, 'exception', or everything.
     */
    #[Url(as: 'log_level')]
    public string $logLevel = '';

    #[Url(as: 'log_q')]
    public string $search = '';

    public int $page = 1;

    /**
     * Reset paging when the view changes underneath it.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    /**
     * Show the trace an entry came from.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    /**
     * Render the component.
     */
    public function render(LogStream $stream): Renderable
    {
        $counts = $stream->counts();

        return View::make('pulse-boosted::livewire.logs', [
            'entries' => $stream->entries($this->logLevel, trim($this->search), self::PER_PAGE, ($this->page - 1) * self::PER_PAGE),
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }
}
