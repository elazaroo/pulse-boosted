<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Overview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('shows the headline figures', function () {
    Pulse::ignore(function () {
        foreach (['ok', 'ok', 'ok', 'failed'] as $status) {
            DB::table('pulse_boosted_traces')->insert([
                'trace_id' => (string) Str::uuid(),
                'type' => 'request',
                'name' => 'GET /',
                'started_at' => now()->getTimestamp(),
                'duration_ms' => 120,
                'status' => $status,
            ]);
        }

        DB::table('pulse_boosted_issue_occurrences')->insert([
            'fingerprint' => md5('x'),
            'occurred_at' => now()->getTimestamp(),
        ]);
    });

    Livewire::test(Overview::class, ['lazy' => false])
        ->assertSee('Error rate')
        ->assertSee('25%')
        ->assertSee('120ms')
        ->assertSee('Exceptions')
        ->assertSee('Queue backlog');
});

it('shows a dash rather than a zero for what it cannot read', function () {
    // No traces at all: there is no error rate, which is not the same as 0%.
    Livewire::test(Overview::class, ['lazy' => false])
        ->assertSee('—')
        ->assertDontSee('0%');
});

it('links each figure to the section that explains it', function () {
    Livewire::test(Overview::class, ['lazy' => false])
        ->assertSeeHtml('href="#errors"')
        ->assertSeeHtml('href="#queues"');
});
