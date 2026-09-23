@use('Carbon\CarbonImmutable')
@use('Elazaroo\PulseBoosted\Settings\Schema')

@php
    $input = 'w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2.5 py-1.5 text-sm text-gray-800 dark:text-gray-200 placeholder:text-gray-400 focus:ring-0 focus:border-accent-500 disabled:opacity-60';
    $small = 'rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-xs text-gray-800 dark:text-gray-200 focus:ring-0 focus:border-accent-500 disabled:opacity-60';
@endphp

<x-pulse-boosted::card :cols="$cols" class="h-full">
    <x-pulse-boosted::card-header :name="$title" :details="$description">
        <x-slot:icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    <form wire:submit="save" class="space-y-5">
        @unless ($canManage)
            <p class="rounded-md border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                You can read these, but changing them needs the <code>managePulseBoostedQueues</code> gate.
            </p>
        @endunless

        @foreach ($fields as $field)
            @php
                $id = Schema::id($field['key']);
                $model = 'values.'.$id;
                $change = $changes[$field['key']] ?? null;
            @endphp
            <div wire:key="field-{{ $id }}" class="grid md:grid-cols-[16rem_1fr] gap-x-6 gap-y-2 border-t border-gray-100 dark:border-gray-800 pt-4 first-of-type:border-0 first-of-type:pt-0">
                <div>
                    <label for="{{ $id }}" class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $field['label'] }}</label>
                    @if ($field['help'] ?? null)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $field['help'] }}</p>
                    @endif
                    <p class="mt-1.5 text-[11px]">
                        @if ($change)
                            <span class="rounded px-1.5 py-px font-semibold uppercase tracking-wide bg-accent-500/15 text-accent-600 dark:text-accent-300">Set here</span>
                            <span class="text-gray-400">
                                {{ CarbonImmutable::createFromTimestamp($change['updated_at'])->diffForHumans() }}@if ($change['updated_by']) by {{ $people[$change['updated_by']]->name ?? 'user '.$change['updated_by'] }}@endif
                            </span>
                            @if ($canManage)
                                &middot; <button type="button" wire:click="useConfig(@js($field['key']))" class="text-accent-500 hover:underline">use config</button>
                            @endif
                        @else
                            <span class="text-gray-400">From config and .env</span>
                        @endif
                    </p>
                </div>

                <div class="min-w-0">
                    @switch ($field['type'])
                        @case ('bool')
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input id="{{ $id }}" type="checkbox" wire:model="{{ $model }}" @disabled(! $canManage) class="rounded border-gray-300 dark:border-gray-600 text-accent-500 focus:ring-0">
                                On
                            </label>
                            @break

                        @case ('emails')
                        @case ('urls')
                        @case ('ids')
                            <textarea id="{{ $id }}" wire:model="{{ $model }}" rows="3" @disabled(! $canManage) class="{{ $input }} font-mono text-xs"
                                placeholder="{{ ['emails' => "oncall@example.com\nlead@example.com", 'urls' => 'https://ops.example.com/hooks/pulse', 'ids' => "1\n42"][$field['type']] }}"></textarea>
                            @break

                        @case ('secrets')
                        @case ('secret')
                            @if ($secrets[$field['key']] !== [])
                                <ul class="mb-2 space-y-1">
                                    @foreach ($secrets[$field['key']] as $masked)
                                        <li class="font-mono text-xs text-gray-600 dark:text-gray-300">{{ $masked }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($field['type'] === 'secrets')
                                <textarea id="{{ $id }}" wire:model="{{ $model }}" rows="2" @disabled(! $canManage) class="{{ $input }} font-mono text-xs" autocomplete="off"
                                    placeholder="{{ $secrets[$field['key']] === [] ? 'https://hooks.slack.com/services/…' : 'Leave empty to keep; anything here replaces them' }}"></textarea>
                            @else
                                <div class="flex gap-2">
                                    <input id="{{ $id }}" type="text" wire:model="{{ $model }}" @disabled(! $canManage) class="{{ $input }} font-mono text-xs" autocomplete="off"
                                        placeholder="{{ $secrets[$field['key']] === [] ? 'At least 16 characters' : 'Leave empty to keep' }}">
                                    @if ($canManage)
                                        <button type="button" wire:click="generateSecret(@js($field['key']))" class="shrink-0 px-2.5 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Generate</button>
                                    @endif
                                </div>
                            @endif
                            @if ($canManage && $secrets[$field['key']] !== [])
                                <button type="button" wire:click="clearSecret(@js($field['key']))" wire:confirm="Remove {{ strtolower($field['label']) }}? Nothing will be sent there any more." class="mt-1.5 text-xs text-red-600 dark:text-red-400 hover:underline">Remove</button>
                            @endif
                            @break

                        @case ('select')
                            <select id="{{ $id }}" wire:model="{{ $model }}" @disabled(! $canManage) class="{{ $input }} max-w-sm">
                                @foreach ($field['options'] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @break

                        @case ('rate')
                            <div class="flex items-center gap-3 max-w-sm" x-data>
                                <input id="{{ $id }}" type="range" min="0" max="1" step="0.01" wire:model.live="{{ $model }}" @disabled(! $canManage) class="flex-1 accent-accent-500">
                                <span class="w-20 text-right text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ is_numeric($values[$id] ?? null) ? round($values[$id] * 100, 1).'%' : '—' }}</span>
                            </div>
                            @break

                        @case ('milliseconds')
                            @if (in_array($field['key'], $arrays, true))
                                <p class="text-xs text-gray-500 dark:text-gray-400">Set per pattern in <code>config/pulse-boosted.php</code>, which a single number here would replace.</p>
                            @endif
                            <div class="flex items-center gap-2 max-w-xs">
                                <input id="{{ $id }}" type="number" min="1" step="1" wire:model="{{ $model }}" @disabled(! $canManage) class="{{ $input }} tabular-nums" placeholder="—">
                                <span class="text-xs text-gray-500">ms</span>
                            </div>
                            @break

                        @case ('text')
                            <input id="{{ $id }}" type="text" wire:model="{{ $model }}" @disabled(! $canManage) class="{{ $input }} max-w-sm" placeholder="—">
                            @break

                        @case ('events')
                            <div class="grid sm:grid-cols-2 gap-1.5">
                                @foreach (Schema::events() as $event)
                                    <label class="inline-flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                                        <input type="checkbox" value="{{ $event }}" wire:model="{{ $model }}" @disabled(! $canManage) class="rounded border-gray-300 dark:border-gray-600 text-accent-500 focus:ring-0">
                                        <code>{{ $event }}</code>
                                    </label>
                                @endforeach
                            </div>
                            @break

                        @case ('thresholds')
                            <div class="space-y-2">
                                @forelse ($values[$id] as $i => $row)
                                    <div wire:key="{{ $id }}-{{ $i }}" class="flex flex-wrap items-center gap-2">
                                        <select wire:model="{{ $model }}.{{ $i }}.type" @disabled(! $canManage) class="{{ $small }} w-36">
                                            @foreach (Schema::thresholdTypes() as $type => $label)
                                                <option value="{{ $type }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" wire:model="{{ $model }}.{{ $i }}.pattern" @disabled(! $canManage) class="{{ $small }} flex-1 min-w-[12rem] font-mono" placeholder="GET /checkout, *, or #^GET /api/#">
                                        <input type="number" min="1" wire:model="{{ $model }}.{{ $i }}.ms" @disabled(! $canManage) class="{{ $small }} w-24 tabular-nums" placeholder="ms">
                                        <span class="text-xs text-gray-500">ms</span>
                                        @if ($canManage)
                                            <button type="button" wire:click="removeRow(@js($field['key']), {{ $i }})" class="px-1.5 text-gray-400 hover:text-red-500" aria-label="Remove">&times;</button>
                                        @endif
                                    </div>
                                    @foreach (['type', 'pattern', 'ms'] as $part)
                                        @error($model.'.'.$i.'.'.$part) <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    @endforeach
                                @empty
                                    <p class="text-xs text-gray-500 dark:text-gray-400">None: nothing is an issue for being slow.</p>
                                @endforelse
                                @if ($canManage)
                                    <button type="button" wire:click="addRow(@js($field['key']))" class="text-xs font-medium text-accent-500 hover:underline">+ Add a threshold</button>
                                @endif
                            </div>
                            @break

                        @case ('rules')
                            <div class="space-y-3">
                                @forelse ($values[$id] as $i => $row)
                                    <div wire:key="{{ $id }}-{{ $i }}" class="rounded-md border border-gray-200 dark:border-gray-800 p-3 space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input type="text" wire:model="{{ $model }}.{{ $i }}.name" @disabled(! $canManage) class="{{ $small }} flex-1 min-w-[10rem] font-medium" placeholder="Name">
                                            @if ($canManage)
                                                <button type="button" wire:click="removeRow(@js($field['key']), {{ $i }})" class="px-1.5 text-gray-400 hover:text-red-500" aria-label="Remove">&times;</button>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                            <select wire:model="{{ $model }}.{{ $i }}.metric" @disabled(! $canManage) class="{{ $small }}">
                                                @foreach (Schema::metrics() as $metric => $label)
                                                    <option value="{{ $metric }}" title="{{ $label }}">{{ $metric }}</option>
                                                @endforeach
                                            </select>
                                            <span>is</span>
                                            <select wire:model="{{ $model }}.{{ $i }}.comparison" @disabled(! $canManage) class="{{ $small }}">
                                                <option value="above">above</option>
                                                <option value="below">below</option>
                                            </select>
                                            <input type="number" step="any" wire:model="{{ $model }}.{{ $i }}.threshold" @disabled(! $canManage) class="{{ $small }} w-24 tabular-nums" placeholder="threshold">
                                            <span>over</span>
                                            <input type="text" wire:model="{{ $model }}.{{ $i }}.window" @disabled(! $canManage) class="{{ $small }} w-28" placeholder="5 minutes">
                                        </div>
                                        <div class="grid sm:grid-cols-2 gap-2">
                                            <input type="text" wire:model="{{ $model }}.{{ $i }}.options" @disabled(! $canManage) class="{{ $small }} font-mono" placeholder="Options: type=request, queue=default">
                                            <input type="text" wire:model="{{ $model }}.{{ $i }}.description" @disabled(! $canManage) class="{{ $small }}" placeholder="Description, shown on the Alerts card">
                                        </div>
                                        <p class="text-[11px] text-gray-400">{{ Schema::metrics()[$row['metric']] ?? '' }}</p>
                                        @foreach (['name', 'metric', 'threshold', 'comparison', 'window', 'options', 'description'] as $part)
                                            @error($model.'.'.$i.'.'.$part) <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                        @endforeach
                                    </div>
                                @empty
                                    <p class="text-xs text-gray-500 dark:text-gray-400">No rules: nothing is watched.</p>
                                @endforelse
                                @if ($canManage)
                                    <button type="button" wire:click="addRow(@js($field['key']))" class="text-xs font-medium text-accent-500 hover:underline">+ Add a rule</button>
                                @endif
                            </div>
                            @break
                    @endswitch

                    @error($model)
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endforeach

        @if ($group === 'webhooks' && $tested !== null)
            <div class="rounded-md border border-gray-200 dark:border-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($tested as $result)
                    <div class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                        <span class="min-w-0 truncate font-mono text-gray-700 dark:text-gray-300">{{ $result['slack'] ? 'Slack · ' : '' }}{{ $result['target'] }}</span>
                        @if ($result['ok'])
                            <span class="shrink-0 font-semibold text-emerald-600 dark:text-emerald-400">Delivered</span>
                        @else
                            <span class="shrink-0 font-semibold text-red-600 dark:text-red-400" title="{{ $result['error'] }}">Failed{{ $result['status'] ? ' · '.$result['status'] : '' }}</span>
                        @endif
                    </div>
                @empty
                    <p class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">No webhooks saved yet, so there was nothing to send to.</p>
                @endforelse
            </div>
        @endif

        @if ($canManage)
            <div class="flex items-center justify-end gap-3 border-t border-gray-100 dark:border-gray-800 pt-4">
                @if ($saved)
                    <span class="text-xs text-emerald-600 dark:text-emerald-400" wire:key="saved-{{ $saved }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show" x-transition.opacity>Saved</span>
                @endif
                @if ($group === 'webhooks')
                    <button type="button" wire:click="testWebhooks" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Send a test</button>
                @endif
                <button type="submit" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs font-medium rounded-md bg-accent-500 text-white hover:bg-accent-600 disabled:opacity-50">Save {{ strtolower($title) }}</button>
            </div>
        @endif
    </form>
</x-pulse-boosted::card>
