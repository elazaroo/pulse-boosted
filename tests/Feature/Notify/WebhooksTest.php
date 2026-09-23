<?php

use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Elazaroo\PulseBoosted\Events\AlertTriggered;
use Elazaroo\PulseBoosted\Events\ScheduledTaskMissed;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Notify\Destinations;
use Elazaroo\PulseBoosted\Notify\SendWebhook;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function webhookFailure(): RuntimeException
{
    return new RuntimeException('Payment provider unreachable');
}

/**
 * A webhook, as added on the settings page.
 *
 * @param  list<string>|null  $events
 */
function webhook(string $type, string $url, ?array $events = null, ?string $secret = null, ?string $chatId = null): int
{
    return app(Destinations::class)->create([
        'name' => ucfirst($type),
        'type' => $type,
        'url' => $url,
        'secret' => $secret,
        'chat_id' => $chatId,
        'events' => $events,
    ]);
}

function recordFailure(): void
{
    app(IssueRepository::class)->record(webhookFailure(), null, null);
    app(IssueRepository::class)->flush();
}

function sentBody(Request $request): array
{
    return json_decode($request->body(), true);
}

beforeEach(function () {
    Http::fake(['*' => Http::response('ok')]);
});

it('sends nothing when no webhook has been added', function () {
    recordFailure();

    Http::assertNothingSent();
});

it('posts a new issue as signed JSON to any URL', function () {
    webhook('json', 'https://hooks.example.com/pulse', secret: 's3cret-s3cret-s3cret');

    recordFailure();

    Http::assertSent(function (Request $request) {
        $body = sentBody($request);

        return $request->url() === 'https://hooks.example.com/pulse'
            && $body['event'] === 'issue.opened'
            && $body['data']['issue']['class'] === RuntimeException::class
            && str_contains($body['url'], '?issue=')
            && $request->header('X-Pulse-Boosted-Event')[0] === 'issue.opened'
            && $request->header('X-Pulse-Boosted-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 's3cret-s3cret-s3cret');
    });
});

it('speaks each service in its own format', function (string $type, string $url, Closure $check) {
    webhook($type, $url, chatId: $type === 'telegram' ? '-100123' : null);

    recordFailure();

    Http::assertSent(fn (Request $request) => $request->url() === $url && $check(sentBody($request)) && ! $request->hasHeader('X-Pulse-Boosted-Signature'));
})->with([
    'slack' => ['slack', 'https://hooks.slack.com/services/T/B/X', fn (array $body) => str_starts_with($body['text'], 'New issue: RuntimeException')
        && str_contains($body['blocks'][0]['text']['text'], '|Open in Pulse Boosted>')],
    'mattermost' => ['mattermost', 'https://chat.example.com/hooks/abc', fn (array $body) => isset($body['blocks'], $body['text'])],
    'discord' => ['discord', 'https://discord.com/api/webhooks/1/abc', fn (array $body) => str_starts_with($body['embeds'][0]['title'], 'New issue: RuntimeException')
        && $body['embeds'][0]['color'] === 0xDC2626
        && $body['allowed_mentions'] === ['parse' => []]
        && str_contains($body['embeds'][0]['url'], '?issue=')],
    'teams' => ['teams', 'https://prod-01.westeurope.logic.azure.com/workflows/abc', fn (array $body) => $body['type'] === 'message'
        && $body['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
        && str_starts_with($body['attachments'][0]['content']['body'][0]['text'], 'New issue: RuntimeException')
        && $body['attachments'][0]['content']['actions'][0]['type'] === 'Action.OpenUrl'],
    'google chat' => ['google_chat', 'https://chat.googleapis.com/v1/spaces/X/messages?key=k&token=t', fn (array $body) => str_starts_with($body['text'], '*New issue: RuntimeException')
        && str_contains($body['text'], '|Open in Pulse Boosted>')],
    'telegram' => ['telegram', 'https://api.telegram.org/bot123:abc/sendMessage', fn (array $body) => $body['chat_id'] === '-100123'
        && $body['parse_mode'] === 'HTML'
        && str_starts_with($body['text'], '<b>New issue: RuntimeException')],
]);

it('gives each webhook only the events it asked for', function () {
    webhook('slack', 'https://hooks.slack.com/services/T/B/alerts', ['alert.triggered']);
    webhook('discord', 'https://discord.com/api/webhooks/1/everything');

    recordFailure();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'discord.com'));

    event(new AlertTriggered(new AlertRule('Error rate', 'error_rate', 5), 12.5));

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/alerts') && str_starts_with(sentBody($request)['text'], 'Alert: Error rate'));
});

