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

        // One row per execution context: a request, an Artisan command, a
        // scheduled task, or a job being worked. Everything else that happens
        // hangs off one of these.
        Schema::create('pulse_boosted_traces', function (Blueprint $table) {
            $table->id();

            $table->char('trace_id', 36)->unique();

            // A job queued during a request keeps a line back to it, so a
            // failure can be followed to whatever asked for the work.
            $table->char('parent_trace_id', 36)->nullable();

            $table->string('type', 16);
            $table->string('name');

            $table->unsignedInteger('started_at');
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('status', 16)->default('ok');

            // Whether this execution won the sampling draw. One that lost it is
            // still kept when it failed, threw or was slow — but it must not
            // count towards rates and percentiles, or keeping every failure
            // and a tenth of the successes would make the error rate lie.
            $table->boolean('sampled')->default(true);

            $table->string('user_id')->nullable();
            $table->mediumText('meta')->nullable();

            $table->index('started_at'); // For trimming and for the list...
            $table->index('type');
            $table->index('parent_trace_id');
            $table->index('status');
        });

        // The children. Deliberately narrow: a trace with a thousand queries
        // is a thousand rows, so this table is the one that has to stay cheap.
        Schema::create('pulse_boosted_trace_events', function (Blueprint $table) {
            $table->id();

            $table->char('trace_id', 36);

            $table->string('type', 16);
            $table->mediumText('label');

            // Milliseconds from the start of the trace, which is what the
            // timeline needs; absolute timestamps would have to be subtracted
            // on every render.
            $table->unsignedBigInteger('offset_ms')->default(0);
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->string('level', 16)->nullable();
            $table->mediumText('meta')->nullable();

            $table->index(['trace_id', 'offset_ms']); // For drawing a timeline...
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_trace_events');
        Schema::dropIfExists('pulse_boosted_traces');
    }
};
