@use('Carbon\CarbonImmutable')

@php
    $input = 'w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2.5 py-1.5 text-sm text-gray-800 dark:text-gray-200 placeholder:text-gray-400 focus:ring-0 focus:border-accent-500';
    $button = 'px-2.5 py-1 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 whitespace-nowrap';
    $badge = [
        'slack' => 'bg-[#4A154B] text-white',
        'discord' => 'bg-[#5865F2] text-white',
        'teams' => 'bg-[#4B53BC] text-white',
        'google_chat' => 'bg-[#00AC47] text-white',
        'mattermost' => 'bg-[#1E325C] text-white',
        'telegram' => 'bg-[#229ED9] text-white',
        'json' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];
@endphp

<x-pulse-boosted::card :cols="$cols">
    <x-pulse-boosted::card-header name="Destinations" details="{{ $webhooks->count() }} {{ \Illuminate\Support\Str::plural('webhook', $webhooks->count()) }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.arrows-left-right />
        </x-slot:icon>
        <x-slot:actions>
            @if ($canManage && ! $adding)
                <button type="button" wire:click="startAdding" class="ml-auto px-3 py-1.5 text-xs font-medium rounded-md bg-accent-500 text-white hover:bg-accent-600">Add a webhook</button>
            @endif
        </x-slot:actions>
    </x-pulse-boosted::card-header>

    <div class="space-y-3">
        <p class="text-xs text-gray-500 dark:text-gray-400">
            A webhook's address is all it takes to post to that channel, so once saved it is kept encrypted and never shown again — only where it goes. To point one somewhere else, change it; to stop it, switch it off or remove it.
        </p>

        @if ($adding)
            <form wire:submit="add" class="rounded-md border border-accent-500/40 p-4 space-y-3">
                <div class="grid gap-3 md:grid-cols-[1fr_12rem]">
                    <div>
                        <label for="webhook-url" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                        <input id="webhook-url" type="url" wire:model.live.debounce.400ms="form.url" class="{{ $input }} font-mono text-xs" placeholder="{{ $hints[$form['type'] ?: 'slack'] }}" autocomplete="off" spellcheck="false">
                        @error('form.url') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="webhook-type" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Service</label>
                        <select id="webhook-type" wire:model.live="form.type" class="{{ $input }}">
                            <option value="">Work it out</option>
                            @foreach ($types as $type => $label)
                                <option value="{{ $type }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('form.type') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label for="webhook-name" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                        <input id="webhook-name" type="text" wire:model="form.name" class="{{ $input }}" placeholder="{{ $form['type'] ? $types[$form['type']] : 'e.g. #incidents' }}">
                    </div>
                    @if ($form['type'] === 'telegram')
                        <div>
                            <label for="webhook-chat" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Chat id</label>
                            <input id="webhook-chat" type="text" wire:model="form.chat_id" class="{{ $input }} font-mono text-xs" placeholder="-1001234567890">
                            @error('form.chat_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($form['type'] === 'json' || $form['type'] === '')
                        <div>
                            <label for="webhook-secret" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Signing secret <span class="font-normal text-gray-400">optional, for any URL</span></label>
                            <input id="webhook-secret" type="text" wire:model="form.secret" class="{{ $input }} font-mono text-xs" placeholder="At least 16 characters" autocomplete="off">
                            @error('form.secret') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <fieldset>
                    <legend class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Events</legend>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                        @foreach ($events as $event)
                            <label class="inline-flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                                <input type="checkbox" value="{{ $event }}" wire:model="form.events" class="rounded border-gray-300 dark:border-gray-600 text-accent-500 focus:ring-0">
                                <code>{{ $event }}</code>
                            </label>
                        @endforeach
                    </div>
                    @error('form.events') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </fieldset>

                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="cancel" class="{{ $button }}">Cancel</button>
                    <button type="submit" class="px-3 py-1.5 text-xs font-medium rounded-md bg-accent-500 text-white hover:bg-accent-600">Add</button>
                </div>
            </form>
        @endif

        @if ($webhooks->isEmpty() && ! $adding)
            <div class="rounded-md border border-dashed border-gray-200 dark:border-gray-800 px-4 py-6 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">No webhooks yet.</p>
                <p class="mt-1 text-xs text-gray-400">Slack, Discord, Microsoft Teams, Google Chat, Mattermost and Telegram each get a message in their own format; any other URL gets the event as JSON.</p>
            </div>
        @endif

        <ul class="space-y-2">
            @foreach ($webhooks as $webhook)
                @php($result = $results[$webhook->id] ?? null)
                <li wire:key="webhook-{{ $webhook->id }}" @class(['rounded-md border border-gray-200 dark:border-gray-800 p-3', 'opacity-60' => ! $webhook->enabled])>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $badge[$webhook->type] ?? $badge['json'] }}">{{ $types[$webhook->type] ?? $webhook->type }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $webhook->name }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate">
                                @if ($webhook->readable)
                                    <span class="font-mono">{{ $webhook->host }}</span> · address hidden
                                @else
                                    <span class="text-red-600 dark:text-red-400">Saved with a different application key — change the address</span>
                                @endif
                                · {{ $webhook->events === null ? 'every event' : count($webhook->events).' of '.count($events).' events' }}
                                @if ($webhook->signed) · signed @endif
                            </p>
                        </div>

                        <span class="shrink-0 text-[11px] tabular-nums">
                            @if ($result)
                                @if ($result['ok'])
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">Test delivered</span>
                                @else
                                    <span class="font-semibold text-red-600 dark:text-red-400" title="{{ $result['error'] }}">Test failed{{ $result['status'] ? ' · '.$result['status'] : '' }}</span>
                                @endif
                            @elseif ($webhook->last_sent_at)
                                @if ($webhook->last_error === null && $webhook->last_status !== null && $webhook->last_status < 300)
                                    <span class="text-emerald-600 dark:text-emerald-400">Delivered {{ CarbonImmutable::createFromTimestamp($webhook->last_sent_at)->diffForHumans(short: true) }}</span>
                                @else
                                    <span class="text-red-600 dark:text-red-400" title="{{ $webhook->last_error }}">Failed{{ $webhook->last_status ? ' · '.$webhook->last_status : '' }} {{ CarbonImmutable::createFromTimestamp($webhook->last_sent_at)->diffForHumans(short: true) }}</span>
                                @endif
                            @else
                                <span class="text-gray-400">Not sent yet</span>
                            @endif
                        </span>

                        @if ($canManage)
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button type="button" wire:click="test({{ $webhook->id }})" wire:loading.attr="disabled" wire:target="test({{ $webhook->id }})" class="{{ $button }}">Test</button>
                                <button type="button" wire:click="startEditing({{ $webhook->id }})" class="{{ $button }}">Change</button>
                                <button type="button" wire:click="toggle({{ $webhook->id }})" class="{{ $button }}">{{ $webhook->enabled ? 'Switch off' : 'Switch on' }}</button>
                                <button type="button" wire:click="delete({{ $webhook->id }})" wire:confirm="Remove {{ $webhook->name }}? Nothing more will be sent there." class="{{ $button }} text-red-600 dark:text-red-400">Remove</button>
                            </div>
                        @endif
                    </div>

                    @if ($result && ! $result['ok'] && $result['error'])
                        <p class="mt-2 rounded bg-red-50 dark:bg-red-500/10 px-2 py-1 text-[11px] text-red-700 dark:text-red-300 break-words">{{ $result['error'] }}</p>
                    @endif

                    @if ($editing === $webhook->id)
                        <form wire:submit="saveEdit" class="mt-3 border-t border-gray-100 dark:border-gray-800 pt-3 space-y-3">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                                    <input type="text" wire:model="edit.name" class="{{ $input }}">
                                    @error('edit.name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">New address <span class="font-normal text-gray-400">leave empty to keep the current one</span></label>
                                    <input type="url" wire:model="edit.url" class="{{ $input }} font-mono text-xs" placeholder="{{ $hints[$webhook->type] ?? '' }}" autocomplete="off" spellcheck="false">
                                    @error('edit.url') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                                @if ($webhook->type === 'telegram')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">New chat id <span class="font-normal text-gray-400">leave empty to keep</span></label>
                                        <input type="text" wire:model="edit.chat_id" class="{{ $input }} font-mono text-xs">
                                    </div>
                                @elseif ($webhook->type === 'json')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">New signing secret <span class="font-normal text-gray-400">leave empty to keep</span></label>
                                        <input type="text" wire:model="edit.secret" class="{{ $input }} font-mono text-xs" autocomplete="off">
                                        @error('edit.secret') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                        @if ($webhook->signed)
                                            <label class="mt-1.5 inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                                <input type="checkbox" wire:model="edit.clear_secret" class="rounded border-gray-300 dark:border-gray-600 text-accent-500 focus:ring-0">
                                                Stop signing
                                            </label>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <fieldset>
                                <legend class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Events</legend>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                                    @foreach ($events as $event)
                                        <label class="inline-flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                                            <input type="checkbox" value="{{ $event }}" wire:model="edit.events" class="rounded border-gray-300 dark:border-gray-600 text-accent-500 focus:ring-0">
                                            <code>{{ $event }}</code>
                                        </label>
                                    @endforeach
                                </div>
                                @error('edit.events') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </fieldset>
                            <div class="flex flex-wrap justify-end gap-2">
                                <button type="button" wire:click="cancel" class="{{ $button }}">Cancel</button>
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium rounded-md bg-accent-500 text-white hover:bg-accent-600">Save</button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</x-pulse-boosted::card>
