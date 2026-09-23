{{--
    Ctrl+K, Cmd+K or / anywhere on the page. Arrow keys move, Enter opens, and
    every result is a link, so it can also be opened in a new tab.
--}}
<div
    x-data="{
        open: false,
        active: 0,
        show() {
            this.open = true
            this.active = 0
            this.$nextTick(() => this.$refs.input.focus())
        },
        results() {
            return [...document.querySelectorAll('[data-pulse-search-result]')]
        },
        move(by) {
            const results = this.results()
            if (results.length === 0) return
            this.active = (this.active + by + results.length) % results.length
            results[this.active].scrollIntoView({ block: 'nearest' })
        },
        go() {
            this.results()[this.active]?.click()
        },
    }"
    x-on:keydown.window.ctrl.k.prevent="show()"
    x-on:keydown.window.meta.k.prevent="show()"
    x-on:keydown.window.slash="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable) { $event.preventDefault(); show() }"
    x-on:open-search.window="show()"
>
    <button
        type="button"
        x-on:click="show()"
        class="flex items-center gap-2 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-2 py-1 text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
        aria-label="Search"
    >
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5"><circle cx="9" cy="9" r="6"/><path d="m14 14 4 4" stroke-linecap="round"/></svg>
        <span class="hidden md:inline">Search</span>
        <kbd class="hidden md:inline rounded border border-gray-200 dark:border-gray-700 px-1 font-sans text-[10px]">Ctrl K</kbd>
    </button>

    @teleport('body')
        <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[12vh]" x-on:keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-gray-900/40 dark:bg-black/60" x-on:click="open = false"></div>
            <div class="relative w-full max-w-xl overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-2xl">
                <div class="flex items-center gap-2 border-b border-gray-200 dark:border-gray-800 px-3">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 text-gray-400 shrink-0"><circle cx="9" cy="9" r="6"/><path d="m14 14 4 4" stroke-linecap="round"/></svg>
                    <input
                        x-ref="input"
                        type="search"
                        wire:model.live.debounce.250ms="query"
                        x-on:input="active = 0"
                        x-on:keydown.arrow-down.prevent="move(1)"
                        x-on:keydown.arrow-up.prevent="move(-1)"
                        x-on:keydown.enter.prevent="go()"
                        placeholder="Issues, traces, jobs, routes, users…"
                        class="w-full border-0 bg-transparent py-3 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:ring-0"
                        autocomplete="off"
                    >
                    <span wire:loading class="text-[11px] text-gray-400">…</span>
                </div>

                <div class="max-h-[60vh] overflow-y-auto">
                    @if (mb_strlen(trim($query)) < 2)
                        <p class="px-4 py-6 text-center text-xs text-gray-500 dark:text-gray-400">
                            Type an exception, a route, a job, a user's name or email, or paste a trace or job id.
                        </p>
                    @elseif ($results === [])
                        <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Nothing matches “{{ $query }}”.</p>
                    @else
                        <ul class="py-1">
                            @foreach ($results as $i => $result)
                                <li wire:key="result-{{ md5($result['url'].$result['title']) }}">
                                    <a
                                        href="{{ $result['url'] }}"
                                        data-pulse-search-result
                                        x-on:mouseenter="active = {{ $i }}"
                                        :class="active === {{ $i }} ? 'bg-accent-50 dark:bg-accent-500/10' : ''"
                                        class="flex items-center gap-3 px-4 py-2"
                                    >
                                        <span class="w-24 shrink-0 text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $result['kind'] }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-mono text-xs text-gray-900 dark:text-gray-100">{{ $result['title'] }}</span>
                                            @if ($result['detail'])
                                                <span class="block truncate text-[11px] text-gray-500 dark:text-gray-400">{{ $result['detail'] }}</span>
                                            @endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="flex items-center gap-3 border-t border-gray-200 dark:border-gray-800 px-4 py-2 text-[11px] text-gray-400">
                    <span><kbd class="font-sans">↑↓</kbd> move</span>
                    <span><kbd class="font-sans">Enter</kbd> open</span>
                    <span><kbd class="font-sans">Esc</kbd> close</span>
                </div>
            </div>
        </div>
    @endteleport
</div>
