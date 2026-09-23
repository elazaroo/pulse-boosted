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

        // One row per distinct problem, rather than per occurrence. Counting
        // exceptions tells you there were four hundred; an issue tells you
        // there was one bug, four hundred times, and whether anyone has dealt
        // with it.
        Schema::create('pulse_boosted_issues', function (Blueprint $table) {
            $table->id();

            // class + file + line, hashed. The message is deliberately left
            // out: "User 41 not found" and "User 42 not found" are one bug.
            $table->char('fingerprint', 32)->unique();

            $table->string('class');
            $table->mediumText('message')->nullable();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();

            $table->string('status', 16)->default('open');

            $table->unsignedInteger('first_seen_at');
            $table->unsignedInteger('last_seen_at');
            $table->unsignedBigInteger('occurrences')->default(0);

            // Set when somebody resolves it, so a reappearance afterwards can
            // be called a regression rather than just another occurrence.
            $table->unsignedInteger('resolved_at')->nullable();

            $table->index('status');
            $table->index('last_seen_at');
            $table->index('class');
        });

        // Enough of the individual occurrences to count affected users and to
        // reach the trace each came from. Trimmed harder than the issues.
        Schema::create('pulse_boosted_issue_occurrences', function (Blueprint $table) {
            $table->id();

            $table->char('fingerprint', 32);
            $table->char('trace_id', 36)->nullable();
            $table->string('user_id')->nullable();
            $table->unsignedInteger('occurred_at');

            $table->index(['fingerprint', 'occurred_at']);
            $table->index('occurred_at'); // For trimming...
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_issue_occurrences');
        Schema::dropIfExists('pulse_boosted_issues');
    }
};
