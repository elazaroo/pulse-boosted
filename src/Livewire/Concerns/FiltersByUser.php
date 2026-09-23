<?php

namespace Elazaroo\PulseBoosted\Livewire\Concerns;

use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Narrow a card to one user, in step with the filter in the header.
 *
 * Every card that uses this reads the same ?user= parameter, and hears when
 * the header changes it, so picking a user narrows the whole page at once.
 */
trait FiltersByUser
{
    #[Url(as: 'user')]
    public string $user = '';

    /**
     * The header's user filter changed.
     */
    #[On('user-filter')]
    public function filterByUser(string $user): void
    {
        $this->user = $user;

        if (property_exists($this, 'page')) {
            $this->page = 1;
        }
    }
}
