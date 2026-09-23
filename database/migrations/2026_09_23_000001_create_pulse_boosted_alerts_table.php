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

        // One row per episode. A rule that trips writes a row; the row is
        // closed when the rule recovers. The open rows are what is wrong now,
        // and the closed ones are the history of what has been wrong before.
        Schema::create('pulse_boosted_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('rule');
            $table->string('metric', 32);

            $table->double('value');
            $table->double('threshold');
            $table->string('comparison', 8);

            $table->unsignedInteger('triggered_at');
            $table->unsignedInteger('resolved_at')->nullable();

            // The worst it got while the alert was open, which is usually the
            // thing worth knowing afterwards.
            $table->double('peak');
            $table->unsignedInteger('last_checked_at');

            $table->mediumText('meta')->nullable();

            $table->index(['rule', 'resolved_at']); // For finding the open episode...
            $table->index('triggered_at'); // For trimming and for the list...
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_alerts');
    }
};
