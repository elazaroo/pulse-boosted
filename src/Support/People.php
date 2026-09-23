<?php

namespace Elazaroo\PulseBoosted\Support;

use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Names, email addresses and avatars for user ids, through whatever user
 * resolver the application configured.
 *
 * A user who cannot be found, or a resolver that throws, still gets a row, so
 * nothing on the dashboard breaks over somebody who has since been deleted.
 *
 * @internal
 */
class People
{
    public function __construct(protected Pulse $pulse)
    {
        //
    }

    /**
     * @param  iterable<int, string|int|null>  $ids
     * @return array<string, object{id: string, name: string, email: string|null, avatar: string|null}>
     */
    public function resolve(iterable $ids): array
    {
        $ids = Collection::make($ids)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        try {
            $resolved = $this->pulse->ignore(fn () => $this->pulse->resolveUsers($ids));
        } catch (Throwable) {
            $resolved = null;
        }

        return $ids->mapWithKeys(function (string $id) use ($resolved) {
            try {
                $user = $resolved?->find($id);
            } catch (Throwable) {
                $user = null;
            }

            $email = $user->extra ?? null;

            return [$id => (object) [
                'id' => $id,
                'name' => (string) ($user->name ?? "User {$id}"),
                'email' => is_string($email) && str_contains($email, '@') ? $email : null,
                'avatar' => $user->avatar ?? null,
            ]];
        })->all();
    }

    /**
     * @return object{id: string, name: string, email: string|null, avatar: string|null}|null
     */
    public function one(string|int|null $id): ?object
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->resolve([$id])[(string) $id] ?? null;
    }
}
