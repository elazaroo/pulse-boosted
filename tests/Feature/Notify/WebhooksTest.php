<?php

use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Elazaroo\PulseBoosted\Events\AlertTriggered;
use Elazaroo\PulseBoosted\Events\ScheduledTaskMissed;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Notify\SendWebhook;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function webhookFailure(): RuntimeException
{
    return new RuntimeException('Payment provider unreachable');
}

beforeEach(function () {
    Http::fake(['*' => Http::response('ok')]);
});

it('sends nothing when no address is configured', function () {
    app(IssueRepository::class)->record(webhookFailure(), null, null);
    app(IssueRepository::class)->flush();

    Http::assertNothingSent();
});

it('posts a new issue as signed JSON', function () {
    Config::set('pulse-boosted.webhooks.urls', 'https://hooks.example.com/pulse');
    Config::set('pulse-boosted.webhooks.secret', 's3cret');

    app(IssueRepository::class)->record(webhookFailure(), null, null);
    app(IssueRepository::class)->flush();

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://hooks.example.com/pulse'
            && $body['event'] === 'issue.opened'
            && $body['data']['issue']['class'] === RuntimeException::class
            && str_contains($body['summary'], 'Payment provider unreachable')
            && str_contains($body['url'], '?issue=')
            && $request->header('X-Pulse-Boosted-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 's3cret');
    });
});

it('gives Slack a message it can show', function () {
    Config::set('pulse-boosted.webhooks.slack', 'https://hooks.slack.com/services/T/B/X');

    app(IssueRepository::class)->record(webhookFailure(), null, null);
    app(IssueRepository::class)->flush();

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return str_starts_with($body['text'], 'New issue: RuntimeException')
            && $body['blocks'][0]['type'] === 'section'
            && str_contains($body['blocks'][0]['text']['text'], '|Open in Pulse Boosted>')
            && ! $request->hasHeader('X-Pulse-Boosted-Signature');
    });
});

it('only sends the events asked for', function () {
    Config::set('pulse-boosted.webhooks.urls', 'https://hooks.example.com/pulse');
    Config::set('pulse-boosted.webhooks.events', ['alert.triggered']);

    app(IssueRepository::class)->record(webhookFailure(), null, null);
    app(IssueRepository::class)->flush();

    Http::assertNothingSent();

    event(new AlertTriggered(new AlertRule('Error rate', 'error_rate', 5), 12.5));

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['event'] === 'alert.triggered'
        && json_decode($request->body(), true)['data']['value'] === 12.5);
});

it('follows the email rules for logged issues', function () {
    Config::set('pulse-boosted.webhooks.urls', 'https://hooks.example.com/pulse');

    Log::warning('Retrying the gateway');
    app(IssueRepository::class)->flush();

    Http::assertNothingSent();

    Log::error('Gateway gave up');
    app(IssueRepository::class)->flush();

    Http::assertSentCount(1);
});

it('announces a missed scheduled task', function () {
    Config::set('pulse-boosted.webhooks.urls', 'https://hooks.example.com/pulse');

    event(new ScheduledTaskMissed((object) [
        'name' => 'reports:send',
        'expression' => '0 * * * *',
        'timezone' => null,
        'last_started_at' => null,
    ], now()->subMinutes(5)->getTimestamp()));

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['event'] === 'schedule.missed'
        && json_decode($request->body(), true)['data']['task']['name'] === 'reports:send');
});

it('never lets a receiver that is down break the code that caused it', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    Config::set('pulse-boosted.webhooks.urls', 'https://hooks.example.com/pulse');

    app(IssueRepository::class)->record(webhookFailure(), null, null);
    app(IssueRepository::class)->flush();

    Http::assertSentCount(1);
});

it('sends through a queue when one is named', function () {
    Queue::fake();
    Config::set('pulse-boosted.webhooks.urls', 'https://hooks.example.com/pulse');
    Config::set('pulse-boosted.webhooks.queue', 'default');

    app(IssueRepository::class)->record(webhookFailure(), null, null);
    app(IssueRepository::class)->flush();

    Http::assertNothingSent();
    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->url === 'https://hooks.example.com/pulse' && $job->body['event'] === 'issue.opened');
});
