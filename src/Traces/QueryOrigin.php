<?php

namespace Elazaroo\PulseBoosted\Traces;

use Elazaroo\PulseBoosted\Issues\StackTrace;
use Elazaroo\PulseBoosted\Support\Location;

/**
 * Where a query came from, and what makes two queries the same one.
 *
 * @internal
 */
class QueryOrigin
{
    /**
     * The first frame in the application's own code.
     *
     * Every query is run by the framework; the frame worth showing is the one
     * above it that asked for it — the controller, the job, the model scope.
     *
     * @param  array<int, array<string, mixed>>  $trace
     * @return array{0: string, 1: int}|null
     */
    public static function find(array $trace): ?array
    {
        $package = str_replace('\\', '/', dirname(__DIR__, 2)).'/';

        foreach ($trace as $frame) {
            if (! isset($frame['file'], $frame['line']) || ! is_string($frame['file'])) {
                continue;
            }

            $file = str_replace('\\', '/', $frame['file']);

            // Pulse Boosted's own frames, when it is being worked on in place
            // rather than installed under vendor/.
            if (str_starts_with($file, $package.'src/')) {
                continue;
            }

            if (StackTrace::isApplicationFile($file)) {
                return [(string) Location::relative($frame['file']), (int) $frame['line']];
            }
        }

        return null;
    }

    /**
     * The query with the parts that vary between calls folded away.
     *
     * "where id in (?, ?, ?)" and "where id in (?, ?)" are the same query run
     * with different lists, and an insert of five rows is the same statement
     * as an insert of fifty. Grouping them apart would hide how often they
     * really run.
     */
    public static function normalize(string $sql): string
    {
        $sql = preg_replace('/\bin\s*\(\s*\?(\s*,\s*\?)*\s*\)/i', 'in (...?)', $sql) ?? $sql;

        $sql = preg_replace('/\bvalues\s*\((?:[^()]|\([^()]*\))*\)(\s*,\s*\((?:[^()]|\([^()]*\))*\))+/i', 'values (...)', $sql) ?? $sql;

        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }
}
