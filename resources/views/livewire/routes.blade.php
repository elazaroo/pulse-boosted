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
                            wire:click="select(@js($route['name']))"
                            class="cursor-pointer group"
                            title="Open this route"
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

    @if ($detail !== null)
        <x-pulse-boosted::panel>
            <x-slot:header>
                @if ($detail['missing'] ?? false)
                    <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate">{{ $detail['name'] }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">No requests to this route in the selected period.</p>
                @else
                    <div class="flex items-center gap-2">
                        <x-pulse-boosted::http-method-badge :method="$detail['method']" />
                        <h2 class="font-mono text-sm font-bold text-gray-900 dark:text-gray-100 truncate">{{ $detail['path'] }}</h2>
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ number_format($detail['calls']) }} sampled requests from {{ number_format($detail['users']) }} {{ \Illuminate\Support\Str::plural('user', $detail['users']) }}</p>
                @endif
            </x-slot:header>

            @unless ($detail['missing'] ?? false)
                <dl class="grid grid-cols-3 md:grid-cols-5 gap-4">
                    @foreach (['Average' => $detail['avg'], 'p50' => $detail['p50'], 'p95' => $detail['p95'], 'p99' => $detail['p99'], 'Slowest' => $detail['max']] as $label => $value)
                        <div>
                            <dt class="text-xs text-gray-500 uppercase">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm font-bold text-gray-900 dark:text-gray-100 tabular-nums">{{ $value === null ? '—' : number_format($value).'ms' }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="grid md:grid-cols-2 gap-5">
                    <x-pulse-boosted::bars :timeline="$detail['timeline']" value="count" failed="errors" label="Requests, and 5xx in red" />
                    <x-pulse-boosted::bars :timeline="$detail['timeline']" value="p95" unit="ms" label="p95" />
                </div>

                <div>
                    <h3 class="text-xs text-gray-500 uppercase mb-2">Responses</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($detail['codes'] as $code => $count)
                            <span @class([
                                'rounded-md border px-2 py-1 text-xs tabular-nums',
                                'border-emerald-200 text-emerald-700 dark:border-emerald-500/30 dark:text-emerald-300' => (int) $code > 0 && (int) $code < 400,
                                'border-amber-200 text-amber-700 dark:border-amber-500/30 dark:text-amber-300' => (int) $code >= 400 && (int) $code < 500,
                                'border-red-200 text-red-700 dark:border-red-500/30 dark:text-red-300' => (int) $code >= 500,
                                'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300' => (int) $code === 0,
                            ])><span class="font-semibold">{{ $code }}</span> &times; {{ number_format($count) }}</span>
                        @endforeach
                    </div>
                </div>

                @foreach (['Slowest requests' => $detail['slowest'], 'Recent failures' => $detail['failures']] as $heading => $list)
                    @if ($list->isNotEmpty())
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">{{ $heading }}</h3>
                            <ul class="divide-y divide-gray-100 dark:divide-gray-800 rounded-md border border-gray-200 dark:border-gray-800">
                                @foreach ($list as $request)
                                    <li>
                                        <button type="button" wire:click="showTrace('{{ $request->trace_id }}')" class="w-full flex items-center justify-between gap-3 px-3 py-2 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                            <span class="flex items-center gap-2 min-w-0">
                                                <span @class([
                                                    'rounded px-1.5 py-px font-semibold tabular-nums',
                                                    'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' => $request->code >= 500,
                                                    'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' => $request->code >= 400 && $request->code < 500,
                                                    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $request->code < 400,
                                                ])>{{ $request->code ?: '—' }}</span>
                                                <span class="text-gray-500 dark:text-gray-400 tabular-nums">{{ \Carbon\CarbonImmutable::createFromTimestamp($request->started_at)->diffForHumans() }}</span>
                                                @if ($request->user_id)
                                                    <span class="text-gray-500 dark:text-gray-400 truncate hover:text-accent-500 hover:underline" x-on:click.stop="Livewire.dispatch('open-person', { id: @js((string) $request->user_id) })">{{ $detail['people'][$request->user_id]->name ?? 'User '.$request->user_id }}</span>
                                                @endif
                                            </span>
                                            <span class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format((int) $request->duration_ms) }}ms</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            @endunless
        </x-pulse-boosted::panel>
    @endif
</x-pulse-boosted::card>
