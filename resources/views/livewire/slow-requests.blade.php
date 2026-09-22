<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header
        name="Slow Requests"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="{{ is_array($config['threshold']) ? '' : $config['threshold'].'ms threshold, ' }}past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse-boosted::icons.arrows-left-right />
        </x-slot:icon>
        <x-slot:actions>
            <x-pulse-boosted::select
                wire:model.live="orderBy"
                id="select-slow-requests-order-by"
                label="Sort by"
                :options="[
                    'slowest' => 'slowest',
                    'count' => 'count',
                ]"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.5s="">
        @if ($slowRequests->isEmpty())
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
                        <x-pulse-boosted::th>Route</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Count</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Slowest</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($slowRequests->take(100) as $slowRequest)
                        <tr wire:key="{{ $slowRequest->method.$slowRequest->uri }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $slowRequest->method.$slowRequest->uri }}-row">
                            <x-pulse-boosted::td>
                                <x-pulse-boosted::http-method-badge :method="$slowRequest->method" />
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td class="overflow-hidden max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $slowRequest->uri }}">
                                    {{ $slowRequest->uri }}
                                </code>
                                @if ($slowRequest->action)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $slowRequest->action }}">
                                        {{ $slowRequest->action }}
                                    </p>
                                @endif
                                @if (is_array($config['threshold']))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $slowRequest->threshold }}ms threshold
                                    </p>
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                @if ($config['sample_rate'] < 1)
                                    <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($slowRequest->count) }}">~{{ number_format($slowRequest->count * (1 / $config['sample_rate'])) }}</span>
                                @else
                                    {{ number_format($slowRequest->count) }}
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($slowRequest->slowest === null)
                                    <strong>Unknown</strong>
                                @else
                                    <strong>{{ number_format($slowRequest->slowest) ?: '<1' }}</strong> ms
                                @endif
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>

            @if ($slowRequests->count() > 100)
                <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
            @endif
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
