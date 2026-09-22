@use('Carbon\CarbonImmutable')

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header
        name="Workers"
        details="live"
    >
        <x-slot:icon>
            <x-pulse-boosted::icons.computer-desktop />
        </x-slot:icon>
        <x-slot:actions>
            @if ($canManage && $workers->isNotEmpty())
                <button
                    type="button"
                    wire:click="restart"
                    wire:confirm="Tell every worker to finish its current job and exit? Your process manager has to start them again."
                    class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 whitespace-nowrap"
                >Restart all</button>
            @endif
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.5s="">
        @if ($workers->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">
                    No workers have reported in
                </p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                    Workers appear here once <code>queue:work</code> is running.
                </p>
            </div>
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Worker</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Processed</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Memory</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Seen</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($workers as $worker)
                        <tr wire:key="{{ $worker['key'] }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $worker['key'] }}-row">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <div class="flex items-center gap-2">
                                    <span @class([
                                        'w-2 h-2 rounded-full shrink-0',
                                        'bg-green-500' => $worker['status'] === 'busy',
                                        'bg-gray-400 dark:bg-gray-600' => $worker['status'] === 'idle',
                                        'bg-amber-500' => $worker['status'] === 'stale',
                                        'bg-red-500' => $worker['status'] === 'stopped',
                                    ])></span>
                                    <code class="text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $worker['key'] }}">{{ $worker['key'] }}</code>
                                    <span class="text-xs text-gray-400 dark:text-gray-600 capitalize shrink-0">{{ $worker['status'] }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">
                                    @if ($worker['currentJob'])
                                        Running {{ class_basename($worker['currentJob']) }}
                                    @else
                                        {{ $worker['connection'] ?? '—' }}@if ($worker['queue']) &middot; {{ $worker['queue'] }}@endif
                                    @endif
                                </p>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($worker['processed']) }}
                                @if ($worker['failed'] > 0)
                                    <span class="block text-xs font-normal text-red-600 dark:text-red-400">{{ number_format($worker['failed']) }} failed</span>
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400">
                                {{ $worker['memory'] === null ? '—' : $worker['memory'].' MB' }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs">
                                {{ $worker['silentFor'] < 5 ? 'now' : $worker['silentFor'].'s ago' }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
