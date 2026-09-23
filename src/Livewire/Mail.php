<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\TraceEvent;
use Livewire\Attributes\Lazy;

/**
 * Mail the application sent, and what it was doing at the time.
 *
 * @internal
 */
#[Lazy]
class Mail extends TraceEventCard
{
    protected function eventType(): string
    {
        return TraceEvent::MAIL;
    }

    protected function title(): string
    {
        return 'Mail';
    }
}
