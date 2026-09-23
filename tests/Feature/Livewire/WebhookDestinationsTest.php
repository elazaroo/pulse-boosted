<?php

use Elazaroo\PulseBoosted\Livewire\WebhookDestinations;
use Elazaroo\PulseBoosted\Notify\Destinations;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => true);
});

it('adds a webhook, working out the service from the address', function () {
    Livewire::test(WebhookDestinations::class)
        ->assertSee('No webhooks yet')
        ->call('startAdding')
        ->set('form.url', 'https://discord.com/api/webhooks/123/SECRET-TOKEN')
        ->assertSet('form.type', 'discord')
        ->set('form.name', '#incidents')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('adding', false)
        ->assertSee('#incidents')
        ->assertSee('discord.com')
        ->assertSee('address hidden')
        ->assertDontSee('SECRET-TOKEN');

    $webhook = app(Destinations::class)->find(app(Destinations::class)->all()->first()->id);

    expect($webhook)->type->toBe('discord')->url->toBe('https://discord.com/api/webhooks/123/SECRET-TOKEN')->events->toBeNull();
});

it('checks what it is given', function () {
    Livewire::test(WebhookDestinations::class)
        ->call('startAdding')
        ->set('form.url', 'http://hooks.slack.com/services/T/B/X')
        ->call('add')
        ->assertHasErrors('form.url')
        ->set('form.type', 'teams')
        ->set('form.url', 'https://hooks.slack.com/services/T/B/X')
        ->call('add')
        ->assertHasErrors(['form.url' => 'That looks like a Slack address, not Microsoft Teams.'])
        ->set('form.type', 'telegram')
        ->set('form.url', 'https://api.telegram.org/bot1:abc/sendMessage')
        ->call('add')
        ->assertHasErrors(['form.chat_id'])
        ->set('form.chat_id', '-100123')
        ->set('form.events', [])
        ->call('add')
        ->assertHasErrors(['form.events']);

    expect(app(Destinations::class)->all())->toBeEmpty();
});

it('changes a webhook without ever showing its address', function () {
    $id = app(Destinations::class)->create(['name' => 'Ops', 'type' => 'slack', 'url' => 'https://hooks.slack.com/services/T/B/OLD']);

    Livewire::test(WebhookDestinations::class)
        ->call('startEditing', $id)
        ->assertSet('edit.url', '')
        ->assertDontSee('/OLD')
        ->set('edit.name', 'On-call')
        ->set('edit.events', ['issue.opened', 'alert.triggered'])
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('On-call')
        ->assertSee('2 of 6 events');

    // Left empty, the address stays; given, it is replaced.
    expect(app(Destinations::class)->find($id)->url)->toBe('https://hooks.slack.com/services/T/B/OLD');

    Livewire::test(WebhookDestinations::class)
        ->call('startEditing', $id)
        ->set('edit.url', 'https://hooks.slack.com/services/T/B/NEW')
        ->call('saveEdit');

    expect(app(Destinations::class)->find($id))
        ->url->toBe('https://hooks.slack.com/services/T/B/NEW')
        ->name->toBe('On-call')
        ->events->toBe(['issue.opened', 'alert.triggered']);
});

it('tests, switches off and removes a webhook', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('ok'), 'discord.com/*' => Http::response('Unknown Webhook', 404)]);

    $slack = app(Destinations::class)->create(['name' => 'Slack', 'type' => 'slack', 'url' => 'https://hooks.slack.com/services/T/B/X']);
    $discord = app(Destinations::class)->create(['name' => 'Discord', 'type' => 'discord', 'url' => 'https://discord.com/api/webhooks/1/gone']);

    Livewire::test(WebhookDestinations::class)
        ->call('test', $slack)
        ->assertSee('Test delivered')
        ->call('test', $discord)
        ->assertSee('Test failed · 404')
        ->assertSee('Unknown Webhook')
        ->call('toggle', $slack)
        ->assertSee('Switch on')
        ->call('delete', $discord)
        ->assertDontSee('Discord');

    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'this webhook works'));

    expect(app(Destinations::class)->all())->toHaveCount(1);
    expect(app(Destinations::class)->all()->first()->enabled)->toBeFalsy();
});

it('shows the webhooks to anyone who can see the dashboard, and changes them only with the gate', function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => false);

    $id = app(Destinations::class)->create(['name' => 'Ops', 'type' => 'slack', 'url' => 'https://hooks.slack.com/services/T/B/X']);

    Livewire::test(WebhookDestinations::class)
        ->assertSee('Ops')
        ->assertDontSee('Add a webhook')
        ->call('delete', $id)
        ->assertForbidden();

    Livewire::test(WebhookDestinations::class)->call('test', $id)->assertForbidden();
});

it('is on the settings page', function () {
    $this->get('/pulse-boosted/settings')->assertOk()->assertSeeLivewire(WebhookDestinations::class);
});
