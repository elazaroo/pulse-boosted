<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Queries" details="every query, from sampled traces">
        <x-slot:icon>
            <x-pulse-boosted::icons.circle-stack />
        </x-slot:icon>
        <x-slot:actions>
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search"
                class="w-40 rounded-md border border-gray-200 dark:border-gray-700 px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
            >
            <x-pulse-boosted::select
                wire:model.live="orderBy"
                id="select-queries-order-by"
                label="Sort by"
                :options="['total' => 'total time', 'calls' => 'calls', 'p95' => 'p95', 'avg' => 'average']"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($queries->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">No queries recorded</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">Queries are read from traces, so they follow the trace sample rate.</p>
            </div>
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
                        <x-pulse-boosted::th>Query</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Calls</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Total</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Avg</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">p95</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($queries as $query)
                        <tr wire:key="{{ md5($query['connection'].$query['sql']) }}-spacer" class="h-2 first:h-0"></tr>
                        <tr
                            wire:key="{{ md5($query['connection'].$query['sql']) }}-row"
                            @if ($query['traceId']) wire:click="showTrace('{{ $query['traceId'] }}')" @endif
                            class="cursor-pointer group"
                            title="Open the slowest execution that ran it"
                        >
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $query['sql'] }}">{{ $query['sql'] }}</code>
                                <p class="mt-0.5 flex items-center gap-2 text-[11px] text-gray-400 dark:text-gray-500 truncate">
                                    <span>{{ $query['connection'] }}</span>
                                    @if ($query['location'])
                                        <span class="font-mono truncate" title="{{ $query['location'] }}">{{ $query['location'] }}</span>
                                        @if ($query['locations'] > 1)
                                            <span>+{{ $query['locations'] - 1 }} more</span>
                                        @endif
                                    @endif
                                </p>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs">{{ number_format($query['calls']) }}</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-900 dark:text-gray-100 text-xs font-medium">{{ number_format($query['total']) }}ms</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs">{{ number_format($query['avg']) }}ms</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs">{{ $query['p95'] === null ? '—' : number_format($query['p95']).'ms' }}</x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
