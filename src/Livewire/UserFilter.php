<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Pick a user, and every card that can be narrowed to one is.
 *
 * @internal
 */
class UserFilter extends Component
{
    #[Url(as: 'user')]
    public string $user = '';

    /**
     * Tell the cards.
     */
    public function updatedUser(): void
    {
        $this->dispatch('user-filter', user: $this->user);
    }

    public function render(Pulse $pulse, DatabaseManager $db, Repository $config): Renderable
    {
        return View::make('pulse-boosted::livewire.user-filter', [
            'users' => $this->users($pulse, $db, $config),
        ]);
    }

    /**
     * The users seen recently, with names where the application can say them.
     *
     * @return array<string, string>
     */
    protected function users(Pulse $pulse, DatabaseManager $db, Repository $config): array
    {
        try {
            $connection = $db->connection($config->get('pulse-boosted.storage.database.connection'));

            $ids = $pulse->ignore(fn () => $connection->table('pulse_boosted_traces')
                ->whereNotNull('user_id')
                ->orderByDesc('id')
                ->limit(2000)
                ->pluck('user_id')
                ->merge($connection->table('pulse_boosted_issue_occurrences')
                    ->whereNotNull('user_id')
                    ->orderByDesc('id')
                    ->limit(500)
                    ->pluck('user_id'))
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->take(100)
                ->values());

            if ($this->user !== '' && ! $ids->contains($this->user)) {
                $ids->push($this->user);
            }

            $resolved = $pulse->resolveUsers($ids);

            return $ids
                ->mapWithKeys(fn (string $id) => [$id => rescue(fn () => $resolved->find($id)->name, "User {$id}", false)])
                ->sort()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
