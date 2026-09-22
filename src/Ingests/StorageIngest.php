<?php

namespace Elazaroo\PulseBoosted\Ingests;

use Elazaroo\PulseBoosted\Contracts\Ingest;
use Elazaroo\PulseBoosted\Contracts\Storage;
use Elazaroo\PulseBoosted\Entry;
use Illuminate\Support\Collection;

/**
 * @internal
 */
class StorageIngest implements Ingest
{
    /**
     * Create a new Storage Ingest instance.
     */
    public function __construct(protected Storage $storage)
    {
        //
    }

    /**
     * Ingest the items.
     *
     * @param  Collection<int, Entry>  $items
     */
    public function ingest(Collection $items): void
    {
        $this->storage->store($items);
    }

    /**
     * Trim the ingest.
     */
    public function trim(): void
    {
        $this->storage->trim();
    }

    /**
     * Digest the ingested items.
     */
    public function digest(Storage $storage): int
    {
        return 0;
    }
}
