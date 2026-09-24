<?php

namespace Elazaroo\PulseBoosted\Deployments;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Which version of the application is running, and when each one arrived.
 *
 * @internal
 */
class Deployments
{
    /**
     * Whether this process has already noted the current version.
     */
    protected bool $noted = false;

    /**
     * Create a new instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected DatabaseManager $db,
        protected Repository $config,
    ) {
        //
    }

    /**
     * The version running now, if one is configured.
     */
    public function current(): ?string
    {
        $version = $this->config->get('pulse-boosted.deployment');

        return is_scalar($version) && (string) $version !== '' ? Str::limit((string) $version, 191, '') : null;
    }

    /**
     * Record that a version is running, the first time it is seen.
     *
     * Called as data is written, once per process: the first process to run
     * a new version is, near enough, when it was deployed.
     */
    public function note(?string $version = null, ?int $at = null): void
    {
        $version ??= $this->current();

        if ($version === null || ($this->noted && $at === null)) {
            return;
        }

        $this->noted = true;

        try {
            // Checked, then inserted, rather than insertOrIgnore, which SQL
            // Server does not have. Two servers noting the same version at
            // once is the one way the insert fails, and caught below.
            $this->pulse->ignore(function () use ($version, $at) {
                if (! $this->table()->where('version', $version)->exists()) {
                    $this->table()->insert([
                        'version' => $version,
                        'deployed_at' => $at ?? CarbonImmutable::now()->getTimestamp(),
                    ]);
                }
            });
        } catch (Throwable) {
            // The table may not exist yet, mid-deploy, before migrations run,
            // or another server noted it first.
        }
    }

    /**
     * The most recent deployments, newest first.
     *
     * @return Collection<int, stdClass>
     */
    public function recent(int $limit = 10): Collection
    {
        try {
            return $this->pulse->ignore(fn () => $this->table()->orderByDesc('deployed_at')->orderByDesc('id')->limit($limit)->get());
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * The most recent deployment, if any has been recorded.
     */
    public function latest(): ?stdClass
    {
        return $this->recent(1)->first();
    }

    /**
     * Drop everything.
     */
    public function purge(): void
    {
        $this->pulse->ignore(fn () => $this->table()->delete());
    }

    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_deployments');
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
