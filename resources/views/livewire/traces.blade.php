@use('Carbon\CarbonImmutable')

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
                        <x-pulse-boosted::th class="hidden @md:table-cell">Type</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Duration</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">When</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($traces as $row)
                        <tr wire:key="{{ $row->trace_id }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $row->trace_id }}-row" wire:click="$dispatch('open-trace', { traceId: '{{ $row->trace_id }}' })" class="cursor-pointer group">
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
                            <x-pulse-boosted::td class="hidden @md:table-cell text-gray-500 dark:text-gray-400 text-xs capitalize">{{ $row->type }}</x-pulse-boosted::td>
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
</x-pulse-boosted::card>
