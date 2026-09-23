<?php

namespace Elazaroo\PulseBoosted\Commands;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Deployments\Deployments;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Mark a deployment.
 *
 * A version is noted on its own the first time it reports in. Running this
 * from a deploy script instead marks the moment of the deploy itself, before
 * any traffic has reached the new code.
 *
 * @internal
 */
#[AsCommand(name: 'pulse-boosted:deploy')]
class DeployCommand extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'pulse-boosted:deploy {version? : The version being deployed; defaults to pulse-boosted.deployment}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Mark a deployment, so issues can be told apart by the version they appeared in';

    public function handle(Deployments $deployments): int
    {
        $version = $this->argument('version') ?? $deployments->current();

        if (! is_string($version) || $version === '') {
            $this->components->error('No version given, and pulse-boosted.deployment is not set.');

            return self::FAILURE;
        }

        $deployments->note($version, CarbonImmutable::now()->getTimestamp());

        $this->components->info("Marked deployment [{$version}].");

        return self::SUCCESS;
    }
}
