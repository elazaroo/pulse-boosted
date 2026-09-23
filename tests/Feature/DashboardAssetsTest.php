<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Traces;
use Elazaroo\PulseBoosted\Livewire\TraceViewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('links its scripts and stylesheet instead of putting them in every page', function () {
    $html = $this->get('/pulse-boosted')->assertOk()->getContent();

    expect($html)->toMatch('#<script src="/pulse-boosted/assets/livewire\.js\?v=[0-9a-f]{12}"></script>#');
    expect($html)->toMatch('#<script src="/pulse-boosted/assets/pulse-boosted\.js\?v=[0-9a-f]{12}"></script>#');
    expect($html)->toMatch('#<link rel="stylesheet" href="/pulse-boosted/assets/pulse-boosted\.css\?v=[0-9a-f]{12}">#');
    // What the page used to inline is the bulk of it.
    expect(strlen($html))->toBeLessThan(200_000);
});

it('serves the assets so the browser can keep them', function (string $asset, string $type) {
    $response = $this->get("/pulse-boosted/assets/{$asset}?v=abc")->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith($type);
    expect($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable');
})->with([
    ['livewire.js', 'application/javascript'],
    ['pulse-boosted.js', 'application/javascript'],
    ['pulse-boosted.css', 'text/css'],
]);

it('serves nothing else from the assets route', function () {
    $this->get('/pulse-boosted/assets/composer.json')->assertNotFound();
    $this->get('/pulse-boosted/assets/..%2F..%2Fcomposer.json')->assertNotFound();
});

it('changes an asset address when the file changes', function () {
    $before = Pulse::assetUrl('pulse-boosted.css');

    $path = Pulse::assetPath('pulse-boosted.css');
    $mtime = filemtime($path);
    touch($path, $mtime + 10);
    clearstatcache();

    try {
        expect(Pulse::assetUrl('pulse-boosted.css'))->not->toBe($before);
    } finally {
        touch($path, $mtime);
    }
});

it('has the trace viewer on every dashboard, apart from any card', function () {
    $this->get('/pulse-boosted')->assertSeeLivewire(TraceViewer::class);
});

it('opens a trace asked for by any card, whether or not the Traces card is loaded', function () {
    $traceId = (string) Str::uuid();

    Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->insert([
        'trace_id' => $traceId,
        'type' => 'request',
        'name' => 'GET /checkout',
        'started_at' => now()->getTimestamp(),
        'duration_ms' => 40,
        'status' => 'ok',
    ]));

    Livewire::test(TraceViewer::class)
        ->assertDontSee('GET /checkout')
        ->dispatch('open-trace', traceId: $traceId)
        ->assertSet('selected', $traceId)
        ->assertSee('GET /checkout')
        ->call('deselect')
        ->assertSet('selected', null);

    Livewire::test(Traces::class, ['lazy' => false])
        ->call('select', $traceId)
        ->assertDispatched('open-trace', traceId: $traceId);
});
