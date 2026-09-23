<script>
setDarkClass = () => {
    if (localStorage.theme === 'dark' || ((!('theme' in localStorage) || !['light', 'dark'].includes(localStorage.theme)) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark')
    } else {
        document.documentElement.classList.remove('dark')
    }
}

setDarkClass()

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', setDarkClass)
</script>

<div
    class="relative"
    x-data="{
        menu: false,
        theme: localStorage.theme,
        darkMode() {
            this.theme = 'dark'
            localStorage.theme = 'dark'
            setDarkClass()
        },
        lightMode() {
            this.theme = 'light'
            localStorage.theme = 'light'
            setDarkClass()
        },
        systemMode() {
            this.theme = undefined
            localStorage.removeItem('theme')
            setDarkClass()
        },
    }"
    @click.outside="menu = false"
>
    <button
        x-cloak
        class="block p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800"
        :class="theme ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-600 hover:text-gray-500 focus:text-gray-500 dark:hover:text-gray-500 dark:focus:text-gray-500'"
        @click="menu = ! menu"
    >
        <x-pulse-boosted::icons.sun class="block dark:hidden w-4 h-4" />
        <x-pulse-boosted::icons.moon class="hidden dark:block w-4 h-4" />
    </button>

    <div x-show="menu" class="z-40 absolute mt-1 origin-top-right right-0 min-w-[8rem] bg-white dark:bg-gray-900 rounded-md border border-gray-200 dark:border-gray-800 shadow-lg flex flex-col overflow-hidden text-sm" style="display: none;" @click="menu = false">
        <button class="flex items-center px-3 py-1.5 gap-2 hover:bg-gray-100 dark:hover:bg-gray-800" :class="theme === 'light' ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'" @click="lightMode()">
            <x-pulse-boosted::icons.sun class="w-4 h-4" />
            Light
        </button>
        <button class="flex items-center px-3 py-1.5 gap-2 hover:bg-gray-100 dark:hover:bg-gray-800" :class="theme === 'dark' ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'" @click="darkMode()">
            <x-pulse-boosted::icons.moon class="w-4 h-4" />
            Dark
        </button>
        <button class="flex items-center px-3 py-1.5 gap-2 hover:bg-gray-100 dark:hover:bg-gray-800" :class="theme === undefined ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'" @click="systemMode()">
            <x-pulse-boosted::icons.computer-desktop class="w-4 h-4" />
            System
        </button>
    </div>
</div>
