@php
$friendlySize = function(int $mb, int $precision = 0) {
    if ($mb >= 1024 * 1024) {
        return round($mb / 1024 / 1024, $precision) . 'TB';
    }
    if ($mb >= 1024) {
        return round($mb / 1024, $precision) . 'GB';
    }
    return round($mb, $precision) . 'MB';
};

$cols = ! empty($cols) ? $cols : 'full';
$rows = ! empty($rows) ? $rows : 1;
@endphp

<section
    wire:poll.5s.visible
    x-data="{
        loading: false,
        init() {
            Livewire.hook('commit', ({ component, succeed }) => {
                if (component.id === $wire.__instance.id) {
                    succeed(() => this.loading = false)
                }
            })
        },
    }"
    class="pb-px default:col-span-full default:lg:col-span-{{ $cols }} default:row-span-{{ $rows }} {{ $class }}"
    :class="loading && 'opacity-25 animate-pulse'"
>
    @if ($servers->isNotEmpty())
        {{--
            One row per server that folds as the page narrows: four columns on
            a wide screen, two on a tablet, one on a phone. Each figure carries
            its own label, so a folded row still says what it is.
        --}}
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($servers as $slug => $server)
                <div wire:key="{{ $slug }}-row" @class([
                    'grid gap-x-8 gap-y-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-[minmax(10rem,1fr)_minmax(0,2fr)_minmax(0,2fr)_auto] items-center',
                    'py-3 first:pt-0 last:pb-0' => $servers->count() > 1,
                    'opacity-40 animate-pulse' => ! $server->recently_reported,
                ])>
                    <div class="flex items-center gap-2 min-w-0 sm:col-span-2 xl:col-span-1" title="{{ $server->updated_at->fromNow() }}">
                        @if ($server->recently_reported)
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-green-500 animate-pulse"></span>
                        @else
                            <x-pulse-boosted::icons.signal-slash class="w-4 h-4 shrink-0 stroke-red-500" />
                        @endif
                        <x-pulse-boosted::icons.server class="w-5 h-5 shrink-0 stroke-gray-500 dark:stroke-gray-400" />
                        <span class="text-base font-bold text-gray-600 dark:text-gray-300 truncate" x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`">{{ $server->name }}</span>
                    </div>

                    <div class="flex items-center gap-3 min-w-0">
                        <div class="shrink-0 w-16">
                            <div class="text-[11px] uppercase font-bold text-gray-500 dark:text-gray-400">CPU</div>
                            <div class="text-xl font-bold text-gray-700 dark:text-gray-200 whitespace-nowrap tabular-nums">{{ $server->cpu_current }}%</div>
                        </div>
                        <div
                            wire:ignore
                            class="flex-1 min-w-0 h-9 relative"
                            x-data="cpuChart({
                                slug: '{{ $slug }}',
                                labels: @js($server->cpu->keys()),
                                data: @js($server->cpu->values()),
                            })"
                        >
                            <canvas x-ref="canvas" class="w-full ring-1 ring-gray-900/5 bg-white dark:bg-gray-900 rounded-md shadow-sm"></canvas>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 min-w-0">
                        <div class="shrink-0">
                            <div class="text-[11px] uppercase font-bold text-gray-500 dark:text-gray-400">Memory</div>
                            <div class="whitespace-nowrap tabular-nums">
                                <span class="text-xl font-bold text-gray-700 dark:text-gray-200">{{ $friendlySize($server->memory_current, 1) }}</span>
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">/ {{ $friendlySize($server->memory_total, 1) }}</span>
                            </div>
                        </div>
                        <div
                            wire:ignore
                            class="flex-1 min-w-0 h-9 relative"
                            x-data="memoryChart({
                                slug: '{{ $slug }}',
                                labels: @js($server->memory->keys()),
                                data: @js($server->memory->values()),
                                total: @js($server->memory_total),
                            })"
                        >
                            <canvas x-ref="canvas" class="w-full ring-1 ring-gray-900/5 bg-white dark:bg-gray-900 rounded-md shadow-sm"></canvas>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div class="text-[11px] uppercase font-bold text-gray-500 dark:text-gray-400">Storage</div>
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                            @foreach ($server->storage as $storage)
                                <div wire:key="{{ $slug.'-storage-'.$storage->directory }}" class="flex items-center gap-3" title="Directory: {{ $storage->directory }}">
                                    <div class="whitespace-nowrap tabular-nums">
                                        <span class="text-xl font-bold text-gray-700 dark:text-gray-200">{{ $friendlySize($storage->used) }}</span>
                                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">/ {{ $friendlySize($storage->total) }}</span>
                                    </div>

                                    <div
                                        wire:ignore
                                        x-data="storageChart({
                                            slug: '{{ $slug }}',
                                            directory: '{{ $storage->directory }}',
                                            used: {{ $storage->used }},
                                            total: {{ $storage->total }},
                                        })"
                                    >
                                        <canvas x-ref="canvas" class="h-8 w-8"></canvas>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex items-center gap-3 rounded-lg border border-dashed border-gray-300 dark:border-gray-700 px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400">
            <x-pulse-boosted::icons.server class="w-4 h-4 shrink-0 stroke-gray-400" />
            <span><span class="font-medium text-gray-700 dark:text-gray-300">No servers reporting.</span> Run <code class="font-mono text-xs">php artisan pulse-boosted:check</code> on each server to see its CPU, memory and storage here.</span>
        </div>
    @endif
</section>

@script
<script>
Alpine.data('cpuChart', (config) => ({
    init() {
        let chart = new Chart(
            this.$refs.canvas,
            {
                type: 'line',
                data: {
                    labels: config.labels.map(formatDate),
                    datasets: [
                        {
                            label: 'CPU Percent',
                            borderColor: '#6366f1',
                            borderWidth: 2,
                            borderCapStyle: 'round',
                            data: config.data,
                            pointHitRadius: 10,
                            pointStyle: false,
                            tension: 0.2,
                            spanGaps: false,
                        },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        autoPadding: false,
                    },
                    scales: {
                        x: {
                            display: false,
                            grid: {
                                display: false,
                            },
                        },
                        y: {
                            display: false,
                            min: 0,
                            max: 100,
                            grid: {
                                display: false,
                            },
                        },
                    },
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            mode: 'index',
                            position: 'nearest',
                            intersect: false,
                            callbacks: {
                                title: () => '',
                                label: (context) => `${context.label} - ${context.formattedValue}%`
                            },
                            displayColors: false,
                        },
                    },
                },
            }
        )

        Livewire.on('servers-chart-update', ({ servers }) => {
            if (chart === undefined) {
                return
            }

            if (servers[config.slug] === undefined && chart) {
                chart.destroy()
                chart = undefined
                return
            }

            chart.data.labels = Object.keys(servers[config.slug].cpu).map(formatDate)
            chart.data.datasets[0].data = Object.values(servers[config.slug].cpu)
            chart.update()
        })
    }
}))

Alpine.data('memoryChart', (config) => ({
    init() {
        let chart = new Chart(
            this.$refs.canvas,
            {
                type: 'line',
                data: {
                    labels: config.labels.map(formatDate),
                    datasets: [
                        {
                            label: 'Memory Used',
                            borderColor: '#6366f1',
                            borderWidth: 2,
                            borderCapStyle: 'round',
                            data: config.data,
                            pointHitRadius: 10,
                            pointStyle: false,
                            tension: 0.2,
                            spanGaps: false,
                        },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        autoPadding: false,
                    },
                    scales: {
                        x: {
                            display: false,
                            grid: {
                                display: false,
                            },
                        },
                        y: {
                            display: false,
                            min: 0,
                            max: config.total,
                            grid: {
                                display: false,
                            },
                        },
                    },
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            mode: 'index',
                            position: 'nearest',
                            intersect: false,
                            callbacks: {
                                title: () => '',
                                label: (context) => `${context.label} - ${context.formattedValue} MB`
                            },
                            displayColors: false,
                        },
                    },
                },
            }
        )

        Livewire.on('servers-chart-update', ({ servers }) => {
            if (chart === undefined) {
                return
            }

            if (servers[config.slug] === undefined && chart) {
                chart.destroy()
                chart = undefined
                return
            }

            chart.data.labels = Object.keys(servers[config.slug].memory).map(formatDate)
            chart.data.datasets[0].data = Object.values(servers[config.slug].memory)
            chart.update()
        })
    }
}))

Alpine.data('storageChart', (config) => ({
    init() {
        let chart = new Chart(
            this.$refs.canvas,
            {
                type: 'doughnut',
                data: {
                    labels: ['Used', 'Free'],
                    datasets: [
                        {
                            data: [
                                config.used,
                                config.total - config.used,
                            ],
                            backgroundColor: [
                                '#6366f1',
                                '#a5b4fc40',
                            ],
                            hoverBackgroundColor: [
                                '#6366f1',
                                '#a5b4fc40',
                            ],
                        },
                    ],
                },
                options: {
                    borderWidth: 0,
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            enabled: false,
                            callbacks: {
                                label: (context) => context.formattedValue + ' MB',
                            },
                            displayColors: false,
                        },
                    },
                },
            }
        )

        Livewire.on('servers-chart-update', ({ servers }) => {
            const storage = servers[config.slug]?.storage?.find(storage => storage.directory === config.directory)

            if (chart === undefined) {
                return
            }

            if (storage === undefined && chart) {
                chart.destroy()
                chart = undefined
                return
            }

            chart.data.datasets[0].data = [
                storage.used,
                storage.total - storage.used,
            ]
            chart.update()
        })
    }
}))
</script>
@endscript
