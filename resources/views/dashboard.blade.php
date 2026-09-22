{{--
    Every row adds up to 12, so the grid never leaves a card hanging half a
    row out of line. Jobs is the one that earns the full width.
--}}
<x-pulse>
    <livewire:pulse-boosted.servers cols="full" />

    <livewire:pulse-boosted.queue-status cols="6" />
    <livewire:pulse-boosted.workers cols="6" />

    <livewire:pulse-boosted.jobs cols="full" rows="2" />

    <livewire:pulse-boosted.traces cols="full" rows="2" />

    <livewire:pulse-boosted.queues cols="6" />
    <livewire:pulse-boosted.exceptions cols="6" />

    <livewire:pulse-boosted.slow-queries cols="6" />
    <livewire:pulse-boosted.slow-requests cols="6" />

    <livewire:pulse-boosted.slow-jobs cols="6" />
    <livewire:pulse-boosted.slow-outgoing-requests cols="6" />

    <livewire:pulse-boosted.cache cols="6" />
    <livewire:pulse-boosted.usage cols="6" />
</x-pulse>
