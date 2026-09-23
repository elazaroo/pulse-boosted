<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header
        name="Exceptions"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse-boosted::icons.bug-ant />
        </x-slot:icon>
        <x-slot:actions>
            <div class="flex items-center rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                @foreach (['all' => 'All', 'exceptions' => 'Exceptions', 'errors' => 'Errors'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('kind', '{{ $value }}')"
                        @class([
                            'px-2 py-1 text-xs font-medium whitespace-nowrap border-r last:border-r-0 border-gray-200 dark:border-gray-700',
                            'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $kind !== $value,
                            'bg-accent-500 text-white' => $kind === $value,
                        ])
                    >{{ $label }}<span class="ml-1 tabular-nums opacity-70">{{ $kindCounts[$value] }}</span></button>
                @endforeach
            </div>

            <x-pulse-boosted::select
                wire:model.live="orderBy"
                id="select-exceptions-order-by"
                label="Sort by"
                :options="[
                    'count' => 'count',
                    'latest' => 'latest',
                ]"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($exceptions->isEmpty())
            <x-pulse-boosted::no-results />
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Type</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Latest</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Count</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($exceptions->take(100) as $exception)
                        <tr wire:key="{{ $exception->class.$exception->location }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $exception->class.$exception->location }}-row">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $exception->class }}">
                                    {{ $exception->class }}
                                </code>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $exception->location }}">
                                    {{ $exception->location }}
                                </p>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ $exception->latest->ago(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                @if ($config['sample_rate'] < 1)
                                    <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($exception->count) }}">~{{ number_format($exception->count * (1 / $config['sample_rate'])) }}</span>
                                @else
                                    {{ number_format($exception->count) }}
                                @endif
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif

        @if ($exceptions->count() > 100)
            <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
