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

        // Where to post what happens: a Slack channel, a Discord channel, a
        // Teams workflow, or any URL. Added from the settings page.
        Schema::create('pulse_boosted_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // slack, discord, teams, google_chat, mattermost, telegram, json.
            $table->string('type', 16);

            // The address is the credential for most of these services, so
            // it is stored encrypted and never shown again once saved. So is
            // the signing secret, and for Telegram the chat id rides along.
            $table->text('url');
            $table->text('secret')->nullable();
            $table->string('chat_id')->nullable();

            // Which events it gets; null for every one.
            $table->text('events')->nullable();
            $table->boolean('enabled')->default(true);

            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->string('created_by')->nullable();

            // How the last delivery went, so a broken one is noticed.
            $table->unsignedInteger('last_sent_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_boosted_webhooks');
    }
};
