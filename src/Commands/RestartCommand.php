<?php

namespace Elazaroo\PulseBoosted\Commands;

use Elazaroo\PulseBoosted\Support\CacheStoreResolver;
use Illuminate\Console\Command;
use Illuminate\Support\InteractsWithTime;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'pulse-boosted:restart')]
class RestartCommand extends Command
{
    use InteractsWithTime;

    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'pulse-boosted:restart';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Restart any running "work" and "check" commands';

    /**
     * Handle the command.
     */
    public function handle(CacheStoreResolver $cache): void
    {
        $cache->store()->forever('elazaroo:pulse-boosted:restart', $this->currentTime());

        $this->components->info('Broadcasting Pulse Boosted restart signal.');
    }
}
