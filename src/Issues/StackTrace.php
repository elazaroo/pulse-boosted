<?php

namespace Elazaroo\PulseBoosted\Issues;

use Elazaroo\PulseBoosted\Support\Location;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\View\ViewException;
use SplFileObject;
use Throwable;

/**
 * An exception's stack, with the source around the frames that matter.
 *
 * A class and a line number say where it broke; the lines around it say why,
 * without opening an editor. Source is read only for the application's own
 * files — the framework's is the same everywhere and not what you came for —
 * and only for the first few of them, so a deep stack stays small.
 *
 * @internal
 */
class StackTrace
{
    /**
     * Lines either side of the one that threw.
     */
    public const CONTEXT = 5;

    /**
     * Application frames that get source, counted from the top.
     */
    public const SOURCE_FRAMES = 10;

    /**
     * Frames kept at all.
     */
    public const MAX_FRAMES = 60;

    /**
     * The exception that is actually the problem.
     *
     * An exception thrown while rendering a view arrives wrapped in a
     * ViewException, which says only that a view failed. The one inside says
     * what went wrong, and is what two occurrences should be grouped by.
     */
    public static function unwrap(Throwable $e): Throwable
    {
        while ($e instanceof ViewException && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }

    /**
     * The frames, top first.
     *
     * @return list<array{file: ?string, line: ?int, call: string, app: bool, code: array<int, string>|null}>
     */
    public static function frames(Throwable $e): array
    {
        $frames = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'call' => '',
        ]];

        foreach ($e->getTrace() as $i => $frame) {
            // PHP errors turned into ErrorException carry two frames of the
            // framework's own conversion first, which only get in the way.
            if ($i < 2 && ($frame['class'] ?? null) === HandleExceptions::class) {
                continue;
            }

            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'call' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function']).'()',
            ];

            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        $withSource = 0;

        return array_map(function (array $frame) use (&$withSource) {
            $app = $frame['file'] !== null && self::isApplicationFile($frame['file']);

            $code = null;

            if ($app && $frame['line'] !== null && $withSource < self::SOURCE_FRAMES) {
                $code = self::source($frame['file'], $frame['line']);

                if ($code !== null) {
                    $withSource++;
                }
            }

            return [
                'file' => $frame['file'] === null ? null : Location::relative($frame['file']),
                'line' => $frame['line'],
                'call' => $frame['call'],
                'app' => $app,
                'code' => $code,
            ];
        }, $frames);
    }

    /**
     * The frames as JSON, ready to store.
     */
    public static function serialize(Throwable $e): ?string
    {
        try {
            return json_encode(self::frames($e), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a file is the application's rather than a dependency's.
     */
    public static function isApplicationFile(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        // Anything outside vendor/ is the application's, which also covers a
        // package developed alongside its app rather than installed into it.
        return ! str_contains($file, '/vendor/')
            && ! str_contains($file, '/storage/framework/views/');
    }

    /**
     * A few lines either side of one, keyed by line number.
     *
     * @return array<int, string>|null
     */
    protected static function source(string $file, int $line): ?array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        try {
            $handle = new SplFileObject($file);
            $handle->seek(max(0, $line - 1 - self::CONTEXT));

            $code = [];

            while (! $handle->eof() && $handle->key() <= $line - 1 + self::CONTEXT) {
                $text = $handle->current();

                $code[$handle->key() + 1] = mb_strimwidth(rtrim(is_string($text) ? $text : '', "\r\n"), 0, 300, '…');
                $handle->next();
            }

            return $code === [] ? null : $code;
        } catch (Throwable) {
            return null;
        }
    }
}
