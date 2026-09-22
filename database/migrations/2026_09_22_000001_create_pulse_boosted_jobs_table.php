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

        Schema::create('pulse_boosted_jobs', function (Blueprint $table) {
            $table->id();

            // Identity. The UUID comes from the job payload and is the only
            // identifier that survives the whole lifecycle; the backend id is
            // whatever the driver assigned and is gone once the job is done.
            $table->char('uuid', 36)->unique();
            $table->string('job_id')->nullable();

            $table->string('connection');
            $table->string('queue');
            $table->string('name');
            $table->string('class')->nullable();
            $table->string('status', 16);

            $table->unsignedInteger('queued_at')->nullable();
            $table->unsignedInteger('started_at')->nullable();
            $table->unsignedInteger('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_tries')->nullable();
            $table->unsignedSmallInteger('timeout')->nullable();

            // Captured from the live job object when it is queued, already
            // redacted and JSON encoded. Never unserialized when read back.
            $table->mediumText('payload')->nullable();
            $table->mediumText('tags')->nullable();

            $table->mediumText('exception')->nullable();
            $table->string('exception_class')->nullable();

            $table->char('batch_id', 36)->nullable();
            $table->string('worker')->nullable();

            $table->index('queued_at'); // For trimming...
            $table->index('status'); // For the status tabs...
            $table->index('class'); // For filtering by job class...
            $table->index('batch_id'); // For the batch view...
            $table->index(['connection', 'queue']); // For filtering by queue...
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_jobs');
    }
};
