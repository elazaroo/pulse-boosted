@use('Carbon\CarbonImmutable')
@use('Illuminate\Support\Str')

{{--
    One root element whatever is shown: Livewire marks an @if with comments,
    and at the top level those would sit outside the root and break the
    morph that swaps the lazy placeholder for this.
--}}
<div class="default:col-span-full default:lg:col-span-{{ $cols ?: 'full' }} default:row-span-{{ $rows ?: 1 }} {{ $class }}">
@if (! $enabled || $configured === 0)
    {{-- Nothing to watch is one line, not a card-sized hole at the top of the page. --}}
    <div class="flex items-center gap-3 rounded-lg border border-dashed border-gray-300 dark:border-gray-700 px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400">
        <x-pulse-boosted::icons.signal-slash class="w-4 h-4 shrink-0 stroke-gray-400" />
        @if (! $enabled)
            <span>Alerting is switched off in the configuration.</span>
        @else
            <span><span class="font-medium text-gray-700 dark:text-gray-300">No alert rules.</span> Add them under <code class="font-mono text-xs">alerts.rules</code> to be told when something breaches a threshold.</span>
        @endif
    </div>
@else
<x-pulse-boosted::card cols="full" :rows="1" class="h-full">
    <x-pulse-boosted::card-header name="Alerts" details="{{ $configured }} {{ Str::plural('rule', $configured) }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.signal-slash />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <div class="flex flex-wrap items-center gap-1 mb-3 pb-2 border-b border-gray-200 dark:border-gray-800 shrink-0">
        @foreach (['active' => 'Now', 'history' => 'History'] as $value => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $value }}')"
                @class([
                    'px-2.5 py-1 text-xs font-medium rounded-md whitespace-nowrap',
                    'text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800' => $tab !== $value,
                    'bg-accent-500 text-white' => $tab === $value,
                ])
            >
                {{ $label }}
                @if ($value === 'active' && $active->isNotEmpty())
                    <span class="ml-1 tabular-nums opacity-70">{{ $active->count() }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <x-pulse-boosted::scroll :expand="$expand" wire:poll.30s.visible="">
        @if (! $enabled)
            <div class="h-full flex items-center justify-center p-4">
                <p class="text-sm text-gray-400 dark:text-gray-600 text-center">Alerting is switched off in the configuration.</p>
            </div>
        @elseif ($configured === 0)
            <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">Nothing is being watched</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                    Add rules under <code>alerts.rules</code> in the configuration.
                </p>
            </div>
        @elseif ($tab === 'active')
            @if ($active->isEmpty())
                <div class="mb-3 rounded-md bg-emerald-50 dark:bg-emerald-500/10 px-3 py-2">
                    <p class="text-sm text-emerald-700 dark:text-emerald-400">
                        Nothing is breaching a rule.
                    </p>
                </div>
            @else
                <ul class="space-y-2 mb-3">
                    @foreach ($active as $alert)
                        <li wire:key="active-{{ $alert->id }}" class="rounded-md bg-red-50 dark:bg-red-500/10 px-3 py-2">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="text-sm font-medium text-red-700 dark:text-red-400 truncate">{{ $alert->rule }}</p>
                                <p class="text-xs text-red-700 dark:text-red-400 tabular-nums whitespace-nowrap">
                                    {{ round((float) $alert->value, 2) }}
                                    <span class="opacity-70">{{ $alert->comparison === 'above' ? '>' : '<' }} {{ round((float) $alert->threshold, 2) }}</span>
                                </p>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                                @if ($descriptions[$alert->rule] ?? null)
                                    {{ $descriptions[$alert->rule] }}
                                @else
                                    {{ $alert->metric }}
                                @endif
                                &middot; since {{ CarbonImmutable::createFromTimestamp($alert->triggered_at)->diffForHumans() }}
                                &middot; worst {{ round((float) $alert->peak, 2) }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($quiet->isNotEmpty())
                <h3 class="text-xs text-gray-500 uppercase mb-2">Watching</h3>
                <ul class="space-y-1">
                    @foreach ($quiet as $rule)
                        <li wire:key="quiet-{{ $rule->name }}" class="flex items-baseline justify-between gap-3">
                            <span class="text-xs text-gray-700 dark:text-gray-300 truncate">{{ $rule->name }}</span>
                            <span class="text-xs text-gray-400 dark:text-gray-600 tabular-nums whitespace-nowrap">
                                {{ $rule->metric }} {{ $rule->comparison === 'above' ? '>' : '<' }} {{ round($rule->threshold, 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            @if ($history->isEmpty())
                <div class="h-full flex flex-col items-center justify-center p-4 text-center">
                    <x-pulse-boosted::icons.no-pulse class="h-8 w-8 stroke-gray-300 dark:stroke-gray-700" />
                    <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">Nothing has breached a rule yet</p>
                </div>
            @else
                <x-pulse-boosted::table>
                    <colgroup><col width="100%" /><col width="0%" /><col width="0%" /></colgroup>
                    <x-pulse-boosted::thead>
                        <tr>
                            <x-pulse-boosted::th>Rule</x-pulse-boosted::th>
                            <x-pulse-boosted::th class="text-right">Worst</x-pulse-boosted::th>
                            <x-pulse-boosted::th class="text-right">Lasted</x-pulse-boosted::th>
                        </tr>
                    </x-pulse-boosted::thead>
                    <tbody>
                        @foreach ($history as $alert)
                            <tr wire:key="history-{{ $alert->id }}-spacer" class="h-2 first:h-0"></tr>
                            <tr wire:key="history-{{ $alert->id }}">
                                <x-pulse-boosted::td class="max-w-[1px]">
                                    <p class="text-xs text-gray-900 dark:text-gray-100 truncate">{{ $alert->rule }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        ended {{ CarbonImmutable::createFromTimestamp($alert->resolved_at)->diffForHumans() }}
                                    </p>
                                </x-pulse-boosted::td>
                                <x-pulse-boosted::td numeric class="text-gray-700 dark:text-gray-300 text-xs whitespace-nowrap">
                                    {{ round((float) $alert->peak, 2) }}
                                </x-pulse-boosted::td>
                                <x-pulse-boosted::td numeric class="text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                    {{ CarbonImmutable::createFromTimestamp($alert->triggered_at)->diffForHumans(CarbonImmutable::createFromTimestamp($alert->resolved_at), true, true) }}
                                </x-pulse-boosted::td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-pulse-boosted::table>
            @endif
        @endif
    </x-pulse-boosted::scroll>
</x-pulse-boosted::card>
@endif
</div>
