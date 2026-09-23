@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Issues" details="{{ number_format($total) }} {{ Str::plural('issue', $total) }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.bug-ant />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200 dark:border-gray-800 shrink-0">
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', '' => 'All'] as $value => $label)
                <button
                    type="button"
                    wire:click="$set('status', '{{ $value }}')"
                    @class([
                        'px-2.5 py-1 text-xs font-medium rounded-md whitespace-nowrap',
                        'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800' => $status !== $value,
                        'bg-[#7A5AF8] text-white' => $status === $value,
                    ])
                >
                    {{ $label }}
                    @if ($value !== '' && ($statusCounts[$value] ?? 0) > 0)
                        <span class="ml-1 tabular-nums opacity-70">{{ number_format($statusCounts[$value]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="Search"
            class="w-40 rounded-md border border-gray-200 dark:border-gray-700 px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
        >
    </div>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s="">
        @if (! $enabled)
            <div class="h-full flex items-center justify-center p-4">
                <p class="text-sm text-gray-400 dark:text-gray-600 text-center">Issue tracking is switched off in the configuration.</p>
            </div>
        @elseif ($issues->isEmpty())
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">
                    {{ $status === 'open' ? 'Nothing is broken' : 'No issues here' }}
                </p>
            </div>
        @else
            <x-pulse-boosted::table>
                <colgroup><col width="100%" /><col width="0%" /><col width="0%" /></colgroup>
                <x-pulse-boosted::thead>
                    <tr>
                        <x-pulse-boosted::th>Issue</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Times</x-pulse-boosted::th>
                        <x-pulse-boosted::th class="text-right">Last seen</x-pulse-boosted::th>
                    </tr>
                </x-pulse-boosted::thead>
                <tbody>
                    @foreach ($issues as $issue)
                        <tr wire:key="{{ $issue->fingerprint }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $issue->fingerprint }}-row" wire:click="select('{{ $issue->fingerprint }}')" class="cursor-pointer group">
                            <x-pulse-boosted::td class="max-w-[1px]">
                                <div class="flex items-center gap-2">
                                    <span @class([
                                        'w-1.5 h-1.5 rounded-full shrink-0',
                                        'bg-red-500' => $issue->status === 'open',
                                        'bg-green-500' => $issue->status === 'resolved',
                                        'bg-gray-400 dark:bg-gray-600' => $issue->status === 'ignored',
                                    ])></span>
                                    <code class="block text-xs text-gray-900 dark:text-gray-100 truncate group-hover:text-[#7A5AF8]">{{ class_basename($issue->class) }}</code>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $issue->message }}">
                                    {{ $issue->message ?: '—' }}
                                </p>
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($issue->occurrences) }}
                            </x-pulse-boosted::td>
                            <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                {{ CarbonImmutable::createFromTimestamp($issue->last_seen_at)->diffForHumans() }}
                            </x-pulse-boosted::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse-boosted::table>

            @if ($issues->count() >= 15 || $page > 1)
                <div class="flex items-center justify-end gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <button type="button" wire:click="$set('page', {{ max(1, $page - 1) }})" @disabled($page <= 1)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Prev</button>
                    <span class="tabular-nums">{{ $page }}</span>
                    <button type="button" wire:click="$set('page', {{ $page + 1 }})" @disabled($issues->count() < 15)
                        class="px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700 disabled:opacity-40">Next</button>
                </div>
            @endif
        @endif
    </x-pulse-boosted::scroll>

    @if ($detail !== null)
        <div class="fixed inset-0 z-40 bg-gray-900/30 dark:bg-black/50" wire:click="deselect"></div>
        <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-2xl overflow-y-auto bg-white dark:bg-gray-900 shadow-xl border-l border-gray-200 dark:border-gray-800">
            @if ($detail['missing'])
                <div class="p-5 flex items-start justify-between">
                    <p class="text-sm text-gray-500 dark:text-gray-400">This issue has been trimmed.</p>
                    <button type="button" wire:click="deselect" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">&times;</button>
                </div>
            @else
                @php($issue = $detail['issue'])
                <div class="sticky top-0 flex items-start justify-between gap-4 p-5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate">{{ $issue->class }}</h2>
                            <span @class([
                                'px-2 py-0.5 rounded-full text-xs font-medium shrink-0 capitalize',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $issue->status === 'open',
                                'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' => $issue->status === 'resolved',
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $issue->status === 'ignored',
                            ])>{{ $issue->status }}</span>
                        </div>
                        @if ($issue->file)
                            <code class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400 truncate">{{ $issue->file }}:{{ $issue->line }}</code>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if ($canManage)
                            @if ($issue->status === 'open')
                                <button type="button" wire:click="resolve('{{ $issue->fingerprint }}')"
                                    class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Resolve</button>
                                <button type="button" wire:click="ignore('{{ $issue->fingerprint }}')"
                                    class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800">Ignore</button>
                            @else
                                <button type="button" wire:click="reopen('{{ $issue->fingerprint }}')"
                                    class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Reopen</button>
                            @endif
                        @endif
                        <button type="button" wire:click="deselect" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">&times;</button>
                    </div>
                </div>

                <div class="p-5 space-y-6">
                    <dl class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        @foreach ([
                            'Occurrences' => number_format($issue->occurrences),
                            'Users affected' => number_format($detail['users']),
                            'First seen' => CarbonImmutable::createFromTimestamp($issue->first_seen_at)->diffForHumans(),
                            'Last seen' => CarbonImmutable::createFromTimestamp($issue->last_seen_at)->diffForHumans(),
                        ] as $label => $value)
                            <div>
                                <dt class="text-xs text-gray-500 uppercase">{{ $label }}</dt>
                                <dd class="mt-0.5 text-sm font-bold text-gray-900 dark:text-gray-100 tabular-nums">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($issue->message)
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Message</h3>
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $issue->message }}</p>
                        </div>
                    @endif

                    @if ($detail['occurrences']->isNotEmpty())
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Recent occurrences</h3>
                            <ul class="space-y-1">
                                @foreach ($detail['occurrences'] as $occurrence)
                                    <li class="flex items-center justify-between gap-3 text-xs">
                                        <span class="text-gray-500 dark:text-gray-400 tabular-nums">
                                            {{ CarbonImmutable::createFromTimestamp($occurrence->occurred_at)->toDateTimeString() }}
                                        </span>
                                        <span class="flex items-center gap-3">
                                            @if ($occurrence->user_id)
                                                <span class="text-gray-500 dark:text-gray-400">user {{ $occurrence->user_id }}</span>
                                            @endif
                                            @if ($occurrence->trace_id)
                                                <button type="button" wire:click="$dispatch('open-trace', { traceId: '{{ $occurrence->trace_id }}' })"
                                                    class="font-medium text-[#7A5AF8] hover:underline">Trace</button>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif
        </aside>
    @endif
</x-pulse-boosted::card>
