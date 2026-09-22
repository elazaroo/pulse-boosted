<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header
        name="Queue Status"
        details="live"
    >
        <x-slot:icon>
            <x-pulse-boosted::icons.queue-list />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.5s="">
        @if (! $enabled)
            <div class="h-full flex items-center justify-center p-4">
                <p class="text-sm text-gray-400 dark:text-gray-600 text-center">
                    Live queue inspection is switched off in the configuration.
                </p>
            </div>
        @elseif ($queues->isEmpty())
            <x-pulse-boosted::no-results />
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Queue</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Waiting</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Delayed</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Running</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">&nbsp;</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($queues as $row)
                        @php($key = $row['connection'].':'.$row['queue'])
                        <tr wire:key="{{ $key }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $key }}-row">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <button
                                    type="button"
                                    wire:click="focus('{{ $row['connection'] }}', '{{ $row['queue'] }}')"
                                    class="block w-full text-left truncate text-sm text-gray-900 dark:text-gray-100 hover:text-[#7A5AF8]"
                                    title="Show {{ $key }} in the jobs table"
                                >{{ $row['queue'] }}</button>
                                <p class="mt-0.5 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="truncate">{{ $row['connection'] }}</span>
                                    @if ($row['paused'])
                                        <span class="px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300 font-medium">Paused</span>
                                    @endif
                                </p>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ $row['counts']->pending === null ? '—' : number_format($row['counts']->pending) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400">
                                {{ $row['counts']->delayed === null ? '—' : number_format($row['counts']->delayed) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400">
                                {{ $row['counts']->reserved === null ? '—' : number_format($row['counts']->reserved) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric>
                                @if ($canPause)
                                    @if ($row['paused'])
                                        <button
                                            type="button"
                                            wire:click="resume('{{ $row['connection'] }}', '{{ $row['queue'] }}')"
                                            class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 whitespace-nowrap"
                                        >Resume</button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="pause('{{ $row['connection'] }}', '{{ $row['queue'] }}')"
                                            wire:confirm="Stop workers picking up new jobs from {{ $key }}? Jobs already running will finish."
                                            class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 whitespace-nowrap"
                                        >Pause</button>
                                    @endif
                                @endif
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
