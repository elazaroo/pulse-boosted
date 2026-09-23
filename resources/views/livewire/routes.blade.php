<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Routes" details="{{ $routes->count() }} routes, from sampled traces">
        <x-slot:icon>
            <x-pulse-boosted::icons.arrows-left-right />
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
                id="select-routes-order-by"
                label="Sort by"
                :options="['calls' => 'requests', 'p95' => 'p95', 'avg' => 'average', 'server' => '5xx']"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($routes->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">No requests recorded</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">Routes are read from traces, so they follow the trace sample rate.</p>
            </div>
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="0%" />
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Method</x-pulse-boosted::th>
                        <x-pulse-boosted::th>Route</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">2xx/3xx</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">4xx</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">5xx</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Total</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Avg</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">p95</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($routes as $route)
                        <tr wire:key="{{ md5($route['name']) }}-spacer" class="h-2 first:h-0"></tr>
                        <tr
                            wire:key="{{ md5($route['name']) }}-row"
                            @if ($route['traceId']) wire:click="showTrace('{{ $route['traceId'] }}')" @endif
                            class="cursor-pointer group"
                            title="Open the slowest request to this route"
                        >
                            <x-pulse-boosted::td>
                                <x-pulse-boosted::http-method-badge :method="$route['method']" />
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $route['path'] }}">{{ $route['path'] }}</code>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-xs text-gray-700 dark:text-gray-300">{{ number_format($route['ok']) }}</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric @class(['text-xs', 'text-amber-600 dark:text-amber-400 font-medium' => $route['client'] > 0, 'text-gray-400 dark:text-gray-600' => $route['client'] === 0])>{{ number_format($route['client']) }}</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric @class(['text-xs', 'text-red-600 dark:text-red-400 font-medium' => $route['server'] > 0, 'text-gray-400 dark:text-gray-600' => $route['server'] === 0])>{{ number_format($route['server']) }}</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-xs text-gray-900 dark:text-gray-100 font-medium">{{ number_format($route['calls']) }}</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-xs text-gray-700 dark:text-gray-300">{{ $route['avg'] === null ? '—' : number_format($route['avg']).'ms' }}</x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-xs text-gray-700 dark:text-gray-300">{{ $route['p95'] === null ? '—' : number_format($route['p95']).'ms' }}</x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
