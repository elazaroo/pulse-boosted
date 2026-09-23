@use('Illuminate\Support\Str')
<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header
        name="Slow Outgoing Requests"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="{{ is_array($config['threshold']) ? '' : $config['threshold'].'ms threshold, ' }}past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse-boosted::icons.cloud-arrow-up />
        </x-slot:icon>
        <x-slot:actions>
            @php
                $count = count($config['groups']);
                $message = sprintf(
                    "URIs may be normalized using groups.\n\nThere %s currently %d %s configured.",
                    $count === 1 ? 'is' : 'are',
                    $count,
                    Str::plural('group', $count)
                );
            @endphp
            <button title="{{ $message }}" @click="alert(@js($message))">
                <x-pulse-boosted::icons.information-circle class="w-5 h-5 stroke-gray-400 dark:stroke-gray-600" />
            </button>

            <x-pulse-boosted::select
                wire:model.live="orderBy"
                id="select-slow-outgoing-requests-order-by"
                label="Sort by"
                :options="[
                    'slowest' => 'slowest',
                    'count' => 'count',
                ]"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($slowOutgoingRequests->isEmpty())
            <x-pulse-boosted::no-results />
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="0%" />
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Method</x-pulse-boosted::th>
                        <x-pulse-boosted::th>URI</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Count</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Slowest</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($slowOutgoingRequests->take(100) as $request)
                        <tr wire:key="{{ $request->method.$request->uri }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $request->method.$request->uri }}-row">
                            <x-pulse-boosted::td>
                                <x-pulse-boosted::http-method-badge :method="$request->method" />
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <div class="flex items-center" title="{{ $request->uri }}">
                                    @if ($host = parse_url($request->uri, PHP_URL_HOST))
                                        <img wire:ignore src="https://unavatar.io/{{ $host }}?fallback=false" loading="lazy" class="w-4 h-4 mr-2" onerror="this.style.display='none'" />
                                    @endif
                                    <code class="block text-xs text-gray-900 dark:text-gray-100 truncate">
                                        {{ $request->uri }}
                                    </code>
                                </div>
                                @if (is_array($config['threshold']))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $request->threshold }}ms threshold
                                    </p>
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                @if ($config['sample_rate'] < 1)
                                    <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($request->count) }}">~{{ number_format($request->count * (1 / $config['sample_rate'])) }}</span>
                                @else
                                    {{ number_format($request->count) }}
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($request->slowest === null)
                                    <strong>Unknown</strong>
                                @else
                                    <strong>{{ number_format($request->slowest) ?: '<1' }}</strong> ms
                                @endif
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>

            @if ($slowOutgoingRequests->count() > 100)
                <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
            @endif
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
