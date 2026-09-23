@use('Elazaroo\PulseBoosted\Facades\Pulse')
@use('Illuminate\Support\Facades\Route')
@props(['cols' => 12, 'fullWidth' => false, 'title' => null, 'nav' => 'Dashboard'])
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
                    // dashboard with its own sections gets matching navigation,
                    // and one without any shows everything, as it always did.
                    const grid = this.$refs.grid
                    const children = [...grid.children]
                    const headings = children.filter(el => el.dataset.pulseSection !== undefined)

                    this.sections = headings.map(el => ({ id: el.dataset.pulseSectionId, title: el.dataset.pulseSection }))

                    if (headings.length === 0) {
                        return
                    }

                    // Each heading owns the cards after it, up to the next one.
                    // Positions rather than elements: a card re-rendering keeps
                    // its place in the grid but not its attributes, so hiding is
                    // done with a stylesheet that picks cards by position.
                    this.positions = {}

                    let section = null

                    children.forEach((el, i) => {
                        if (el.dataset.pulseSection !== undefined) {
                            section = el.dataset.pulseSectionId
                            this.positions[section] = []
                        }

                        if (section !== null) {
                            this.positions[section].push(i + 1)
                        }
                    })

                    this.style = document.createElement('style')
                    document.head.appendChild(this.style)

                    window.addEventListener('hashchange', () => this.show(this.pick(), true))

                    this.show(this.pick(), true)
                },
                // The section in the address, or the one holding whatever a
                // link asked to open, or the first.
                pick() {
                    const hash = decodeURIComponent(window.location.hash.slice(1))

                    if (this.positions[hash]) {
                        return hash
                    }

                    const params = new URLSearchParams(window.location.search)
                    const opens = { job: 'pulse-boosted.jobs', issue: 'pulse-boosted.issues', route: 'pulse-boosted.routes', query: 'pulse-boosted.queries' }

                    for (const [param, name] of Object.entries(opens)) {
                        const el = params.has(param) && this.$refs.grid.querySelector(`[wire\\:name='${name}']`)
                        const heading = el && [...this.$refs.grid.children].indexOf(el.closest('[data-pulse-grid] > *')) + 1

                        const found = heading && Object.keys(this.positions).find(id => this.positions[id].includes(heading))

                        if (found) {
                            return found
                        }
                    }

                    return this.sections[0].id
                },
                // Only the current section is on the page. Cards in the others
                // are display: none, so they neither load nor poll until shown.
                show(id, scroll) {
                    this.current = id

                    const hidden = Object.entries(this.positions)
                        .filter(([section]) => section !== id)
                        .flatMap(([, positions]) => positions)

                    this.style.textContent = hidden.length === 0 ? '' : hidden
                        .map(n => `[data-pulse-grid] > :nth-child(${n})`)
                        .join(',') + ' { display: none !important; }'

                    if (scroll) {
                        window.scrollTo({ top: 0 })
                    }
                },
            }"
        >
            <header class="sticky top-0 z-30 border-b border-gray-200/80 dark:border-gray-800/80 bg-white/80 dark:bg-gray-950/80 backdrop-blur supports-[backdrop-filter]:bg-white/60 supports-[backdrop-filter]:dark:bg-gray-950/60">
                <div class="{{ $fullWidth ? '' : 'max-w-screen-2xl' }} mx-auto px-4 sm:px-6 py-2 sm:py-0 sm:h-14 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <a href="{{ Route::has('pulse-boosted') ? route('pulse-boosted') : url(config('pulse-boosted.path')) }}" class="flex items-center gap-2.5 shrink-0">
                        <span class="relative flex items-center justify-center w-7 h-7 rounded-md bg-gradient-to-br from-accent-500 to-fuchsia-500 shadow-sm shadow-accent-500/30">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-white"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
                        </span>
                        <span class="text-sm font-semibold tracking-tight">Pulse <span class="text-gray-400 dark:text-gray-500 font-medium">Boosted</span></span>
                    </a>

                    <div class="flex flex-wrap items-center justify-end gap-2 sm:gap-3">
                        <span class="hidden sm:flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60 animate-ping"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                            </span>
                            Live
                        </span>
                        <livewire:pulse-boosted.search />
                        <livewire:pulse-boosted.user-filter />
                        <livewire:pulse-boosted.period-selector />
                        @if (Route::has('pulse-boosted.settings'))
                            <a href="{{ route('pulse-boosted.settings') }}" title="Settings" aria-label="Settings" @class([
                                'p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800',
                                'text-accent-500' => $nav === 'Settings',
                                'text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $nav !== 'Settings',
                            ])>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
                            </a>
                        @endif
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
                        @if ($nav !== 'Dashboard')
                            <a href="{{ Route::has('pulse-boosted') ? route('pulse-boosted') : url(config('pulse-boosted.path')) }}" class="flex items-center gap-1.5 px-3 mb-4 text-xs text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">&larr; Dashboard</a>
                        @endif
                        <p class="px-3 mb-2 text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $nav }}</p>
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
                    <div x-show="sections.length > 0" x-cloak class="lg:hidden -mx-4 sm:-mx-6 px-4 sm:px-6 mb-5 overflow-x-auto">
                        <div class="flex gap-1 w-max">
                            <template x-for="section in sections" :key="section.id">
                                <a
                                    :href="'#' + section.id"
                                    x-text="section.title"
                                    class="px-3 py-1.5 rounded-md text-sm whitespace-nowrap transition-colors"
                                    :class="current === section.id
                                        ? 'bg-accent-50 text-accent-700 font-medium dark:bg-accent-500/10 dark:text-accent-300'
                                        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-100 dark:hover:bg-gray-800/60'"
                                ></a>
                            </template>
                        </div>
                    </div>

                    <div x-ref="grid" data-pulse-grid {{ $attributes->merge(['class' => "grid default:grid-cols-{$cols} default:gap-4 lg:default:gap-5"]) }}>
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        <livewire:pulse-boosted.trace-viewer />
        <livewire:pulse-boosted.person-viewer />
    </body>
</html>
