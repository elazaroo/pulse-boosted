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

        // One row per attempt. The job's own row says how it ended; this says
        // how it got there — released twice with a timeout, then failed on a
        // different exception the third time.
        Schema::create('pulse_boosted_job_attempts', function (Blueprint $table) {
            $table->id();

            $table->char('uuid', 36);
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16);

            $table->unsignedInteger('started_at')->nullable();
            $table->unsignedInteger('finished_at');
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();

            // The attempt's own trace, when it was recorded.
            $table->char('trace_id', 36)->nullable();

            $table->index(['uuid', 'attempt']);
            $table->index('finished_at'); // For trimming...
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_job_attempts');
    }
};
