@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Jobs" details="{{ $total === null ? 'live' : number_format($total).' '.Str::plural('job', $total) }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.queue-list />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    {{-- Tabs and filters on one line, so the card keeps the same height as its neighbours --}}
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200 dark:border-gray-800 shrink-0">
        <div class="flex flex-wrap items-center gap-1">
            @foreach ($tabs as $value => $label)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $value }}')"
                    @class([
                        'px-2.5 py-1 text-xs font-medium rounded-md whitespace-nowrap',
                        'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800' => $tab !== $value,
                        'bg-[#7A5AF8] text-white' => $tab === $value,
                    ])
                >
                    {{ $label }}
                    @if (isset($counts[$value]) && $counts[$value] > 0)
                        <span class="ml-1 tabular-nums opacity-70">{{ number_format($counts[$value]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search"
                class="w-32 rounded-md border border-gray-200 dark:border-gray-700 px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
            >

            <select
                wire:model.live="queue"
                class="rounded-md border border-gray-200 dark:border-gray-700 pl-2 pr-7 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
            >
                <option value="">All queues</option>
                @foreach ($queues as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </select>

            @if ($canManage && ($counts['failed'] ?? 0) > 0)
                <button
                    type="button"
                    wire:click="retryAll"
                    wire:confirm="Push every failed job back onto its queue?"
                    class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 whitespace-nowrap"
                >Retry all</button>
                <button
                    type="button"
                    wire:click="flush"
                    wire:confirm="Permanently delete every failed job? This cannot be undone."
                    class="px-2 py-1 text-xs font-medium rounded-md border border-red-200 dark:border-red-900 text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950 whitespace-nowrap"
                >Clear</button>
            @endif
        </div>
    </div>
    <x-pulse-boosted::scroll :expand="$expand" wire:poll.5s="">
        @if ($jobs->isEmpty())
            <x-pulse-boosted::no-results />
        @else
            <x-pulse-boosted::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Job</x-pulse-boosted::th>
                        <x-pulse-boosted::th>Status</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Attempts</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">When</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($jobs as $row)
                        @php
                            $uuid = $row->uuid;
                            $status = $live ? $tabs[$tab] : ($row->status ?? '');
                            $when = $live
                                ? ($row->availableAt ?? $row->reservedAt ?? $row->createdAt)?->getTimestamp()
                                : ($row->finished_at ?? $row->started_at ?? $row->queued_at ?? null);
                        @endphp
                        <tr wire:key="{{ $uuid ?? $loop->index }}-spacer" class="h-2 first:h-0"></tr>
                        <tr
                            wire:key="{{ $uuid ?? $loop->index }}-row"
                            @if ($uuid) wire:click="select('{{ $uuid }}')" @endif
                            @class(['cursor-pointer group' => (bool) $uuid])
                        >
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-[#7A5AF8]" title="{{ $row->name }}">{{ $row->name }}</code>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">
                                    @if (! $live && ($row->exception_class ?? null))
                                        <span class="text-red-600 dark:text-red-400">{{ class_basename($row->exception_class) }}</span>
                                    @else
                                        {{ $row->queue ?? '' }}
                                    @endif
                                </p>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td>
                                <span @class([
                                    'px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap',
                                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! in_array($status, ['processed', 'failed', 'timed_out', 'processing']),
                                    'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300' => $status === 'processing',
                                    'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' => $status === 'processed',
                                    'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => in_array($status, ['failed', 'timed_out']),
                                ])>{{ Str::headline($status) }}</span>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ $row->attempts ?? 0 }}@if (! $live && ($row->max_tries ?? null))<span class="text-gray-400">/{{ $row->max_tries }}</span>@endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ $this->moment($when) }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>

            @if ($jobs->count() >= 20 || $page > 1)
                <div class="flex items-center justify-end gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <button type="button" wire:click="$set('page', {{ max(1, $page - 1) }})" @disabled($page <= 1)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Prev</button>
                    <span class="tabular-nums">{{ $page }}</span>
                    <button type="button" wire:click="$set('page', {{ $page + 1 }})" @disabled($jobs->count() < 20)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Next</button>
                </div>
            @endif
        @endif
    </x-pulse-boosted::scroll>

    {{-- Detail panel, in place --}}
    @if ($detail !== null)
        <div class="fixed inset-0 z-40 bg-gray-900/30 dark:bg-black/50" wire:click="deselect"></div>
        <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-xl overflow-y-auto bg-white dark:bg-gray-900 shadow-xl border-l border-gray-200 dark:border-gray-800">
            <div class="sticky top-0 flex items-start justify-between gap-4 p-5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                <div class="min-w-0">
                    @if (($detail['source'] ?? '') === 'missing')
                        <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100">Job not found</h2>
                    @else
                        <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate" title="{{ $detail['name'] }}">{{ $detail['name'] }}</h2>
                        <code class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400 truncate">{{ $detail['uuid'] }}</code>
                    @endif
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    @if (($detail['source'] ?? '') === 'recorded')
                        <button type="button" wire:click="showTrace('{{ $detail['uuid'] }}')"
                            class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 whitespace-nowrap">Trace</button>
                    @endif
                    @if ($detail['canAct'] ?? false)
                        <button type="button" wire:click="retry('{{ $detail['uuid'] }}')" wire:confirm="Push this job back onto its queue?"
                            class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Retry</button>
                        <button type="button" wire:click="forget('{{ $detail['uuid'] }}')" wire:confirm="Permanently delete this failed job?"
                            class="px-2 py-1 text-xs font-medium rounded-md border border-red-200 dark:border-red-900 text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950">Delete</button>
                    @endif
                    <button type="button" wire:click="deselect" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" aria-label="Close">&times;</button>
                </div>
            </div>

            @if (($detail['source'] ?? '') === 'missing')
                <p class="p-5 text-sm text-gray-500 dark:text-gray-400">
                    No record of this job, and it is not on the queue either. It may have been trimmed,
                    or it ran before recording was switched on.
                </p>
            @else
                <div class="p-5 space-y-6">
                    @if (($detail['source'] ?? '') === 'backend')
                        <p class="text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 rounded-md px-3 py-2">
                            Read straight off the queue: this job has not been recorded yet, so there is no timing or argument capture for it.
                        </p>
                    @endif

                    <dl class="grid grid-cols-2 gap-4">
                        @foreach ([
                            'Status' => $detail['statusLabel'],
                            'Connection' => $detail['connection'] ?: '—',
                            'Queue' => $detail['queue'] ?: '—',
                            'Attempts' => $detail['maxTries'] ? $detail['attempts'].' of '.$detail['maxTries'] : (string) $detail['attempts'],
                            'Duration' => $detail['durationMs'] === null ? '—' : number_format($detail['durationMs']).' ms',
                            'Class' => $detail['class'] ? class_basename($detail['class']) : '—',
                            'Batch' => $detail['batchId'] ?: '—',
                            'Backend ID' => $detail['jobId'] ?: '—',
                        ] as $label => $value)
                            <div class="min-w-0">
                                <dt class="text-xs text-gray-500 uppercase">{{ $label }}</dt>
                                <dd class="mt-0.5 text-sm text-gray-900 dark:text-gray-100 truncate" title="{{ $value }}">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($detail['timeline'] !== [])
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Timeline</h3>
                            <ol class="space-y-1">
                                @foreach ($detail['timeline'] as $point)
                                    <li class="flex items-center gap-2 text-sm">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#7A5AF8] shrink-0"></span>
                                        <span class="text-gray-700 dark:text-gray-300">{{ $point['label'] }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ CarbonImmutable::createFromTimestamp($point['at'])->toDateTimeString() }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    <div>
                        <h3 class="text-xs text-gray-500 uppercase mb-2">Arguments</h3>
                        @if ($detail['arguments'] === null)
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Not captured. Payloads carry personal data and credentials, so capture is off
                                until you enable <code class="text-xs">capture_payload</code>.
                            </p>
                        @else
                            <pre class="overflow-x-auto text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-800/50 rounded-md p-3">{{ json_encode($detail['arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        @endif
                    </div>

                    @if ($detail['exception'])
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-1">Exception</h3>
                            <p class="mb-2 text-sm font-medium text-red-600 dark:text-red-400">{{ $detail['exceptionClass'] }}</p>
                            <pre class="overflow-x-auto text-xs text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 rounded-md p-3">{{ $detail['exception'] }}</pre>
                        </div>
                    @endif
                </div>
            @endif
        </aside>
    @endif
</x-pulse-boosted::card>
