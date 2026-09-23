<?php

namespace Elazaroo\PulseBoosted\Issues;

use Elazaroo\PulseBoosted\Events\IsolatedBeat;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Recorders\Concerns\Throttling;
use Illuminate\Contracts\Config\Repository;

/**
 * Resolves issues nobody has seen for a while.
 *
 * An open issue that stopped happening a month ago was probably fixed by a
 * change nobody connected to it. Resolving it keeps the open list about what
 * is broken now — and if it was not fixed after all, it reopens itself the
 * next time it happens, and says so.
 *
 * @internal
 */
class AutoResolveIssues
{
    use Throttling;

    public function __construct(
        protected Repository $config,
        protected IssueRepository $issues,
        protected Pulse $pulse,
    ) {
        //
    }

    public function __invoke(IsolatedBeat $event): void
    {
        $after = $this->config->get('pulse-boosted.issues.auto_resolve_after');

        if (! is_string($after) || $after === '') {
            return;
        }

        $this->pulse->rescue(fn () => $this->throttle(3600, $event, fn () => $this->issues->resolveQuietFor($after)));
    }
}
