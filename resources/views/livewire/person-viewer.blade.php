@use('Carbon\CarbonImmutable')
@use('Elazaroo\PulseBoosted\Support\DashboardUrl')
@use('Illuminate\Support\Str')

{{-- One user, over whatever page asked for them. --}}
<div>
    @if ($person !== null)
        <x-pulse-boosted::panel>
            <x-slot:header>
                <div class="flex items-center gap-3">
                    @if ($person->avatar)
                        <img src="{{ $person->avatar }}" alt="" class="w-10 h-10 rounded-full object-cover" loading="lazy">
                    @endif
                    <div class="min-w-0">
                        <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate">{{ $person->name }}</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                            {{ $person->email ?? 'No email' }} &middot; id <code class="font-mono">{{ $person->id }}</code>
                        </p>
                    </div>
                </div>
            </x-slot:header>

            <x-slot:actions>
                <a href="{{ DashboardUrl::to(['user' => $person->id]) }}" class="px-2 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800" title="Narrow every card to this user">Only this user</a>
            </x-slot:actions>

            <dl class="grid grid-cols-2 md:grid-cols-5 gap-4">
                @foreach ([
                    'Requests' => number_format($report['counts']['request'] ?? 0),
                    'Jobs' => number_format($report['counts']['job'] ?? 0),
                    'Failed' => number_format($report['failed']),
                    'First seen' => $report['firstSeen'] ? CarbonImmutable::createFromTimestamp($report['firstSeen'])->diffForHumans() : '—',
                    'Last seen' => $report['lastSeen'] ? CarbonImmutable::createFromTimestamp($report['lastSeen'])->diffForHumans() : '—',
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-gray-500 uppercase">{{ $label }}</dt>
                        <dd @class(['mt-0.5 text-sm font-bold tabular-nums', 'text-red-600 dark:text-red-400' => $label === 'Failed' && $report['failed'] > 0, 'text-gray-900 dark:text-gray-100' => ! ($label === 'Failed' && $report['failed'] > 0)])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <x-pulse-boosted::bars :timeline="$report['timeline']" value="count" failed="errors" :label="'Activity over the last '.$periodLabel.', failures in red'" />

            @if ($report['issues']->isNotEmpty())
                <div>
                    <h3 class="text-xs text-gray-500 uppercase mb-2">Issues they ran into</h3>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800 rounded-md border border-gray-200 dark:border-gray-800">
                        @foreach ($report['issues'] as $issue)
                            <li>
                                <a href="{{ DashboardUrl::to(['issue' => $issue->fingerprint], 'errors') }}" class="flex items-center justify-between gap-3 px-3 py-2 text-xs hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2">
                                            <span @class(['w-1.5 h-1.5 rounded-full shrink-0', 'bg-red-500' => $issue->status === 'open', 'bg-green-500' => $issue->status === 'resolved', 'bg-gray-400' => $issue->status === 'ignored'])></span>
                                            <code class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ in_array($issue->kind, ['exception', 'error'], true) ? class_basename($issue->class) : $issue->class }}</code>
                                        </span>
                                        <span class="block mt-0.5 text-gray-500 dark:text-gray-400 truncate">{{ Str::limit((string) $issue->message, 120) }}</span>
                                    </span>
                                    <span class="shrink-0 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ number_format($issue->times) }}&times;</span>
                                        <span class="block">{{ CarbonImmutable::createFromTimestamp((int) $issue->last_at)->diffForHumans(short: true) }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($report['routes']->isNotEmpty())
                <div>
                    <h3 class="text-xs text-gray-500 uppercase mb-2">Where they went</h3>
                    <ul class="grid md:grid-cols-2 gap-2">
                        @foreach ($report['routes'] as $route)
                            <li class="flex items-center justify-between gap-3 rounded-md border border-gray-200 dark:border-gray-800 px-3 py-2 text-xs">
                                <code class="truncate text-gray-700 dark:text-gray-300" title="{{ $route['name'] }}">{{ $route['name'] }}</code>
                                <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ number_format($route['count']) }}
                                    @if ($route['failed'] > 0)
                                        <span class="text-red-600 dark:text-red-400">&middot; {{ number_format($route['failed']) }} failed</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($report['logs']->isNotEmpty())
                <div>
                    <h3 class="text-xs text-gray-500 uppercase mb-2">Warnings and errors logged for them</h3>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800 rounded-md border border-gray-200 dark:border-gray-800">
                        @foreach ($report['logs'] as $log)
                            <li>
                                <button type="button" wire:click="showTrace('{{ $log->trace_id }}')" class="w-full flex items-center gap-2 px-3 py-2 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <span @class(['shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase', 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400' => $log->level !== 'warning', 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' => $log->level === 'warning'])>{{ $log->level }}</span>
                                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ $log->label }}</span>
                                    <span class="shrink-0 text-gray-400 tabular-nums">{{ CarbonImmutable::createFromTimestamp((int) $log->started_at)->diffForHumans(short: true) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <h3 class="text-xs text-gray-500 uppercase mb-2">Latest executions</h3>
                @if ($report['recent']->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing recorded for them in the last {{ $periodLabel }}.</p>
                @else
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800 rounded-md border border-gray-200 dark:border-gray-800">
                        @foreach ($report['recent'] as $trace)
                            <li>
                                <button type="button" wire:click="showTrace('{{ $trace->trace_id }}')" class="w-full flex items-center justify-between gap-3 px-3 py-2 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <span class="flex items-center gap-2 min-w-0">
                                        <span @class(['w-1.5 h-1.5 rounded-full shrink-0', 'bg-red-500' => $trace->status === 'failed', 'bg-emerald-500' => $trace->status !== 'failed'])></span>
                                        <span class="shrink-0 text-gray-400">{{ Str::headline($trace->type) }}</span>
                                        <code class="truncate text-gray-900 dark:text-gray-100">{{ $trace->name }}</code>
                                    </span>
                                    <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ number_format((int) $trace->duration_ms) }}ms &middot; {{ CarbonImmutable::createFromTimestamp((int) $trace->started_at)->diffForHumans(short: true) }}
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-pulse-boosted::panel>
    @endif
</div>
