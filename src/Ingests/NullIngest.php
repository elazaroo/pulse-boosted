<?php

namespace Elazaroo\PulseBoosted\Ingests;

use Elazaroo\PulseBoosted\Contracts\Ingest;
use Elazaroo\PulseBoosted\Contracts\Storage;
use Elazaroo\PulseBoosted\Entry;
use Illuminate\Support\Collection;

class NullIngest implements Ingest
{
    /**
     * Ingest the items.
     *
     * @param  Collection<int, Entry>  $items
     */
    public function ingest(Collection $items): void
    {
        //
    }

    /**
     * Digest the ingested items.
     */
    public function digest(Storage $storage): int
    {
        return 0;
    }

    /**
     * Trim the ingest.
     */
    public function trim(): void
    {
        //
    }
}
