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

        // What happened to an issue and who did it: resolved, ignored,
        // reopened, regressed, assigned, and whatever people wrote about it.
        // Kept as long as the issue is.
        Schema::create('pulse_boosted_issue_activity', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 32);

            // comment, resolved, ignored, reopened, regressed, assigned,
            // unassigned.
            $table->string('type', 16);

            // Who did it; null for the dashboard itself — a regression, or an
            // issue resolved for being quiet.
            $table->string('user_id')->nullable();

            // The comment, or for an assignment the user it went to.
            $table->text('body')->nullable();

            $table->unsignedInteger('created_at');

            $table->index(['fingerprint', 'id']);
        });

        // Every task the scheduler knows about, as of its last run, and when
        // each last started and finished — so one that should have run and
        // did not can be told apart from one that is not due yet.
        Schema::create('pulse_boosted_scheduled_tasks', function (Blueprint $table) {
            $table->id();

            // The task's name and its schedule, hashed: the same command on
            // two schedules is two tasks.
            $table->char('key', 32)->unique();
            $table->string('name');
            $table->string('expression', 64);
            $table->string('timezone', 64)->nullable();

            // When the scheduler first and last listed it. A task gone from
            // the schedule stops being listed and is dropped after a while.
            $table->unsignedInteger('first_seen_at');
            $table->unsignedInteger('last_seen_at');

            $table->unsignedInteger('last_started_at')->nullable();
            $table->unsignedInteger('last_finished_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();

            // Skipped by its own conditions — when(), skip(), overlapping —
            // which counts as having been looked at, not as missed.
            $table->unsignedInteger('last_skipped_at')->nullable();

            // The due time last announced as missed, so it is announced once.
            $table->unsignedInteger('missed_notified_for')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_issue_activity');
        Schema::dropIfExists('pulse_boosted_scheduled_tasks');
    }
};
