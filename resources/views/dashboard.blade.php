{{--
    Grouped into sections, each with a heading the sidebar links to. Within a
    section every row adds up to 12, so no card is left hanging half a row out
    of line. Cards load as they scroll into view and only poll while visible,
    so a long page costs no more than the part of it on screen.
--}}
<x-pulse>
    <x-pulse-boosted::section id="overview" title="Overview" />
    <livewire:pulse-boosted.overview />
    <livewire:pulse-boosted.alerts cols="full" expand />

    <x-pulse-boosted::section id="servers" title="Servers" />
    <livewire:pulse-boosted.servers cols="full" />

    <x-pulse-boosted::section id="queues" title="Queues & jobs" />
    <livewire:pulse-boosted.queue-status cols="6" />
    <livewire:pulse-boosted.workers cols="6" />
    <livewire:pulse-boosted.jobs cols="full" rows="2" />
    <livewire:pulse-boosted.queues cols="6" />
    <livewire:pulse-boosted.slow-jobs cols="6" />

    <x-pulse-boosted::section id="traces" title="Traces" />
    <livewire:pulse-boosted.traces cols="full" rows="2" />
    <livewire:pulse-boosted.commands cols="6" />
    <livewire:pulse-boosted.scheduled-tasks cols="6" />

    <x-pulse-boosted::section id="errors" title="Errors & logs" />
    <livewire:pulse-boosted.issues cols="full" />
    <livewire:pulse-boosted.logs cols="full" rows="2" />

    <x-pulse-boosted::section id="performance" title="Performance" />
    <livewire:pulse-boosted.slow-requests cols="6" />
    <livewire:pulse-boosted.slow-queries cols="6" />
    <livewire:pulse-boosted.slow-outgoing-requests cols="6" />
    <livewire:pulse-boosted.cache cols="6" />

    <x-pulse-boosted::section id="delivery" title="Mail & notifications" />
    <livewire:pulse-boosted.mail cols="6" />
    <livewire:pulse-boosted.notifications cols="6" />

    <x-pulse-boosted::section id="users" title="Users" />
    <livewire:pulse-boosted.usage cols="full" />
</x-pulse>
