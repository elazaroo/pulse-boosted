<?php

/**
 * @js and @json are not compiled inside a component tag's attributes, so
 * one written there reaches the browser as text and breaks the script it is
 * in. Checked on the source, since a card only shows it once it has data.
 */
it('never writes a Blade directive inside a component tag', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../resources/views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/<x-[\w:.-]+\b[^>]*?@(js|json)\(/s', file_get_contents($file->getPathname()), $matches);

        if ($matches[0] !== []) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
