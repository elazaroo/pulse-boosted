@use('Illuminate\Support\Str')

<div class="col-span-full" wire:poll.5s>
    {{-- Connection, queue and search --}}
    <div class="flex flex-wrap items-end gap-3 mb-6">
        <x-pulse-boosted::select
            wire:model.live="connection"
            id="queue-explorer-connection"
            label="Connection"
            :options="$connections->mapWithKeys(fn ($name) => [$name => $name])->all()"
        />

        <x-pulse-boosted::select
            wire:model.live="queue"
            id="queue-explorer-queue"
            label="Queue"
            :options="$queues->mapWithKeys(fn ($name) => [$name => $name])->all()"
        />

        <div class="flex border border-gray-200 dark:border-gray-700 overflow-hidden rounded-md focus-within:ring">
            <label for="queue-explorer-search" class="px-3 flex items-center border-r border-gray-200 dark:border-gray-700 text-xs sm:text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap bg-gray-100 dark:bg-gray-800/50">Search</label>
            <input
                id="queue-explorer-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Job name, UUID or exception"
                class="w-64 border-0 px-3 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs sm:text-sm shadow-none focus:ring-0"
            >
        </div>

        @if ($canManage)
            <div class="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    wire:click="retryAll"
                    wire:confirm="Push every failed job back onto its queue?"
                    class="px-3 py-1.5 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800"
                >Retry all failed</button>
                <button
                    type="button"
                    wire:click="flush"
                    wire:confirm="Permanently delete every failed job? This cannot be undone."
                    class="px-3 py-1.5 text-xs font-medium rounded-md border border-red-200 dark:border-red-900 text-red-700 dark:text-red-400 bg-white dark:bg-gray-900 hover:bg-red-50 dark:hover:bg-red-950"
                >Delete all failed</button>
            </div>
        @endif
    </div>

    {{-- Live counters --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach ([
            'Waiting' => $counts->pending,
            'Delayed' => $counts->delayed,
            'Running' => $counts->reserved,
            'Total' => $counts->total(),
        ] as $label => $value)
            <div class="p-4 bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-900/5">
                <p class="text-xs text-gray-500 uppercase">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100">
                    @if ($value === null)
                        <span class="text-base font-medium text-gray-400 dark:text-gray-600" title="{{ $capabilities->reason }}">Unknown</span>
                    @else
                        {{ number_format($value) }}
                    @endif
                </p>
            </div>
        @endforeach
    </div>

    @if ($capabilities->reason)
        <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">{{ $capabilities->reason }}</p>
    @endif

    {{-- Tabs --}}
    <div class="flex flex-wrap items-center gap-1 mb-4 border-b border-gray-200 dark:border-gray-800">
        @foreach ($tabs as $value => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $value }}')"
                @class([
                    'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                    'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $value,
                    'border-[#7A5AF8] text-gray-900 dark:text-gray-100' => $tab === $value,
                ])
            >
                {{ $label }}
                @if (isset($recordedCounts[$value]))
                    <span class="ml-1 text-xs text-gray-400 tabular-nums">{{ number_format($recordedCounts[$value]) }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Rows --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-900/5 p-3">
        @if ($rows->isEmpty())
            <x-pulse-boosted::no-results />
        @else
            <x-pulse-boosted::table>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Job</x-pulse-boosted::th>
                        <x-pulse-boosted::th>UUID</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Attempts</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">{{ $live ? 'Available' : 'When' }}</x-pulse-boosted::th>
                        @unless ($live)
                            <x-pulse-boosted::th class="text-right">Duration</x-pulse-boosted::th>
                        @endunless
                        <x-pulse-boosted::th class="text-right">&nbsp;</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            // Both a PendingJob read from the backend and a recorded row
                            // expose these, so the table does not branch on source.
                            $uuid = $row->uuid;
                            $name = $row->name;
                        @endphp
                        <tr wire:key="{{ $uuid ?? $loop->index }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $uuid ?? $loop->index }}-row">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $name }}">{{ $name }}</code>
                                @unless ($live)
                                    @if ($row->exception_class)
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400 truncate" title="{{ $row->exception_class }}">{{ class_basename($row->exception_class) }}</p>
                                    @endif
                                @endunless
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td class="text-gray-500 dark:text-gray-400">
                                <code class="text-xs">{{ $uuid ? Str::limit($uuid, 8, '…') : '—' }}</code>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ $row->attempts ?? 0 }}@if (! $live && $row->max_tries)<span class="text-gray-400">/{{ $row->max_tries }}</span>@endif
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs">
                                @if ($live)
                                    {{ $row->availableAt?->diffForHumans() ?? $row->reservedAt?->diffForHumans() ?? $row->createdAt?->diffForHumans() ?? '—' }}
                                @else
                                    {{ ($row->finished_at ?? $row->started_at ?? $row->queued_at) ? \Carbon\CarbonImmutable::createFromTimestamp($row->finished_at ?? $row->started_at ?? $row->queued_at)->diffForHumans() : '—' }}
                                @endif
                            </x-pulse-boosted::td>
                            @unless ($live)
                                <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300">
                                    {{ $row->duration_ms === null ? '—' : number_format($row->duration_ms).' ms' }}
                                </x-pulse-boosted::td>
                            @endunless
                            <x-pulse-boosted::td numeric>
                                @if ($uuid && ! $live)
                                    <a href="{{ route('pulse-boosted.jobs.show', $uuid) }}" class="text-xs font-medium text-[#7A5AF8] hover:underline">Details</a>
                                @endif
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>
        @endif
    </div>

    {{-- Paging --}}
    <div class="flex items-center justify-between mt-4 text-xs text-gray-500 dark:text-gray-400">
        <div>
            @if ($total !== null)
                {{ number_format($total) }} {{ Str::plural('job', $total) }}
            @else
                Read live from the queue
            @endif
        </div>
        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="$set('page', {{ max(1, $page - 1) }})"
                @disabled($page <= 1)
                class="px-3 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40"
            >Previous</button>
            <span class="tabular-nums">Page {{ $page }}</span>
            <button
                type="button"
                wire:click="$set('page', {{ $page + 1 }})"
                @disabled($rows->count() < 25)
                class="px-3 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40"
            >Next</button>
        </div>
    </div>
</div>
