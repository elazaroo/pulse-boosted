<?php

namespace Elazaroo\PulseBoosted\Traces;

/**
 * The phases a request goes through, in order.
 *
 * A request that took two seconds is a question; one that spent 1.8 of them
 * in middleware is an answer. Every event on the timeline falls inside one of
 * these, so it is plain which part of the lifecycle it belongs to.
 *
 * @internal
 */
final class Stage
{
    public const BOOTSTRAP = 'bootstrap';

    public const MIDDLEWARE = 'middleware';

    public const CONTROLLER = 'controller';

    public const RENDER = 'render';

    public const AFTER_MIDDLEWARE = 'after_middleware';

    public const SENDING = 'sending';

    public const TERMINATING = 'terminating';

    /**
     * What to call each one on the timeline.
     */
    public static function label(string $stage): string
    {
        return match ($stage) {
            self::BOOTSTRAP => 'Bootstrap',
            self::MIDDLEWARE, self::AFTER_MIDDLEWARE => 'Middleware',
            self::CONTROLLER => 'Controller',
            self::RENDER => 'Render',
            self::SENDING => 'Sending',
            self::TERMINATING => 'Terminating',
            default => ucfirst(str_replace('_', ' ', $stage)),
        };
    }
}
