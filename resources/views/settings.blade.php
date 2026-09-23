@use('Elazaroo\PulseBoosted\Settings\Schema')
{{--
    Everything the dashboard lets you change without a deploy. Each section
    saves on its own, and what is saved here wins over config and .env.
--}}
<x-pulse title="Settings" nav="Settings">
    @foreach (Schema::GROUPS as $group => [$title, $description])
        <x-pulse-boosted::section :id="$group" :title="$title" />
        @if ($group === 'webhooks')
            <livewire:pulse-boosted.webhook-destinations cols="full" />
        @endif
        <livewire:pulse-boosted.settings :group="$group" cols="full" :key="'settings-'.$group" />
    @endforeach
</x-pulse>
