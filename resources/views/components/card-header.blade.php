@props(['name' => '', 'title' => '', 'details' => null])
<header class="flex flex-wrap justify-between items-center gap-3 mb-4">
    <div class="flex-1 basis-0 flex-grow-[10000] max-w-full">
        <div class="flex overflow-hidden gap-2.5 items-center">
            @if (isset($icon))
                <div class="flex items-center justify-center w-7 h-7 shrink-0 rounded-md bg-gray-100 dark:bg-gray-800 [&>svg]:w-4 [&>svg]:h-4 [&>svg]:stroke-gray-500 [&>svg]:dark:stroke-gray-400">
                    {{ $icon }}
                </div>
            @endif
            <hgroup class="flex flex-wrap items-baseline gap-x-2 overflow-hidden">
                <h2 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100 truncate" @if($attributes->has('x-bind:title')) x-bind:title="{{ $attributes->get('x-bind:title') }}" @else title="{{ $title }}" @endif>{{ $name }}</h2>
                @if ($details)
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $details }}</p>
                @endif
            </hgroup>
        </div>
    </div>
    @if ($actions ?? false)
        <div class="flex flex-grow">
            <div class="w-full flex flex-wrap items-center gap-x-4 gap-y-2">
                {{ $actions }}
            </div>
        </div>
    @endif
</header>
