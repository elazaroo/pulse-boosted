@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')
@use('Elazaroo\PulseBoosted\Support\Location')

<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Issues" details="{{ $view === 'stream' ? 'every log line and exception, as it happened' : 'exceptions, errors, warnings and slow executions, grouped' }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.bug-ant />
        </x-slot:icon>
        <x-slot:actions>
            <div class="flex items-center p-0.5 rounded-md bg-gray-100 dark:bg-gray-900 border border-gray-200/80 dark:border-gray-800">
                @foreach (['grouped' => 'Grouped', 'stream' => 'Every entry'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('view', '{{ $value }}')"
                        @class([
                            'px-2.5 py-1 rounded text-xs font-medium whitespace-nowrap',
                            'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' => $view === $value,
                            'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' => $view !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            @if ($view === 'grouped')
            <div class="flex items-center rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                @foreach (['' => 'All', 'exception' => 'Exceptions', 'error' => 'Errors', 'log' => 'Logged', 'performance' => 'Slow'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('kind', '{{ $value }}')"
                        title="{{ ['error' => 'PHP Errors: usually a bug in the code', 'exception' => 'Exceptions: usually something the application anticipated', 'log' => 'Warnings and errors the application logged', 'performance' => 'Executions slower than their threshold'][$value] ?? '' }}"
                        @class([
                            'px-2 py-1 text-xs font-medium whitespace-nowrap border-r last:border-r-0 border-gray-200 dark:border-gray-700',
                            'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $kind !== $value,
                            'bg-accent-500 text-white' => $kind === $value,
                        ])
                    >{{ $label }}<span class="ml-1 tabular-nums opacity-70">{{ number_format($kindCounts[$value]) }}</span></button>
                @endforeach
            </div>

            <x-pulse-boosted::select
                wire:model.live="orderBy"
                id="select-issues-order-by"
                label="Sort by"
                :options="[
                    'latest' => 'latest',
                    'count' => 'count',
                ]"
                @change="loading = true"
            />
            @endif
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    @if ($view === 'stream')
        @include('pulse-boosted::livewire.partials.log-stream', ['perPage' => \Elazaroo\PulseBoosted\Livewire\Issues::STREAM_PER_PAGE])
    @else
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200 dark:border-gray-800 shrink-0">
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', '' => 'All'] as $value => $label)
                <button
                    type="button"
                    wire:click="$set('status', '{{ $value }}')"
                    @class([
                        'px-2.5 py-1 text-xs font-medium rounded-md whitespace-nowrap',
                        'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800' => $status !== $value,
                        'bg-accent-500 text-white' => $status === $value,
                    ])
                >
                    {{ $label }}
                    @if ($value !== '' && ($statusCounts[$value] ?? 0) > 0)
                        <span class="ml-1 tabular-nums opacity-70">{{ number_format($statusCounts[$value]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <select
                wire:model.live="handled"
                aria-label="Handled or unhandled"
                class="rounded-md border border-gray-200 dark:border-gray-700 pl-2 pr-7 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
            >
                <option value="">Handled or not</option>
                <option value="unhandled">Unhandled</option>
                <option value="handled">Handled</option>
            </select>

            <select
                wire:model.live="assignee"
                aria-label="Assigned to"
                class="rounded-md border border-gray-200 dark:border-gray-700 pl-2 pr-7 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
            >
                <option value="">Anyone's</option>
                @if ($me !== null)
                    <option value="me">Assigned to me</option>
                @endif
                <option value="none">Unassigned</option>
            </select>

            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search"
                class="w-40 rounded-md border border-gray-200 dark:border-gray-700 px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
            >
        </div>
    </div>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
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
                        <x-pulse-boosted::th class="text-right">Count</x-pulse-boosted::th>
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
                                    <code class="block text-xs font-medium text-gray-900 dark:text-gray-100 truncate group-hover:text-accent-500" title="{{ $issue->class }}">{{ $issue->class }}</code>
                                    @if (($issue->kind ?? 'exception') === 'error')
                                        <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400">Error</span>
                                    @endif
                                    @if ($latestDeploy !== null && ($issue->first_seen_deploy ?? null) === $latestDeploy)
                                        <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide bg-accent-500/15 text-accent-600 dark:text-accent-300" title="First seen in the latest deploy, {{ $latestDeploy }}">New</span>
                                    @endif
                                    @if (($issue->kind ?? '') === 'performance')
                                        <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">Slow</span>
                                    @elseif (($issue->kind ?? '') === 'log')
                                        <span @class([
                                            'shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide',
                                            'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400' => in_array($issue->level, ['emergency', 'alert', 'critical', 'error']),
                                            'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' => ! in_array($issue->level, ['emergency', 'alert', 'critical', 'error']),
                                        ]) title="Logged by the application">{{ $issue->level }}</span>
                                    @elseif (! ($issue->handled ?? false))
                                        <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide bg-red-500 text-white" title="Escaped to the exception handler">Unhandled</span>
                                    @else
                                        <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400" title="Caught and passed to report()">Handled</span>
                                    @endif
                                @if ($issue->assignee && isset($assignees[$issue->assignee]))
                                    @php($person = $assignees[$issue->assignee])
                                    <span class="shrink-0 ml-auto flex items-center gap-1 text-[11px] text-gray-500 dark:text-gray-400" title="Assigned to {{ $person->name }}">
                                        @if ($person->avatar)
                                            <img src="{{ $person->avatar }}" alt="" class="w-4 h-4 rounded-full" loading="lazy">
                                        @endif
                                        <span class="hidden md:inline truncate max-w-[8rem]">{{ $person->name }}</span>
                                    </span>
                                @endif
                                </div>
                                @unless (($issue->kind ?? '') === 'log' && $issue->message === $issue->class)
                                    <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300 truncate" title="{{ $issue->message }}">
                                        {{ $issue->message ?: '—' }}
                                    </p>
                                @endunless
                                @if ($location = Location::relative($issue->file, $issue->line))
                                    <p class="mt-0.5 font-mono text-[11px] text-gray-400 dark:text-gray-500 truncate" title="{{ $location }}">{{ $location }}</p>
                                @endif
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

    @endif

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
                            <code class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400 truncate">{{ Location::relative($issue->file, $issue->line) }}</code>
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

                    @php($assigned = $issue->assignee ? ($detail['people'][$issue->assignee] ?? null) : null)
                    <div class="flex flex-wrap items-center gap-3 rounded-md border border-gray-200 dark:border-gray-800 px-3 py-2">
                        <span class="text-xs text-gray-500 uppercase">Assignee</span>
                        @if ($assigned)
                            <span class="flex items-center gap-1.5 text-sm font-medium text-gray-900 dark:text-gray-100">
                                @if ($assigned->avatar)
                                    <img src="{{ $assigned->avatar }}" alt="" class="w-5 h-5 rounded-full" loading="lazy">
                                @endif
                                {{ $assigned->name }}
                            </span>
                        @else
                            <span class="text-sm text-gray-500 dark:text-gray-400">Nobody</span>
                        @endif
                        @if ($canManage)
                            <div class="ml-auto flex items-center gap-2">
                                @if ($me !== null && $issue->assignee !== $me)
                                    <button type="button" wire:click="assignToMe('{{ $issue->fingerprint }}')"
                                        class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Assign to me</button>
                                @endif
                                <select
                                    aria-label="Assign to"
                                    x-on:change="$wire.assign('{{ $issue->fingerprint }}', $event.target.value || null)"
                                    class="rounded-md border border-gray-200 dark:border-gray-700 pl-2 pr-7 py-1 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs shadow-none focus:ring-0"
                                >
                                    <option value="" @selected($issue->assignee === null)>Nobody</option>
                                    @foreach ($detail['candidates'] as $candidate)
                                        <option value="{{ $candidate }}" @selected($issue->assignee === $candidate)>{{ $detail['people'][$candidate]->name ?? "User {$candidate}" }}</option>
                                    @endforeach
                                    @if ($issue->assignee !== null && ! in_array($issue->assignee, $detail['candidates'], true))
                                        <option value="{{ $issue->assignee }}" selected>{{ $assigned->name ?? "User {$issue->assignee}" }}</option>
                                    @endif
                                </select>
                            </div>
                        @endif
                    </div>

                    @if ($issue->message)
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Message</h3>
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $issue->message }}</p>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        @if (($issue->kind ?? '') === 'performance')
                            <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">Slow</span>
                        @elseif (($issue->kind ?? '') === 'log')
                            <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400">Logged {{ $issue->level }}</span>
                        @else
                            <span @class([
                                'rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide',
                                'bg-red-500 text-white' => ! $issue->handled,
                                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $issue->handled,
                            ])>{{ $issue->handled ? 'Handled' : 'Unhandled' }}</span>
                        @endif
                        @if ($issue->laravel_version)
                            <span class="rounded border border-gray-200 dark:border-gray-700 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:text-gray-300">Laravel {{ $issue->laravel_version }}</span>
                        @endif
                        @if ($issue->php_version)
                            <span class="rounded border border-gray-200 dark:border-gray-700 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:text-gray-300">PHP {{ $issue->php_version }}</span>
                        @endif
                        @if ($issue->first_seen_deploy ?? null)
                            <span class="rounded border border-gray-200 dark:border-gray-700 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:text-gray-300" title="First seen in this deploy{{ $issue->last_seen_deploy && $issue->last_seen_deploy !== $issue->first_seen_deploy ? ', last seen in '.$issue->last_seen_deploy : '' }}">
                                Since {{ \Illuminate\Support\Str::limit($issue->first_seen_deploy, 16) }}
                            </span>
                        @endif
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            @click="navigator.clipboard.writeText(@js($detail['markdown'])).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            class="ml-auto rounded-md border border-gray-200 dark:border-gray-700 px-2 py-1 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                        ><span x-show="! copied">Copy as Markdown</span><span x-show="copied" x-cloak>Copied</span></button>
                    </div>

                    @if ($detail['groups'] !== [])
                        <div>
                            <h3 class="text-xs text-gray-500 uppercase mb-2">Stack trace</h3>
                            <div class="space-y-1.5">
                                @foreach ($detail['groups'] as $group)
                                    @if ($group['app'])
                                        @foreach ($group['frames'] as $frame)
                                            <div class="rounded-md border border-gray-200 dark:border-gray-800 overflow-hidden">
                                                <div class="flex items-baseline justify-between gap-3 px-3 py-1.5 bg-gray-50 dark:bg-gray-800/60">
                                                    <code class="text-xs text-accent-600 dark:text-accent-400 truncate" title="{{ $frame['call'] }}">{{ $frame['call'] ?: '—' }}</code>
                                                    <code class="shrink-0 text-[11px] text-gray-500 dark:text-gray-400">{{ $frame['file'] }}{{ $frame['line'] ? ':'.$frame['line'] : '' }}</code>
                                                </div>
                                                @if ($frame['code'])
                                                    <pre class="overflow-x-auto text-[11px] leading-5 font-mono bg-white dark:bg-gray-950">@foreach ($frame['code'] as $number => $text)<div @class(['flex', 'bg-red-50 dark:bg-red-500/10' => (int) $number === (int) $frame['line']])><span class="w-12 shrink-0 pr-3 text-right text-gray-400 select-none">{{ $number }}</span><span @class(['pr-3 whitespace-pre', 'text-red-700 dark:text-red-300 font-medium' => (int) $number === (int) $frame['line'], 'text-gray-700 dark:text-gray-300' => (int) $number !== (int) $frame['line']])>{{ $text }}</span></div>@endforeach</pre>
                                                @endif
                                            </div>
                                        @endforeach
                                    @else
                                        <details class="rounded-md border border-dashed border-gray-200 dark:border-gray-800">
                                            <summary class="cursor-pointer px-3 py-1.5 text-xs text-gray-500 dark:text-gray-400">
                                                {{ count($group['frames']) }} framework {{ \Illuminate\Support\Str::plural('frame', count($group['frames'])) }}
                                            </summary>
                                            <ul class="px-3 pb-2 space-y-0.5">
                                                @foreach ($group['frames'] as $frame)
                                                    <li class="flex items-baseline justify-between gap-3 text-[11px] font-mono">
                                                        <span class="text-gray-600 dark:text-gray-300 truncate">{{ $frame['call'] ?: '—' }}</span>
                                                        <span class="shrink-0 text-gray-400 truncate max-w-[50%]">{{ $frame['file'] ?? '[internal]' }}{{ $frame['line'] ? ':'.$frame['line'] : '' }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                @endforeach
                            </div>
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
                                                <button type="button" wire:click="$dispatch('open-person', { id: @js((string) $occurrence->user_id) })" class="text-gray-500 dark:text-gray-400 hover:text-accent-500 hover:underline">{{ $detail['people'][$occurrence->user_id]->name ?? 'User '.$occurrence->user_id }}</button>
                                            @endif
                                            @if ($occurrence->trace_id)
                                                <button type="button" wire:click="$dispatch('open-trace', { traceId: '{{ $occurrence->trace_id }}' })"
                                                    class="font-medium text-accent-500 hover:underline">Trace</button>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        <h3 class="text-xs text-gray-500 uppercase mb-2">Activity</h3>
                        <ol class="space-y-3">
                            <li class="flex gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span class="mt-1 w-1.5 h-1.5 rounded-full bg-red-400 shrink-0"></span>
                                <span>First seen <span class="tabular-nums">{{ CarbonImmutable::createFromTimestamp($issue->first_seen_at)->diffForHumans() }}</span>@if ($issue->first_seen_deploy ?? null), in {{ \Illuminate\Support\Str::limit($issue->first_seen_deploy, 16) }}@endif</span>
                            </li>
                            @foreach ($detail['activity'] as $entry)
                                @php($who = $entry->user_id !== null ? ($detail['people'][$entry->user_id]->name ?? "User {$entry->user_id}") : null)
                                @php($to = $entry->type === 'assigned' && $entry->body !== null ? ($detail['people'][$entry->body]->name ?? "User {$entry->body}") : null)
                                <li wire:key="activity-{{ $entry->id }}" class="flex gap-2 text-xs">
                                    <span @class([
                                        'mt-1 w-1.5 h-1.5 rounded-full shrink-0',
                                        'bg-accent-500' => $entry->type === 'comment',
                                        'bg-green-500' => $entry->type === 'resolved',
                                        'bg-red-500' => in_array($entry->type, ['regressed', 'reopened'], true),
                                        'bg-gray-400' => in_array($entry->type, ['ignored', 'assigned', 'unassigned'], true),
                                    ])></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-gray-600 dark:text-gray-300">
                                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $who ?? ($entry->type === 'regressed' ? 'It' : 'Pulse Boosted') }}</span>
                                            {{ match ($entry->type) {
                                                'comment' => 'wrote',
                                                'resolved' => 'resolved it',
                                                'ignored' => 'ignored it',
                                                'reopened' => 'reopened it',
                                                'regressed' => 'happened again after being resolved',
                                                'assigned' => $entry->user_id === $entry->body ? 'took it on' : 'assigned it to '.$to,
                                                'unassigned' => 'left it with nobody',
                                                default => $entry->type,
                                            } }}
                                            @if ($entry->type === 'resolved' && $entry->body)
                                                <span class="text-gray-400">— {{ $entry->body }}</span>
                                            @endif
                                            <span class="text-gray-400 tabular-nums" title="{{ CarbonImmutable::createFromTimestamp($entry->created_at)->toDateTimeString() }}">&middot; {{ CarbonImmutable::createFromTimestamp($entry->created_at)->diffForHumans() }}</span>
                                        </p>
                                        @if ($entry->type === 'comment')
                                            <p class="mt-1 rounded-md bg-gray-50 dark:bg-gray-800/60 px-3 py-2 text-sm text-gray-800 dark:text-gray-200 whitespace-pre-line break-words">{{ $entry->body }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        <form wire:submit="addComment('{{ $issue->fingerprint }}')" class="mt-3 space-y-2">
                            <textarea
                                wire:model="comment"
                                rows="2"
                                placeholder="What did you find? Who is looking at it?"
                                class="w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-800 dark:text-gray-200 placeholder:text-gray-400 focus:ring-0 focus:border-accent-500"
                                x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                                x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                            ></textarea>
                            @error('comment')
                                <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <div class="flex justify-end">
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium rounded-md bg-accent-500 text-white hover:bg-accent-600 disabled:opacity-50" wire:loading.attr="disabled" wire:target="addComment">Comment</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </aside>
    @endif
</x-pulse-boosted::card>
