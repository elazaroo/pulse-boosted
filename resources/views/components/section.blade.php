@props(['id', 'title', 'description' => null])
{{--
    A heading that spans the grid, and owns the cards after it up to the next
    one. The navigation is built from these, and only the current section is
    shown, so adding one to a published dashboard is all it takes to give it a
    page of its own.
--}}
{{-- No id: the address names the section, and a real anchor would make the browser scroll past the navigation to it. --}}
<div data-pulse-section="{{ $title }}" data-pulse-section-id="{{ $id }}" class="col-span-full flex items-end justify-between gap-4 pt-4 first:pt-0">
    <div>
        <h2 class="text-sm font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $title }}</h2>
        @if ($description)
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>
</div>
