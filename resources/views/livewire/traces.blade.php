@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')

@php
    $eventColour = [
        'query' => 'bg-sky-500',
        'cache' => 'bg-teal-500',
        'job' => 'bg-accent-500',
        'http' => 'bg-amber-500',
        'exception' => 'bg-red-500',
        'log' => 'bg-gray-400',
        'mail' => 'bg-pink-500',
        'notification' => 'bg-indigo-500',
    ];
@endphp

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Traces" details="{{ number_format($total) }} recorded">
        <x-slot:icon>
            <x-pulse-boosted::icons.arrows-left-right />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200 dark:border-gray-800 shrink-0">
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['' => 'All', 'request' => 'Requests', 'job' => 'Jobs', 'command' => 'Commands', 'schedule' => 'Scheduled'] as $value => $label)
                <button
                    type="button"
                    wire:click="$set('type', '{{ $value }}')"
                    @class([
                        'px-2.5 py-1 text-xs font-medium rounded-md whitespace-nowrap',
                        'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800' => $type !== $value,
                        'bg-accent-500 text-white' => $type === $value,
                    ])
                >
                    {{ $label }}
                    @if ($value !== '' && ($typeCounts[$value] ?? 0) > 0)
                        <span class="ml-1 tabular-nums opacity-70">{{ number_format($typeCounts[$value]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="$set('status', '{{ $status === 'failed' ? '' : 'failed' }}')"
                @class([
                    'px-2 py-1 text-xs font-medium rounded-md border whitespace-nowrap',
                    'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400' => $status !== 'failed',
                    'border-red-200 dark:border-red-900 bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-400' => $status === 'failed',
                ])
            >Failed only</button>

            <x-pulse-boosted::select
                wire:model.live="orderBy"
                id="select-traces-order-by"
                label="Sort"
                :options="['latest' => 'latest', 'slowest' => 'slowest']"
            />
        </div>
    </div>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.10s.visible="">
        @if (! $enabled)
            <div class="h-full flex items-center justify-center p-4">
                <p class="text-sm text-gray-400 dark:text-gray-600 text-center">Tracing is switched off in the configuration.</p>
            </div>
        @elseif ($traces->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">No traces recorded</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                    Executions are sampled, so only some are kept. Raise <code>traces.sample_rate</code> to record more.
                </p>
            </div>
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="100%" /><col width="0%" /><col width="0%" /><col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Execution</x-pulse-boosted::th>
                        <x-pulse-boosted::th>Type</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Duration</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">When</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($traces as $row)
                        <tr wire:key="{{ $row->trace_id }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $row->trace_id }}-row" wire:click="select('{{ $row->trace_id }}')" class="cursor-pointer group">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <div class="flex items-center gap-2">
                                    @if ($row->status === 'failed')
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0"></span>
                                    @endif
                                    <code class="block text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $row->name }}">{{ $row->name }}</code>
                                    @if (! ($row->sampled ?? true))
                                        <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-medium text-gray-500 dark:text-gray-400 border border-dashed border-gray-300 dark:border-gray-700" title="Lost the sampling draw, kept because it {{ ['failed' => 'failed', 'exception' => 'threw an exception', 'slow' => 'was slow'][json_decode($row->meta ?? '{}', true)['kept_because'] ?? ''] ?? 'mattered' }}">kept</span>
                                    @endif
                                </div>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td class="text-gray-500 dark:text-gray-400 text-xs capitalize">{{ $row->type }}</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ $row->duration_ms === null ? '—' : number_format($row->duration_ms).' ms' }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ CarbonImmutable::createFromTimestamp($row->started_at)->diffForHumans() }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>

            @if ($traces->count() >= 20 || $page > 1)
                <div class="flex items-center justify-end gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <button type="button" wire:click="$set('page', {{ max(1, $page - 1) }})" @disabled($page <= 1)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Prev</button>
                    <span class="tabular-nums">{{ $page }}</span>
                    <button type="button" wire:click="$set('page', {{ $page + 1 }})" @disabled($traces->count() < 20)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Next</button>
                </div>
            @endif
        @endif
    </x-pulse-boosted::scroll>

    {{-- Timeline --}}
    @if ($detail !== null)
        <div class="fixed inset-0 z-40 bg-gray-900/30 dark:bg-black/50" wire:click="deselect"></div>
        <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-3xl overflow-y-auto bg-white dark:bg-gray-900 shadow-xl border-l border-gray-200 dark:border-gray-800">
            @if ($detail['missing'])
                <div class="p-5 flex items-start justify-between">
                    <p class="text-sm text-gray-500 dark:text-gray-400">This trace has been trimmed.</p>
                    <button type="button" wire:click="deselect" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">&times;</button>
                </div>
            @else
                @php($trace = $detail['trace'])
                <div class="sticky top-0 flex items-start justify-between gap-4 p-5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate" title="{{ $trace->name }}">{{ $trace->name }}</h2>
                            <span @class([
                                'px-2 py-0.5 rounded-full text-xs font-medium shrink-0',
                                'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' => $trace->status === 'ok',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $trace->status !== 'ok',
                            ])>{{ $trace->status }}</span>
                            @if (! ($trace->sampled ?? true))
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium shrink-0 border border-dashed border-gray-300 dark:border-gray-700 text-gray-500 dark:text-gray-400">
                                    Kept: {{ ['failed' => 'it failed', 'exception' => 'it threw', 'slow' => 'it was slow'][$detail['meta']['kept_because'] ?? ''] ?? 'it mattered' }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ Str::headline($trace->type) }} &middot; {{ number_format((int) $trace->duration_ms) }} ms &middot;
                            {{ CarbonImmutable::createFromTimestamp($trace->started_at)->toDateTimeString() }}
                        </p>
                    </div>
                    <button type="button" wire:click="deselect" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 shrink-0">&times;</button>
                </div>

                <div class="p-5 space-y-6">
                    {{-- What it spent its time on --}}
                    @if ($detail['summary'] !== [] || $detail['peakMemory'])
                        <div class="flex flex-wrap gap-3">
                            @if ($detail['peakMemory'])
                                <div class="px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                                    <p class="text-xs text-gray-500 uppercase">Peak memory</p>
                                    <p class="text-sm font-bold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($detail['peakMemory'] / 1048576, 1) }} MB</p>
                                </div>
                            @endif
                            @foreach ($detail['summary'] as $type => $totals)
                                <div class="px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                                    <p class="text-xs text-gray-500 uppercase">{{ Str::plural(Str::headline($type), $totals['count']) }}</p>
                                    <p class="text-sm font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ number_format($totals['count']) }}
                                        @if ($totals['ms'] > 0)
                                            <span class="font-normal text-gray-500">&middot; {{ number_format($totals['ms']) }} ms</span>
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($detail['parent'])
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Queued by
                            <button type="button" wire:click="select('{{ $detail['parent']->trace_id }}')" class="font-medium text-accent-500 hover:underline">{{ $detail['parent']->name }}</button>
                        </p>
                    @endif

                    {{-- The timeline itself --}}
                    <div>
                        <h3 class="text-xs text-gray-500 uppercase mb-2">Timeline</h3>
                        @if ($detail['events']->isEmpty())
                            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing was recorded inside this execution.</p>
                        @else
                            @if ($detail['stages'] !== [])
                                <ol class="space-y-2">
                                    @foreach ($detail['stages'] as $stage)
                                        <li>
                                            <div class="flex items-baseline gap-2 text-xs">
                                                <span class="w-14 shrink-0 text-right tabular-nums text-gray-400">{{ number_format($stage['startMs']) }}ms</span>
                                                <span class="flex-1 min-w-0 font-semibold uppercase tracking-wide text-[11px] text-gray-700 dark:text-gray-200">{{ $stage['label'] }}</span>
                                                <span class="w-16 shrink-0 text-right tabular-nums font-medium text-gray-700 dark:text-gray-200">{{ number_format($stage['durationMs']) }}ms</span>
                                            </div>
                                            <div class="ml-16 mt-0.5 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 relative overflow-hidden">
                                                <div class="absolute inset-y-0 rounded-full bg-gray-400 dark:bg-gray-500" style="left: {{ round($stage['left'], 2) }}%; width: {{ round($stage['width'], 2) }}%"></div>
                                            </div>
                                            @if ($stage['events'] !== [])
                                                <ol class="mt-1 ml-4 pl-2 border-l border-gray-200 dark:border-gray-800 space-y-1">
                                                    @foreach ($stage['events'] as $event)
                                                        <li class="group">
                                                            <div class="flex items-baseline gap-2 text-xs">
                                                                <span class="w-14 shrink-0 text-right tabular-nums text-gray-400">{{ number_format($event['offsetMs']) }}ms</span>
                                                                <span class="w-16 shrink-0 text-gray-500 dark:text-gray-400 capitalize">{{ $event['type'] }}</span>
                                                                <span class="flex-1 min-w-0 truncate text-gray-800 dark:text-gray-200" title="{{ $event['label'] }}">
                                                                    @if ($event['level'])
                                                                        <span @class([
                                                                            'mr-1 font-medium',
                                                                            'text-red-600 dark:text-red-400' => in_array($event['level'], ['error', 'critical', 'alert', 'emergency', 'unhandled']),
                                                                            'text-amber-600 dark:text-amber-400' => $event['level'] === 'warning',
                                                                        ])>[{{ $event['level'] }}]</span>
                                                                    @endif
                                                                    {{ $event['label'] }}
                                                                </span>
                                                                <span class="w-16 shrink-0 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                                                    {{ $event['durationMs'] === null ? '' : number_format($event['durationMs']).'ms' }}
                                                                </span>
                                                            </div>
                                                            <div class="ml-16 mt-0.5 h-1 rounded-full bg-gray-100 dark:bg-gray-800 relative overflow-hidden">
                                                                <div
                                                                    @class(['absolute inset-y-0 rounded-full', $eventColour[$event['type']] ?? 'bg-gray-400'])
                                                                    style="left: {{ round($event['left'], 2) }}%; width: {{ round($event['width'], 2) }}%"
                                                                ></div>
                                                            </div>
                                                        </li>
                                                    @endforeach
                                                </ol>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                            <ol class="space-y-1">
                                @foreach ($detail['events'] as $event)
                                    <li class="group">
                                        <div class="flex items-baseline gap-2 text-xs">
                                            <span class="w-14 shrink-0 text-right tabular-nums text-gray-400">{{ number_format($event['offsetMs']) }}ms</span>
                                            <span class="w-16 shrink-0 text-gray-500 dark:text-gray-400 capitalize">{{ $event['type'] }}</span>
                                            <span class="flex-1 min-w-0 truncate text-gray-800 dark:text-gray-200" title="{{ $event['label'] }}">
                                                @if ($event['level'])
                                                    <span @class([
                                                        'mr-1 font-medium',
                                                        'text-red-600 dark:text-red-400' => in_array($event['level'], ['error', 'critical', 'alert', 'emergency', 'unhandled']),
                                                        'text-amber-600 dark:text-amber-400' => $event['level'] === 'warning',
                                                    ])>[{{ $event['level'] }}]</span>
                                                @endif
                                                {{ $event['label'] }}
                                            </span>
                                            <span class="w-16 shrink-0 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                                {{ $event['durationMs'] === null ? '' : number_format($event['durationMs']).'ms' }}
                                            </span>
                                        </div>
                                        <div class="ml-16 mt-0.5 h-1 rounded-full bg-gray-100 dark:bg-gray-800 relative overflow-hidden">
                                            <div
                                                @class(['absolute inset-y-0 rounded-full', $eventColour[$event['type']] ?? 'bg-gray-400'])
                                                style="left: {{ round($event['left'], 2) }}%; width: {{ round($event['width'], 2) }}%"
                                            ></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                            @endif

                            @if (($detail['meta']['dropped_events'] ?? 0) > 0)
                                <p class="mt-3 text-xs text-amber-700 dark:text-amber-400">
                                    {{ number_format($detail['meta']['dropped_events']) }} further events were not recorded: this trace hit the per-trace cap.
                                </p>
                            @endif
                        @endif
                    </div>

                    {{-- Work this execution queued --}}
                    @if ($detail['children']->isNotEmpty())
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Traced work it queued</h3>
                            <ul class="space-y-1">
                                @foreach ($detail['children'] as $child)
                                    <li>
                                        <button type="button" wire:click="select('{{ $child->trace_id }}')" class="text-sm text-accent-500 hover:underline">{{ $child->name }}</button>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ number_format((int) $child->duration_ms) }} ms &middot; {{ $child->status }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- What the application itself attached --}}
                    @if ($detail['context'] !== [])
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Context</h3>
                            <dl class="rounded-md bg-gray-50 dark:bg-gray-800/50 divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach ($detail['context'] as $key => $value)
                                    <div class="flex items-baseline gap-3 px-3 py-1.5">
                                        <dt class="w-32 shrink-0 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $key }}">{{ $key }}</dt>
                                        <dd class="min-w-0 flex-1 text-xs text-gray-900 dark:text-gray-100 break-words">
                                            @if (is_bool($value))
                                                {{ $value ? 'true' : 'false' }}
                                            @elseif ($value === null)
                                                <span class="text-gray-400 dark:text-gray-600">null</span>
                                            @else
                                                {{ $value }}
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif

                    @if ($detail['meta'] !== [])
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Metadata</h3>
                            <pre class="overflow-x-auto text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-800/50 rounded-md p-3">{{ json_encode($detail['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif
                </div>
            @endif
        </aside>
    @endif
</x-pulse-boosted::card>
