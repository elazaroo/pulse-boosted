@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header :name="$title" details="{{ number_format($total) }} recorded">
        <x-slot:icon>
            @if ($eventType === 'log')
                <x-pulse-boosted::icons.clipboard />
            @elseif ($eventType === 'mail')
                <x-pulse-boosted::icons.cloud-arrow-up />
            @else
                <x-pulse-boosted::icons.signal-slash />
            @endif
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200 dark:border-gray-800 shrink-0">
        <div class="flex flex-wrap items-center gap-1">
            @if ($levels !== [])
                <button
                    type="button"
                    wire:click="$set('logLevel', '')"
                    @class([
                        'px-2.5 py-1 text-xs font-medium rounded-md',
                        'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-800' => $level !== '',
                        'bg-accent-500 text-white' => $level === '',
                    ])
                >All</button>

                @foreach (['error', 'warning', 'info', 'debug'] as $name)
                    @if (($levels[$name] ?? 0) > 0)
                        <button
                            type="button"
                            wire:click="$set('logLevel', '{{ $name }}')"
                            @class([
                                'px-2.5 py-1 text-xs font-medium rounded-md capitalize',
                                'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-800' => $level !== $name,
                                'bg-accent-500 text-white' => $level === $name,
                            ])
                        >{{ $name }} <span class="tabular-nums opacity-70">{{ number_format($levels[$name]) }}</span></button>
                    @endif
                @endforeach
            @endif
        </div>

        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="Search"
            class="w-40 rounded-md border border-gray-200 dark:border-gray-700 px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
        >
    </div>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($events->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">Nothing recorded</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                    These are collected as part of a trace, so they follow the trace sample rate.
                </p>
            </div>
        @else
            <x-pulse-boosted::table>
                <colgroup><col width="100%" /><col width="0%" /><col width="0%" /></colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>{{ $eventType === 'log' ? 'Message' : 'Subject' }}</x-pulse-boosted::th>
                        <x-pulse-boosted::th>Execution</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">When</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($events as $event)
                        @php($meta = json_decode($event->meta ?? '{}', true) ?: [])
                        <tr wire:key="{{ $event->id }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $event->id }}-row" wire:click="showTrace('{{ $event->trace_id }}')" class="cursor-pointer group">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <p class="text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $event->label }}">
                                    @if ($event->level)
                                        <span @class([
                                            'mr-1 font-medium',
                                            'text-red-600 dark:text-red-400' => in_array($event->level, ['error', 'critical', 'alert', 'emergency']),
                                            'text-amber-600 dark:text-amber-400' => $event->level === 'warning',
                                            'text-gray-500' => in_array($event->level, ['info', 'debug', 'notice']),
                                        ])>[{{ $event->level }}]</span>
                                    @endif
                                    {{ $event->label }}
                                </p>
                                @if ($meta !== [])
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">
                                        {{ Str::limit(json_encode($meta['context'] ?? $meta, JSON_UNESCAPED_SLASHES), 90) }}
                                    </p>
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td class="text-gray-500 dark:text-gray-400 max-w-[1px]">
                                <code class="block text-xs truncate" title="{{ $event->execution }}">{{ $event->execution }}</code>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ CarbonImmutable::createFromTimestamp($event->started_at)->diffForHumans() }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>

            @if ($events->count() >= 20 || $page > 1)
                <div class="flex items-center justify-end gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <button type="button" wire:click="$set('page', {{ max(1, $page - 1) }})" @disabled($page <= 1)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Prev</button>
                    <span class="tabular-nums">{{ $page }}</span>
                    <button type="button" wire:click="$set('page', {{ $page + 1 }})" @disabled($events->count() < 20)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Next</button>
                </div>
            @endif
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
