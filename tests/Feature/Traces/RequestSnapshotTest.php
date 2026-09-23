<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\TraceViewer as TracesCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);

    Route::post('orders', fn () => abort(500, 'Could not create order'));
    Route::post('fine', fn () => 'ok');
});

/**
 * @return array<string, mixed>|null
 */
function snapshotOfLastRequest(): ?array
{
    $meta = json_decode(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->orderByDesc('id')->value('meta')), true);

    return $meta['request'] ?? null;
}

it('keeps the headers of a request that ended in a server error, with credentials replaced', function () {
    $this->withHeaders(['Authorization' => 'Bearer secret-token', 'X-Request-Id' => 'abc-123'])
        ->post('orders')
        ->assertServerError();

    $snapshot = snapshotOfLastRequest();

    expect($snapshot['headers']['x-request-id'])->toBe('abc-123');
    expect($snapshot['headers']['authorization'])->toBe('[redacted]');
    expect(json_encode($snapshot))->not->toContain('secret-token');

    Pulse::flush();
});

it('does not keep the body unless asked to', function () {
    $this->post('orders', ['email' => 'buyer@example.com'])->assertServerError();

    expect(snapshotOfLastRequest())->not->toHaveKey('payload');

    Pulse::flush();
});

it('keeps the body when asked to, with sensitive fields replaced by their length', function () {
    Config::set('pulse-boosted.traces.request.capture_payload', true);

    $this->post('orders', [
        'email' => 'buyer@example.com',
        'password' => 'hunter22',
        'card' => ['card_number' => '4242424242424242', 'expiry' => '12/30'],
        'avatar' => UploadedFile::fake()->image('me.png'),
    ])->assertServerError();

    $payload = snapshotOfLastRequest()['payload'];

    expect($payload['email'])->toBe('buyer@example.com');
    expect($payload['password'])->toBe('[8 bytes redacted]');
    expect($payload['card']['card_number'])->toBe('[16 bytes redacted]');
    expect($payload['card']['expiry'])->toBe('12/30');
    expect($payload['_files']['avatar']['name'])->toBe('me.png');

    Pulse::flush();
});

it('keeps nothing for a request that succeeded', function () {
    $this->post('fine')->assertOk();

    expect(snapshotOfLastRequest())->toBeNull();
});

it('drops the body rather than write an enormous row', function () {
    Config::set('pulse-boosted.traces.request.capture_payload', true);

    $this->post('orders', ['notes' => array_fill(0, 200, str_repeat('x', 900))])->assertServerError();

    $snapshot = snapshotOfLastRequest();

    expect($snapshot)->not->toHaveKey('payload');
    expect($snapshot['truncated'])->toBeTrue();

    Pulse::flush();
});

it('shows what the request carried in the trace panel', function () {
    $this->withHeaders(['X-Request-Id' => 'abc-123'])->post('orders')->assertServerError();

    $traceId = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->orderByDesc('id')->value('trace_id'));

    Livewire::test(TracesCard::class, ['lazy' => false])
        ->call('select', $traceId)
        ->assertSee('Request')
        ->assertSee('x-request-id')
        ->assertSee('abc-123');

    Pulse::flush();
});
