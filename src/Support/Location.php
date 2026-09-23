<?php

namespace Elazaroo\PulseBoosted\Support;

/**
 * Where something was thrown, as a person would want to read it.
 *
 * @internal
 */
class Location
{
    /**
     * A file and line, relative to the application where possible, with
     * forward slashes so a path reads the same whichever platform threw it.
     */
    public static function relative(?string $file, int|string|null $line = null): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }

        $file = str_replace('\\', '/', $file);
        $base = function_exists('base_path') ? rtrim(str_replace('\\', '/', base_path()), '/').'/' : '';

        if ($base !== '/' && $base !== '' && str_starts_with($file, $base)) {
            $file = substr($file, strlen($base));
        }

        return $line === null ? $file : $file.':'.$line;
    }
}
