<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Search\GlobalSearch;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Component;

/**
 * The search box behind Ctrl+K.
 *
 * @internal
 */
class Search extends Component
{
    public string $query = '';

    public function render(GlobalSearch $search): Renderable
    {
        return View::make('pulse-boosted::livewire.search', [
            'results' => $search->search($this->query),
        ]);
    }
}
