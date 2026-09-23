@use('Carbon\CarbonImmutable')

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Scheduled Tasks" details="{{ $missed > 0 ? $missed.' missed' : $tasks->count().' scheduled' }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.clock />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    @if ($stopped)
        <div class="mb-3 rounded-md border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-3 py-2 text-xs text-red-700 dark:text-red-300">
            <span class="font-semibold">The scheduler has stopped.</span>
            It last ran {{ CarbonImmutable::createFromTimestamp($lastRun)->diffForHumans() }}, so nothing scheduled is running. Check the cron entry, or <code>schedule:work</code>.
        </div>
    @endif

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if ($tasks->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">Nothing recorded</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">The schedule is written down the first time <code>schedule:run</code> runs.</p>
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
                        <x-pulse-boosted::th>Task</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="hidden @lg:table-cell text-right">Runs</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Failed</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="hidden @xl:table-cell text-right" title="95% of runs finished within this">p95</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Last</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="hidden @md:table-cell text-right">Next</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($tasks as $row)
                        @php
                            $task = $row['task'];
                            $stats = $row['stats'];
                            $name = $task->name ?? $stats['name'];
                            $lastAt = $task?->last_started_at ?? ($stats['lastAt'] ?? null);
                            $traceId = $stats['traceId'] ?? null;
                        @endphp
                        <tr wire:key="{{ md5($name) }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ md5($name) }}-row" @if ($traceId) wire:click="showTrace('{{ $traceId }}')" @endif @class(['group', 'cursor-pointer' => $traceId])>
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <div class="flex items-center gap-2">
                                    <code class="block text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $name }}">{{ $name }}</code>
                                    @if ($task)
                                        <span @class([
                                            'shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide',
                                            'bg-red-500 text-white' => $task->state === 'missed',
                                            'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' => $task->state === 'failed',
                                            'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400' => $task->state === 'running',
                                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' => $task->state === 'ok',
                                            'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' => in_array($task->state, ['waiting', 'skipped'], true),
                                        ]) @if ($task->state === 'missed' && $task->previous_due) title="Due {{ CarbonImmutable::createFromTimestamp($task->previous_due)->toDateTimeString() }} and not started" @elseif ($task->state === 'skipped') title="Due, but held back by its own conditions" @endif>{{ $task->state === 'waiting' ? 'Not run yet' : $task->state }}</span>
                                    @endif
                                </div>
                                @if ($task)
                                    <p class="mt-0.5 font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $task->expression }}{{ $task->timezone ? ' · '.$task->timezone : '' }}</p>
                                @endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="hidden @lg:table-cell text-gray-700 dark:text-gray-300 text-xs">
                                {{ $stats ? number_format($stats['count']) : '—' }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric @class([
                                'text-xs',
                                'text-gray-400 dark:text-gray-600' => ($stats['failed'] ?? 0) === 0,
                                'text-red-600 dark:text-red-400 font-medium' => ($stats['failed'] ?? 0) > 0,
                            ])>
                                {{ $stats ? number_format($stats['failed']) : '—' }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="hidden @xl:table-cell text-gray-700 dark:text-gray-300 text-xs whitespace-nowrap">
                                {{ ($stats['p95'] ?? null) === null ? '—' : number_format($stats['p95']).'ms' }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ $lastAt === null ? 'never' : CarbonImmutable::createFromTimestamp((int) $lastAt)->diffForHumans(short: true) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="hidden @md:table-cell text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ ($task?->next_due ?? null) === null ? '—' : CarbonImmutable::createFromTimestamp($task->next_due)->diffForHumans(short: true) }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
