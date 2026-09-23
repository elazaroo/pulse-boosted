@props(['cols' => 6, 'rows' => 1])
<section
    {{ $attributes->merge(['class' => "@container flex flex-col p-4 sm:p-5 bg-white dark:bg-gray-900/60 text-gray-900 dark:text-gray-100 rounded-lg border border-gray-200/80 dark:border-gray-800 shadow-[0_1px_2px_rgba(0,0,0,0.04)] default:col-span-full default:lg:col-span-{$cols} default:row-span-{$rows}"]) }}
    x-data="{
        loading: false,
        init() {
            @if (isset($_instance))
                Livewire.hook('commit', ({ component, succeed }) => {
                    if (component.id === $wire.__instance.id) {
                        succeed(() => this.loading = false)
                    }
                })
            @endif
        }
    }"
>
    {{ $slot }}
</section>
