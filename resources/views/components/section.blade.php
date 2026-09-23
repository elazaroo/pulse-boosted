@props(['id', 'title', 'description' => null])
{{--
    A heading that spans the grid. The sidebar is built from these, so adding
    one to a published dashboard is all it takes to get a link to it.
--}}
<div id="{{ $id }}" data-pulse-section="{{ $title }}" class="col-span-full flex items-end justify-between gap-4 pt-4 first:pt-0">
    <div>
        <h2 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $title }}</h2>
        @if ($description)
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>
</div>
