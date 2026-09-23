@use('Elazaroo\PulseBoosted\Facades\Pulse')
@use('Illuminate\Support\Facades\Route')
@props(['cols' => 12, 'fullWidth' => false, 'title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $title ? $title.' &middot; Pulse Boosted' : 'Pulse Boosted' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500&display=swap" rel="stylesheet" />
        <link href="data:image/x-icon;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAMAAABEpIrGAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAACQFBMVEUAAAD6WlXyWmb2Wl77WlXxWmPqWmzpWmzdWnvhWnfjWnXUWojaWoDTWonMWpHLWpLAWqDCWp7CWp3DWp23Wqu7Wqe6WqisWrm2Wq2xWrPsWWalWsKsWrqkWsSGWumDWu2BWu97Wvd7WvZ6Wvh8WvZ5Wvn4Wlr4WlrxWmPyWmHyWmHwWmTpWm3qWmzqWmzpWm3gWnjhWnjgWnjRWovXWoTXWoTMWpHNWpDNWpDLWpPAWqDBWp/AWqDDWpzEWpvEWpvEWpvDWp23Wqy6Wqi6Wqi6Wqi7Wqe7Wqe6Wqe6WqiwWrWxWrOxWrSrWruxWrSyWrKxWrOwWrSlWsOnWsCnWr+mWsGnWr+oWr6oWr+nWsCaWtCaWtCaWs+aWtCdWs2fWsqeWsqeWsudWsyeWsueWsufWsmfWsqeWsucWs6aWtCaWs+aWtCZWtGUWteVWteXWtSVWtaUWteUWteVWteXWtSVWtaVWteUWteSWtqUWtiUWteUWtePWt6OWt+OWt+OWt+OWt+XWtSMWuKLWuOLWuKMWuGLWuOLWuOKWuSLWuOLWuKNWuCOWt+QWt2DWu2CWu+CWu6EWuuCWu6BWu+BWu+DWu17WvZ7Wvd7Wvd7Wvd7Wvd7Wvd8WvZ6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6Wvh6WvjhWnfXWoPNWo/EWpu7Wqe6WqexWrOnWr+oWr+eWsuUWteLWuOBWu97Wvd6Wvj///+vWn4hAAAAsHRSTlMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA4NFbGpEEL28DVy/l0CpYoU0bUHM3EaNe/+2RoTwHNi0+PzOWz8sQWVj7VhI9beLsJTiI4xZWhmpsvY+Xjk6iZcuQcnaVIMt/JNk9j8xgs289wtuO9ESIeKi2ADWvyXGdjzn/jgjnEVKeZkBrP6/XEKvPA2iMgXgtIVW/74XDrVhwImyqMOGAsBFqm/VNAAAAABYktHRL/T27NVAAAAB3RJTUUH5wsQDgkSyLkAHwAAASpJREFUOMtjYBh2gJFJTZ2ZBY8CVg1NLW1WPArYdHT19Nlxy3NwGmwwNOLkwqmA29hk40ZTHl6cCvjMzDdtsrDkxyYnIGhlbSNka2e/2cFRGJsCESfnLS6irlvd3D08xbDIi0t4eW/z8fXb5h+wLVBSClOBdFDw9u0hoWE7wiO2R8rIoshFRcfExMTG7YxPSExKTklN25mekQkSAoOs7ByG3F1gkJdfsKuwqLiktKy8onIXHFRVM9TU1gFBfUNjU/Pu3S1yrW3tHZ1d3XUQUN/TC7dKvq9/z54JChMn7Zk8ZaqiHKY7laZN3ztjpqLyrL17Z89RweJTVeW5++bNV1ZesHDRYmVVbGGlvGTpsuXKyitWrlqtjDUmlJevWausrLxu/Wpl7ApGNgAAUsxoMcT6INsAAAAldEVYdGRhdGU6Y3JlYXRlADIwMjMtMTEtMTZUMTQ6MDk6MTgrMDA6MDANsDzYAAAAJXRFWHRkYXRlOm1vZGlmeQAyMDIzLTExLTE2VDE0OjA5OjE4KzAwOjAwfO2EZAAAAFd6VFh0UmF3IHByb2ZpbGUgdHlwZSBpcHRjAAB4nOPyDAhxVigoyk/LzEnlUgADIwsuYwsTIxNLkxQDEyBEgDTDZAMjs1Qgy9jUyMTMxBzEB8uASKBKLgDqFxF08kI1lQAAAABJRU5ErkJggg==" rel="icon" type="image/x-icon">

        {!! Pulse::css() !!}
        @livewireStyles

        {!! Pulse::js() !!}
        @livewireScriptConfig
    </head>
    <body class="bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100 font-sans antialiased">
        <div
            class="min-h-screen"
            x-data="{
                sections: [],
                current: null,
                init() {
                    // Built from the page rather than configured, so a published
                    // dashboard with its own sections gets a matching sidebar,
                    // and one without any simply has none.
                    this.sections = [...document.querySelectorAll('[data-pulse-section]')]
                        .map(el => ({ id: el.id, title: el.dataset.pulseSection }))

                    const observer = new IntersectionObserver(entries => {
                        entries.filter(e => e.isIntersecting).forEach(e => this.current = e.target.id)
                    }, { rootMargin: '-10% 0px -80% 0px' })

                    document.querySelectorAll('[data-pulse-section]').forEach(el => observer.observe(el))
                },
            }"
        >
            <header class="sticky top-0 z-30 border-b border-gray-200/80 dark:border-gray-800/80 bg-white/80 dark:bg-gray-950/80 backdrop-blur supports-[backdrop-filter]:bg-white/60 supports-[backdrop-filter]:dark:bg-gray-950/60">
                <div class="{{ $fullWidth ? '' : 'max-w-screen-2xl' }} mx-auto px-4 sm:px-6 h-14 flex items-center justify-between gap-4">
                    <a href="{{ Route::has('pulse-boosted') ? route('pulse-boosted') : url(config('pulse-boosted.path')) }}" class="flex items-center gap-2.5 shrink-0">
                        <span class="relative flex items-center justify-center w-7 h-7 rounded-md bg-gradient-to-br from-accent-500 to-fuchsia-500 shadow-sm shadow-accent-500/30">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-white"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
                        </span>
                        <span class="text-sm font-semibold tracking-tight">Pulse <span class="text-gray-400 dark:text-gray-500 font-medium">Boosted</span></span>
                    </a>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <span class="hidden sm:flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60 animate-ping"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                            </span>
                            Live
                        </span>
                        <livewire:pulse-boosted.period-selector />
                        <x-pulse-boosted::theme-switcher />
                    </div>
                </div>
            </header>

            <div class="{{ $fullWidth ? '' : 'max-w-screen-2xl' }} mx-auto flex">
                <nav
                    x-show="sections.length > 0"
                    x-cloak
                    class="hidden lg:block w-52 shrink-0 border-r border-gray-200/80 dark:border-gray-800/80"
                >
                    <div class="sticky top-14 max-h-[calc(100vh-3.5rem)] overflow-y-auto py-6 px-3">
                        <p class="px-3 mb-2 text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">Dashboard</p>
                        <ul class="space-y-0.5">
                            <template x-for="section in sections" :key="section.id">
                                <li>
                                    <a
                                        :href="'#' + section.id"
                                        x-text="section.title"
                                        class="block px-3 py-1.5 rounded-md text-sm transition-colors"
                                        :class="current === section.id
                                            ? 'bg-accent-50 text-accent-700 font-medium dark:bg-accent-500/10 dark:text-accent-300'
                                            : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-100 dark:hover:bg-gray-800/60'"
                                    ></a>
                                </li>
                            </template>
                        </ul>
                    </div>
                </nav>

                <main class="flex-1 min-w-0 px-4 sm:px-6 pt-6 pb-16">
                    <div {{ $attributes->merge(['class' => "grid default:grid-cols-{$cols} default:gap-4 lg:default:gap-5"]) }}>
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>
    </body>
</html>
