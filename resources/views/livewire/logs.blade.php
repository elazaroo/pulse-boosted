@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')
@use('Elazaroo\PulseBoosted\Logging\LogStream')

@php
    $filters = ['' => 'All', LogStream::EXCEPTIONS => 'Exceptions'];

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $name) {
        if (($counts[$name] ?? 0) > 0) {
            $filters[$name] = ucfirst($name);
        }
    }

    $severe = ['emergency', 'alert', 'critical', 'error'];
@endphp

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Logging" details="{{ number_format($total) }} {{ Str::plural('entry', $total) }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.clipboard />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200 dark:border-gray-800 shrink-0">
        <div class="flex flex-wrap items-center gap-1">
            @foreach ($filters as $value => $label)
                <button
                    type="button"
                    wire:click="$set('logLevel', '{{ $value }}')"
                    @class([
                        'px-2.5 py-1 text-xs font-medium rounded-md whitespace-nowrap',
                        'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800' => $logLevel !== $value,
                        'bg-accent-500 text-white' => $logLevel === $value,
                    ])
                >
                    {{ $label }}
                    @if ($value !== '' && ($counts[$value] ?? 0) > 0)
                        <span class="ml-1 tabular-nums opacity-70">{{ number_format($counts[$value]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="Search"
            class="w-40 rounded-md border border-gray-200 dark:border-gray-700 px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
        >
    </div>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($entries->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">Nothing logged</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                    Every exception is kept. Log lines are collected as part of a trace, so they follow the trace sample rate.
                </p>
            </div>
        @else
            <x-pulse-boosted::table>
                <colgroup><col width="100%" /><col width="0%" /><col width="0%" /></colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Entry</x-pulse-boosted::th>
                        <x-pulse-boosted::th>Execution</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">When</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr wire:key="{{ $entry->key }}-spacer" class="h-2 first:h-0"></tr>
                        <tr
                            wire:key="{{ $entry->key }}-row"
                            @if ($entry->execution !== null) wire:click="showTrace('{{ $entry->traceId }}')" @endif
                            @class(['group', 'cursor-pointer' => $entry->execution !== null])
                        >
                            <x-pulse-boosted::td class="max-w-[1px]">
                                @if ($entry->kind === 'exception')
                                    <p class="flex items-baseline gap-2 min-w-0">
                                        <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400">
                                            {{ $entry->isError ? 'Error' : 'Exception' }}
                                        </span>
                                        <code class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $entry->class }}">{{ $entry->class }}</code>
                                    </p>
                                    @if ($entry->message !== '')
                                        <p class="mt-0.5 text-xs text-gray-700 dark:text-gray-300 truncate" title="{{ $entry->message }}">{{ $entry->message }}</p>
                                    @endif
                                    @if ($entry->location)
                                        <p class="mt-0.5 font-mono text-[11px] text-gray-400 dark:text-gray-500 truncate" title="{{ $entry->location }}">{{ $entry->location }}</p>
                                    @endif
                                @else
                                    <p class="flex items-baseline gap-2 min-w-0">
                                        <span @class([
                                            'shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide',
                                            'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400' => in_array($entry->level, $severe),
                                            'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' => $entry->level === 'warning',
                                            'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' => ! in_array($entry->level, $severe) && $entry->level !== 'warning',
                                        ])>{{ $entry->level }}</span>
                                        <span class="text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $entry->message }}">{{ $entry->message }}</span>
                                    </p>
                                    @if ($entry->context)
                                        <p class="mt-0.5 font-mono text-[11px] text-gray-400 dark:text-gray-500 truncate">{{ Str::limit($entry->context, 120) }}</p>
                                    @endif
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td class="text-gray-500 dark:text-gray-400 w-56 max-w-[14rem]">
                                @if ($entry->execution !== null)
                                    <code class="block text-xs truncate" title="{{ $entry->execution }}">{{ $entry->execution }}</code>
                                @else
                                    <span class="text-xs text-gray-300 dark:text-gray-600" title="This execution was not traced, or its trace has expired">—</span>
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ CarbonImmutable::createFromTimestamp($entry->at)->diffForHumans() }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>

            @if ($entries->count() >= \Elazaroo\PulseBoosted\Livewire\Logs::PER_PAGE || $page > 1)
                <div class="flex items-center justify-end gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <button type="button" wire:click="$set('page', {{ max(1, $page - 1) }})" @disabled($page <= 1)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Prev</button>
                    <span class="tabular-nums">{{ $page }}</span>
                    <button type="button" wire:click="$set('page', {{ $page + 1 }})" @disabled($entries->count() < \Elazaroo\PulseBoosted\Livewire\Logs::PER_PAGE)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Next</button>
                </div>
            @endif
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
