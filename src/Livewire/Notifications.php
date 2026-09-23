<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\TraceEvent;
use Livewire\Attributes\Lazy;

/**
 * Notifications the application delivered, and through which channel.
 *
 * @internal
 */
#[Lazy]
class Notifications extends TraceEventCard
{
    protected function eventType(): string
    {
        return TraceEvent::NOTIFICATION;
    }

    protected function title(): string
    {
        return 'Notifications';
    }
}
