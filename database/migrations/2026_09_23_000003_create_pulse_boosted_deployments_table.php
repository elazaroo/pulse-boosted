<?php

use Elazaroo\PulseBoosted\Support\PulseMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends PulseMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->shouldRun()) {
            return;
        }

        // When each version first reported in, so "did this start with the
        // last deploy?" has an answer.
        Schema::create('pulse_boosted_deployments', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->unsignedInteger('deployed_at');

            $table->unique('version');
            $table->index('deployed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_deployments');
    }
};
