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

        // Settings changed from the dashboard. Each row overrides the config
        // key it names; a key with no row keeps whatever config says.
        Schema::create('pulse_boosted_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();

            // JSON, or for a secret the encrypted JSON.
            $table->text('value');

            $table->unsignedInteger('updated_at');
            $table->string('updated_by')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_settings');
    }
};
