@props(['timeline' => [], 'value' => 'count', 'failed' => null, 'unit' => '', 'label' => null])
{{--
    A small bar chart of a timeline, oldest on the left. Plain elements rather
    than a canvas: it draws with the page, costs no script, and each bar says
    what it is on hover.
--}}
@php
    $max = max(1, ...array_map(fn ($bucket) => (int) ($bucket[$value] ?? 0), $timeline ?: [['x' => 0]]));
@endphp
<div {{ $attributes }}>
    @if ($label)
        <div class="flex items-baseline justify-between mb-1.5">
            <h3 class="text-xs text-gray-500 uppercase">{{ $label }}</h3>
            <span class="text-[11px] text-gray-400 tabular-nums">max {{ number_format($max) }}{{ $unit }}</span>
        </div>
    @endif
    <div class="flex items-end gap-px h-20 rounded-md bg-gray-50 dark:bg-gray-800/40 px-1 pt-1">
        @foreach ($timeline as $bucket)
            @php
                $amount = (int) ($bucket[$value] ?? 0);
                $bad = $failed === null ? 0 : (int) ($bucket[$failed] ?? 0);
                $height = $amount === 0 ? 0 : max(4, $amount / $max * 100);
                $badShare = $amount === 0 ? 0 : min(100, $bad / max(1, (int) ($bucket['count'] ?? $amount)) * 100);
            @endphp
            <div
                class="flex-1 flex flex-col justify-end h-full group"
                title="{{ \Carbon\CarbonImmutable::createFromTimestamp($bucket['start'])->format('M j, H:i') }}: {{ number_format($amount) }}{{ $unit }}{{ $bad > 0 ? ', '.number_format($bad).' failed' : '' }}"
            >
                <div class="w-full rounded-t-sm overflow-hidden flex flex-col-reverse bg-accent-400/70 dark:bg-accent-500/60 group-hover:bg-accent-500" style="height: {{ $height }}%">
                    @if ($badShare > 0)
                        <div class="w-full bg-red-500" style="height: {{ $badShare }}%"></div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    @if ($timeline !== [])
        <div class="flex justify-between mt-1 text-[10px] text-gray-400 tabular-nums">
            <span>{{ \Carbon\CarbonImmutable::createFromTimestamp($timeline[0]['start'])->format('M j, H:i') }}</span>
            <span>now</span>
        </div>
    @endif
</div>
