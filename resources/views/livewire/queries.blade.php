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
                        <x-pulse-boosted::th class="hidden @lg:table-cell text-right">Avg</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">p95</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($queries as $query)
                        <tr wire:key="{{ md5($query['connection'].$query['sql']) }}-spacer" class="h-2 first:h-0"></tr>
                        <tr
                            wire:key="{{ md5($query['connection'].$query['sql']) }}-row"
                            wire:click="select('{{ $query['key'] }}')"
                            class="cursor-pointer group"
                            title="Open this query"
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
                            <x-pulse-boosted::td numeric class="hidden @lg:table-cell text-gray-700 dark:text-gray-300 text-xs">{{ number_format($query['avg']) }}ms</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs">{{ $query['p95'] === null ? '—' : number_format($query['p95']).'ms' }}</x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </x-pulse-boosted::scroll>

    @if ($detail !== null)
        <x-pulse-boosted::panel>
            <x-slot:header>
                @if ($detail['missing'] ?? false)
                    <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100">Query</h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Not run in the selected period.</p>
                @else
                    <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100">Query on {{ $detail['connection'] ?: 'the default connection' }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ number_format($detail['calls']) }} runs in {{ number_format($detail['executions']) }} sampled {{ \Illuminate\Support\Str::plural('execution', $detail['executions']) }}</p>
                @endif
            </x-slot:header>

            @unless ($detail['missing'] ?? false)
                <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded-md bg-gray-50 dark:bg-gray-800/60 p-3 text-xs font-mono text-gray-800 dark:text-gray-200">{{ $detail['sql'] }}</pre>

                <dl class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    @foreach (['Runs' => number_format($detail['calls']), 'Total' => number_format($detail['total']).'ms', 'Average' => number_format($detail['avg']).'ms', 'p95' => $detail['p95'] === null ? '—' : number_format($detail['p95']).'ms', 'Slowest' => number_format($detail['max']).'ms'] as $label => $value)
                        <div>
                            <dt class="text-xs text-gray-500 uppercase">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm font-bold text-gray-900 dark:text-gray-100 tabular-nums">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="grid md:grid-cols-2 gap-5">
                    <x-pulse-boosted::bars :timeline="$detail['timeline']" value="count" label="Runs" />
                    <x-pulse-boosted::bars :timeline="$detail['timeline']" value="p95" unit="ms" label="p95" />
                </div>

                @if ($detail['locations'] !== [])
                    <div>
                        <h3 class="text-xs text-gray-500 uppercase mb-2">Run from</h3>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800 rounded-md border border-gray-200 dark:border-gray-800">
                            @foreach ($detail['locations'] as $location)
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                                    <code class="truncate text-gray-700 dark:text-gray-300" title="{{ $location['location'] }}">{{ $location['location'] }}</code>
                                    <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($location['calls']) }} &times; &middot; {{ number_format($location['total']) }}ms</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <h3 class="text-xs text-gray-500 uppercase mb-2">Executions that ran it most</h3>
                    <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">The same query many times in one execution is usually an N+1: a query in a loop that one eager load would replace.</p>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800 rounded-md border border-gray-200 dark:border-gray-800">
                        @foreach ($detail['perExecution'] as $execution)
                            <li>
                                <button type="button" wire:click="showTrace('{{ $execution['traceId'] }}')" class="w-full flex items-center justify-between gap-3 px-3 py-2 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <span class="min-w-0 truncate">
                                        <code class="text-gray-900 dark:text-gray-100">{{ $execution['execution'] }}</code>
                                        <span class="ml-2 text-gray-500 dark:text-gray-400">{{ \Carbon\CarbonImmutable::createFromTimestamp($execution['at'])->diffForHumans() }}</span>
                                    </span>
                                    <span @class(['shrink-0 tabular-nums', 'font-semibold text-red-600 dark:text-red-400' => $execution['times'] >= 10, 'text-gray-700 dark:text-gray-300' => $execution['times'] < 10])>{{ number_format($execution['times']) }} &times; &middot; {{ number_format($execution['total']) }}ms</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <h3 class="text-xs text-gray-500 uppercase mb-2">Slowest runs</h3>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800 rounded-md border border-gray-200 dark:border-gray-800">
                        @foreach ($detail['slowest'] as $run)
                            <li>
                                <button type="button" wire:click="showTrace('{{ $run['traceId'] }}')" class="w-full flex items-center justify-between gap-3 px-3 py-2 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <span class="min-w-0 truncate">
                                        <code class="text-gray-900 dark:text-gray-100">{{ $run['execution'] }}</code>
                                        <span class="ml-2 text-gray-500 dark:text-gray-400">{{ \Carbon\CarbonImmutable::createFromTimestamp($run['at'])->diffForHumans() }}</span>
                                    </span>
                                    <span class="shrink-0 font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($run['durationMs']) }}ms</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endunless
        </x-pulse-boosted::panel>
    @endif
</x-pulse-boosted::card>