it('sends nothing to a webhook that is switched off', function () {
    $id = webhook('slack', 'https://hooks.slack.com/services/T/B/X');
    app(Destinations::class)->update($id, ['enabled' => false]);

    recordFailure();

    Http::assertNothingSent();
});

it('follows the email rules for logged issues', function () {
    webhook('json', 'https://hooks.example.com/pulse');

    Log::warning('Retrying the gateway');
    app(IssueRepository::class)->flush();

    Http::assertNothingSent();

    Log::error('Gateway gave up');
    app(IssueRepository::class)->flush();

    Http::assertSentCount(1);
});

it('announces a missed scheduled task', function () {
    webhook('json', 'https://hooks.example.com/pulse');

    event(new ScheduledTaskMissed((object) [
        'name' => 'reports:send',
        'expression' => '0 * * * *',
        'timezone' => null,
        'last_started_at' => null,
    ], now()->subMinutes(5)->getTimestamp()));

    Http::assertSent(fn (Request $request) => sentBody($request)['event'] === 'schedule.missed'
        && sentBody($request)['data']['task']['name'] === 'reports:send');
});

it('never lets a receiver that is down break the code that caused it, and notes it', function () {
    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response('invalid_token', 403)]);
    $id = webhook('slack', 'https://hooks.slack.com/services/T/B/X');

    recordFailure();

    Http::assertSentCount(1);

    $row = app(Destinations::class)->all()->firstWhere('id', $id);
    expect($row)->last_status->toBe(403)->last_error->toBe('invalid_token');
});

it('sends through a queue by id, never with the address in the job', function () {
    Queue::fake();
    Config::set('pulse-boosted.webhooks.queue', 'default');
    $id = webhook('slack', 'https://hooks.slack.com/services/T/B/SECRET');

    recordFailure();

    Http::assertNothingSent();
    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->destination === $id
        && $job->payload['event'] === 'issue.opened'
        && ! str_contains(serialize($job), 'SECRET'));
});

it('keeps addresses and secrets encrypted, and never hands them back', function () {
    $id = webhook('json', 'https://hooks.example.com/pulse/TOKEN123', secret: 'signing-secret-value');

    $row = Pulse::ignore(fn () => DB::table('pulse_boosted_webhooks')->find($id));

    expect($row->url)->not->toContain('TOKEN123')
        ->and($row->secret)->not->toContain('signing-secret-value');

    $public = app(Destinations::class)->all()->first();

    expect($public)->host->toBe('hooks.example.com')->signed->toBeTrue();
    expect(isset($public->url) || isset($public->secret))->toBeFalse();
});

it('works out the service from the address', function (string $url, ?string $type) {
    expect(Destinations::detect($url))->toBe($type);
})->with([
    ['https://hooks.slack.com/services/T/B/X', 'slack'],
    ['https://discord.com/api/webhooks/1/abc', 'discord'],
    ['https://discordapp.com/api/webhooks/1/abc', 'discord'],
    ['https://acme.webhook.office.com/webhookb2/abc', 'teams'],
    ['https://prod-12.westeurope.logic.azure.com:443/workflows/abc', 'teams'],
    ['https://chat.googleapis.com/v1/spaces/X/messages', 'google_chat'],
    ['https://api.telegram.org/bot1:abc/sendMessage', 'telegram'],
    ['https://ops.example.com/hooks', null],
]);
