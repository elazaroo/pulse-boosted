<x-pulse-boosted::card :cols="$cols ?? null" :rows="$rows ?? null" :class="$class ?? ''">
    <div class="h-7 flex items-center gap-2.5 w-full mb-4">
        <div class="rounded-md bg-gray-100 dark:bg-gray-800 h-7 w-7 animate-pulse"></div>
        <div class="rounded bg-gray-100 dark:bg-gray-800 h-4 w-1/3 animate-pulse"></div>
    </div>
    <div class="space-y-2 h-56">
        <div class="rounded-md bg-gray-50 dark:bg-gray-800/60 h-10 animate-pulse"></div>
        <div class="rounded-md bg-gray-50 dark:bg-gray-800/60 h-10 animate-pulse"></div>
        <div class="rounded-md bg-gray-50 dark:bg-gray-800/60 h-10 animate-pulse"></div>
    </div>
</x-pulse-boosted::card>
