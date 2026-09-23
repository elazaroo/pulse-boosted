@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Number')

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header :name="$title" details="{{ $summary->count() }} distinct">
        <x-slot:icon>
            @if ($executionType === 'command')
                <x-pulse-boosted::icons.command-line />
            @else
                <x-pulse-boosted::icons.clock />
            @endif
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s="">
        @if ($summary->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">Nothing recorded</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                    @if ($executionType === 'schedule')
                        Either nothing is scheduled, or the scheduler is not running.
                    @else
                        Commands are captured as traces, so they follow the trace sample rate.
                    @endif
                </p>
            </div>
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Name</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Runs</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Failed</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Avg</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right" title="95% of runs finished within this">p95</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Last</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($summary as $row)
                        <tr wire:key="{{ $row['name'] }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $row['name'] }}-row" wire:click="showTrace('{{ $row['traceId'] }}')" class="cursor-pointer group">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-[#7A5AF8]" title="{{ $row['name'] }}">
                                    {{ $row['name'] }}
                                </code>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs">
                                {{ number_format($row['count']) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric @class([
                                'text-xs',
                                'text-gray-400 dark:text-gray-600' => $row['failed'] === 0,
                                'text-red-600 dark:text-red-400 font-medium' => $row['failed'] > 0,
                            ])>
                                {{ number_format($row['failed']) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs whitespace-nowrap">
                                {{ $row['avg'] === null ? '—' : number_format($row['avg']).'ms' }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs whitespace-nowrap" title="Slowest {{ $row['max'] === null ? 'unknown' : number_format($row['max']).'ms' }}">
                                {{ $row['p95'] === null ? '—' : number_format($row['p95']).'ms' }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ $row['lastAt'] === null ? '—' : CarbonImmutable::createFromTimestamp($row['lastAt'])->diffForHumans(short: true) }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
