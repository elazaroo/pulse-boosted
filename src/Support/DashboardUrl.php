<?php

namespace Elazaroo\PulseBoosted\Support;

use Illuminate\Support\Facades\Route;

/**
 * A link into the dashboard, with something already open.
 *
 * @internal
 */
class DashboardUrl
{
    /**
     * @param  array<string, string>  $query  What to open: issue, job, trace, route, query, person.
     * @param  string|null  $section  The section to land on, by its id.
     */
    public static function to(array $query = [], ?string $section = null): string
    {
        $base = Route::has('pulse-boosted')
            ? route('pulse-boosted')
            : url((string) config('pulse-boosted.path', 'pulse-boosted'));

        return $base
            .($query === [] ? '' : '?'.http_build_query($query))
            .($section === null ? '' : '#'.$section);
    }
}
