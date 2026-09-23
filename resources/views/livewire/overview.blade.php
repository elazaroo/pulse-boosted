{{--
    Seven figures that say whether anything needs looking at. A figure is
    tinted when it crosses the line where it probably does, and each one
    links to the section that explains it.
--}}
<div class="col-span-full space-y-2" @if ($stats !== null) wire:poll.15s.visible="" @endif>
@if ($deployment)
    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <span class="inline-flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full bg-accent-500"></span>
            Deployed <code class="font-mono text-gray-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit($deployment->version, 24) }}</code>
            {{ \Carbon\CarbonImmutable::createFromTimestamp($deployment->deployed_at)->diffForHumans() }}
        </span>
        @if ($newSinceDeploy)
            <a href="#errors" class="text-red-600 dark:text-red-400 font-medium hover:underline">{{ $newSinceDeploy }} new {{ \Illuminate\Support\Str::plural('issue', $newSinceDeploy) }} since</a>
        @elseif ($newSinceDeploy === 0)
            <span class="text-emerald-600 dark:text-emerald-400">no new issues since</span>
        @endif
    </p>
@endif
<div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200/80 dark:border-gray-800 bg-gray-200/80 dark:bg-gray-800">
    @if ($stats === null)
        @for ($i = 0; $i < 7; $i++)
            <div class="bg-white dark:bg-gray-900/60 px-4 py-3.5">
                <div class="h-3 w-16 rounded bg-gray-100 dark:bg-gray-800 animate-pulse"></div>
                <div class="mt-2.5 h-6 w-12 rounded bg-gray-100 dark:bg-gray-800 animate-pulse"></div>
                <div class="mt-2 h-2.5 w-20 rounded bg-gray-100 dark:bg-gray-800 animate-pulse"></div>
            </div>
        @endfor
    @else
        @foreach ($stats as $stat)
            @php
                $value = $stat['value'];
                $bad = $value !== null && ($stat['bad'])($value);
                $display = match (true) {
                    $value === null => '—',
                    $stat['format'] === 'percent' => rtrim(rtrim(number_format($value, 1), '0'), '.').'%',
                    $stat['format'] === 'ms' => $value >= 1000 ? rtrim(rtrim(number_format($value / 1000, 2), '0'), '.').'s' : number_format($value).'ms',
                    default => number_format($value),
                };
            @endphp
            <a href="{{ $stat['href'] }}" class="group relative bg-white dark:bg-gray-900/60 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors">
                @if ($bad)
                    <span class="absolute inset-x-0 top-0 h-0.5 bg-red-500"></span>
                @endif
                <p class="text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                <p @class([
                    'mt-1 text-2xl font-semibold tracking-tight tabular-nums',
                    'text-red-600 dark:text-red-400' => $bad,
                    'text-gray-400 dark:text-gray-600' => $value === null,
                    'text-gray-900 dark:text-gray-100' => ! $bad && $value !== null,
                ])>{{ $display }}</p>
                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500 truncate">{{ $stat['hint'] }}</p>
            </a>
        @endforeach
    @endif
</div>
</div>
