<div class="flex items-center p-0.5 rounded-md bg-gray-100 dark:bg-gray-900 border border-gray-200/80 dark:border-gray-800"
    x-data="{
        setPeriod(period) {
            let query = new URLSearchParams(window.location.search)
            if (period === '1_hour') {
                query.delete('period')
            } else {
                query.set('period', period)
            }

            window.location = `${location.pathname}?${query}`
        }
    }"
>
    <button @click="setPeriod('1_hour')" class="px-2.5 py-1 rounded text-xs font-medium tabular-nums transition-colors {{ $period === '1_hour' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' }}">1h</button>
    <button @click="setPeriod('6_hours')" class="px-2.5 py-1 rounded text-xs font-medium tabular-nums transition-colors {{ $period === '6_hours' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' }}">6h</button>
    <button @click="setPeriod('24_hours')" class="px-2.5 py-1 rounded text-xs font-medium tabular-nums transition-colors {{ $period === '24_hours' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' }}">24h</button>
    <button @click="setPeriod('7_days')" class="px-2.5 py-1 rounded text-xs font-medium tabular-nums transition-colors {{ $period === '7_days' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' }}">7d</button>
</div>
