@use('Illuminate\Support\Str')
<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header
        name="Cache"
        x-bind:title="`Global Time: {{ number_format($allTime) }}ms; Global run at: ${formatDate('{{ $allRunAt }}')}; Key Time: {{ number_format($keyTime) }}ms; Key run at: ${formatDate('{{ $keyRunAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse-boosted::icons.rocket-launch />
        </x-slot:icon>
        <x-slot:actions>
            @php
                $count = count($config['groups']);
                $message = sprintf(
                    "Keys may be normalized using groups.\n\nThere %s currently %d %s configured.",
                    $count === 1 ? 'is' : 'are',
                    $count,
                    Str::plural('group', $count)
                );
            @endphp
            <button title="{{ $message }}" @click="alert(@js($message))">
                <x-pulse-boosted::icons.information-circle class="w-5 h-5 stroke-gray-400 dark:stroke-gray-600" />
            </button>
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($allCacheInteractions->hits === 0 && $allCacheInteractions->misses === 0 && $allCacheInteractions->writes === 0 && $allCacheInteractions->deletes === 0)
            <x-pulse-boosted::no-results />
        @else
            <div class="flex flex-col gap-6">
                <div class="grid grid-cols-3 @md:grid-cols-5 gap-3 text-center">
                    <div class="flex flex-col justify-center @sm:block">
                        <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">
                            @if ($config['sample_rate'] < 1)
                                <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($allCacheInteractions->hits) }}">~{{ number_format($allCacheInteractions->hits * (1 / $config['sample_rate'])) }}</span>
                            @else
                                {{ number_format($allCacheInteractions->hits) }}
                            @endif
                        </span>
                        <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">
                            Hits
                        </span>
                    </div>
                    <div class="flex flex-col justify-center @sm:block">
                        <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">
                            @if ($config['sample_rate'] < 1)
                                <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($allCacheInteractions->misses) }}">~{{ number_format(($allCacheInteractions->misses) * (1 / $config['sample_rate'])) }}</span>
                            @else
                                {{ number_format($allCacheInteractions->misses) }}
                            @endif
                        </span>
                        <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">
                            Misses
                        </span>
                    </div>
                    <div class="flex flex-col justify-center @sm:block">
                        <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">
                            @if ($allCacheInteractions->hits + $allCacheInteractions->misses > 0)
                                {{ ((int) ($allCacheInteractions->hits / ($allCacheInteractions->hits + $allCacheInteractions->misses) * 10000)) / 100 }}%
                            @else
                                —
                            @endif
                        </span>
                        <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">
                            Hit Rate
                        </span>
                    </div>
                    <div class="flex flex-col justify-center @sm:block">
                        <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format($allCacheInteractions->writes) }}</span>
                        <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">Writes</span>
                    </div>
                    <div class="flex flex-col justify-center @sm:block">
                        <span @class(['text-xl uppercase font-bold tabular-nums', 'text-red-600 dark:text-red-400' => $allCacheInteractions->failures > 0, 'text-gray-700 dark:text-gray-300' => $allCacheInteractions->failures === 0])>{{ number_format($allCacheInteractions->failures) }}</span>
                        <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">Failures</span>
                    </div>
                </div>
                <div>
                    <x-pulse-boosted::table>
                        <colgroup>
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
                                <x-pulse-boosted::th>Key</x-pulse-boosted::th>
                                <x-pulse-boosted::th class="text-right">Hits</x-pulse-boosted::th>
                                <x-pulse-boosted::th class="text-right">Misses</x-pulse-boosted::th>
                                <x-pulse-boosted::th class="text-right">Writes</x-pulse-boosted::th>
                                <x-pulse-boosted::th class="text-right">Deletes</x-pulse-boosted::th>
                                <x-pulse-boosted::th class="text-right">Failures</x-pulse-boosted::th>
                                <x-pulse-boosted::th class="text-right whitespace-nowrap">Hit Rate</x-pulse-boosted::th>
                            </tr>
                        </x-pulse-boosted::thead>
                        <tbody>
                            @foreach ($cacheKeyInteractions->take(100) as $interaction)
                                <tr wire:key="{{ $interaction->key }}-spacer" class="h-2 first:h-0"></tr>
                                <tr wire:key="{{ $interaction->key }}-row">
                                    <x-pulse-boosted::td class="max-w-[1px]">
                                        <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $interaction->key }}">
                                            {{ $interaction->key }}
                                        </code>
                                    </x-pulse-boosted::td>
                                    <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                        @if ($config['sample_rate'] < 1)
                                            <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($interaction->hits) }}">~{{ number_format($interaction->hits * (1 / $config['sample_rate'])) }}</span>
                                        @else
                                            {{ number_format($interaction->hits) }}
                                        @endif
                                    </x-pulse-boosted::td>
                                    <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                        @if ($config['sample_rate'] < 1)
                                            <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($interaction->misses) }}">~{{ number_format($interaction->misses * (1 / $config['sample_rate'])) }}</span>
                                        @else
                                            {{ number_format($interaction->misses) }}
                                        @endif
                                    </x-pulse-boosted::td>
                                    <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300">{{ number_format($interaction->writes) }}</x-pulse-boosted::td>
                                    <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300">{{ number_format($interaction->deletes) }}</x-pulse-boosted::td>
                                    <x-pulse-boosted::td numeric @class(['text-red-600 dark:text-red-400 font-bold' => $interaction->failures > 0, 'text-gray-400 dark:text-gray-600' => $interaction->failures === 0])>{{ number_format($interaction->failures) }}</x-pulse-boosted::td>
                                    <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                        @if ($interaction->hits + $interaction->misses > 0)
                                            {{ ((int) ($interaction->hits / ($interaction->hits + $interaction->misses) * 10000)) / 100 }}%
                                        @else
                                            —
                                        @endif
                                    </x-pulse-boosted::td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-pulse-boosted::table>

                    @if ($cacheKeyInteractions->count() > 100)
                        <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
                    @endif
                </div>
            </div>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
