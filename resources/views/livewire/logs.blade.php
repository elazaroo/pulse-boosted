@use('Illuminate\Support\Str')


<x-pulse-boosted::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse-boosted::card-header name="Logging" details="{{ number_format($total) }} {{ Str::plural('entry', $total) }}">
        <x-slot:icon>
            <x-pulse-boosted::icons.clipboard />
        </x-slot:icon>
    </x-pulse-boosted::card-header>

    @include('pulse-boosted::livewire.partials.log-stream', ['perPage' => \Elazaroo\PulseBoosted\Livewire\Logs::PER_PAGE])
</x-pulse-boosted::card>
