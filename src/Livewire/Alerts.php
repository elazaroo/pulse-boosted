<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Alerts\AlertManager;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * What is currently breaching a rule, and what has breached one before.
 *
 * @internal
 */
#[Lazy(isolate: false)]
class Alerts extends Card
{
    #[Url(as: 'alert_tab')]
    public string $tab = 'active';

    /**
     * Render the component.
     */
    public function render(AlertManager $alerts): Renderable
    {
        $rules = $alerts->rules();

        $active = $alerts->active();

        return View::make('pulse-boosted::livewire.alerts', [
            'enabled' => $alerts->enabled(),
            'configured' => $rules->count(),
            'active' => $active,
            'history' => $this->tab === 'history' ? $alerts->history() : collect(),

            // Rules keyed by name, so a row can show the description the
            // configuration gave it rather than only a number.
            'descriptions' => $rules->mapWithKeys(fn ($rule) => [$rule->name => $rule->description])->all(),

            // Everything that is not currently breaching, which is the
            // reassuring half of the picture.
            'quiet' => $rules->reject(fn ($rule) => $active->contains('rule', $rule->name))->values(),
        ]);
    }
}
