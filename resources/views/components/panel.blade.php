@props(['close' => 'deselect', 'width' => 'max-w-3xl'])
{{--
    A panel over the right of the page, for whatever a row opens. Closed by
    clicking outside it, the cross, or Escape.
--}}
<div x-data x-on:keydown.escape.window="$wire.{{ $close }}()">
    <div class="fixed inset-0 z-40 bg-gray-900/30 dark:bg-black/50" wire:click="{{ $close }}"></div>
    <aside {{ $attributes->merge(['class' => "fixed inset-y-0 right-0 z-50 w-full {$width} overflow-y-auto bg-white dark:bg-gray-900 shadow-xl border-l border-gray-200 dark:border-gray-800"]) }}>
        <div class="sticky top-0 z-10 flex items-start justify-between gap-4 p-5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
            <div class="min-w-0">
                {{ $header }}
            </div>
            <div class="flex items-center gap-2 shrink-0">
                {{ $actions ?? '' }}
                <button type="button" wire:click="{{ $close }}" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" aria-label="Close">&times;</button>
            </div>
        </div>
        <div class="p-5 space-y-6">
            {{ $slot }}
        </div>
    </aside>
</div>
