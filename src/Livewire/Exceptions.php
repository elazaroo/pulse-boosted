<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Recorders\Exceptions as ExceptionsRecorder;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * @phpstan-type ThrownRow object{class: string, location: string, latest: CarbonImmutable, count: int, isError: bool}
 *
 * @internal
 */
#[Lazy]
class Exceptions extends Card
{
    /**
     * Ordering.
     *
     * @var 'count'|'latest'
     */
    #[Url(as: 'exceptions')]
    public string $orderBy = 'count';

    /**
     * Which half of the Throwable hierarchy to show.
     *
     * PHP splits what can be thrown in two: an Exception is usually something
     * the application anticipated, an Error is usually a bug in the code. They
     * want looking at differently, so they can be separated here.
     *
     * @var 'all'|'exceptions'|'errors'
     */
    #[Url(as: 'kind')]
    public string $kind = 'all';

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$exceptions, $time, $runAt] = $this->remember(
            fn () => $this->aggregate(
                'exception',
                ['max', 'count'],
                match ($this->orderBy) {
                    'latest' => 'max',
                    default => 'count'
                },
            )->map(function ($row) {
                [$class, $location] = json_decode($row->key, flags: JSON_THROW_ON_ERROR);

                return (object) [
                    'class' => $class,
                    'location' => $location,
                    'latest' => CarbonImmutable::createFromTimestamp($row->max),
                    'count' => $row->count,
                    'isError' => $this->isError($class),
                ];
            }),
            $this->orderBy
        );

        return View::make('pulse-boosted::livewire.exceptions', [
            'time' => $time,
            'runAt' => $runAt,
            'exceptions' => $this->ofSelectedKind($exceptions),
            'kindCounts' => $this->kindCounts($exceptions),
            'config' => Config::get('pulse-boosted.recorders.'.ExceptionsRecorder::class),
        ]);
    }

    /**
     * Narrow the list to the selected kind.
     *
     * @param  Collection<int, ThrownRow>  $exceptions
     * @return Collection<int, ThrownRow>
     */
    protected function ofSelectedKind(Collection $exceptions): Collection
    {
        return match ($this->kind) {
            'errors' => $exceptions->filter(fn ($row) => $row->isError)->values(),
            'exceptions' => $exceptions->reject(fn ($row) => $row->isError)->values(),
            default => $exceptions,
        };
    }

    /**
     * How many of each kind there are, for the toggle.
     *
     * @param  Collection<int, ThrownRow>  $exceptions
     * @return array<string, int>
     */
    protected function kindCounts(Collection $exceptions): array
    {
        return [
            'all' => $exceptions->count(),
            'exceptions' => $exceptions->reject(fn ($row) => $row->isError)->count(),
            'errors' => $exceptions->filter(fn ($row) => $row->isError)->count(),
        ];
    }

    /**
     * Whether a thrown class is one of PHP's Errors rather than an Exception.
     *
     * A class the application no longer has cannot be placed, and is counted
     * as an exception rather than being hidden from both lists.
     */
    protected function isError(string $class): bool
    {
        return (class_exists($class) || interface_exists($class))
            && is_a($class, \Error::class, true);
    }
}
