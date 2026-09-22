@use('Carbon\CarbonImmutable')

<div class="col-span-full" @if ($status && ! $status->isFinished()) wire:poll.3s @endif>
    <a href="{{ route('pulse-boosted.queues') }}" class="inline-block mb-4 text-xs font-medium text-[#7A5AF8] hover:underline">&larr; Back to queues</a>

    @if ($job === null)
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-900/5 p-8 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No record of this job. It may have been trimmed, or it was queued before recording was switched on.
            </p>
        </div>
    @else
        {{-- Header --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-900/5 p-6 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-bold text-gray-900 dark:text-gray-100 truncate">{{ $job->name }}</h1>
                        <span @class([
                            'px-2 py-0.5 rounded-full text-xs font-medium',
                            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => in_array($job->status, ['queued', 'released']),
                            'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300' => $job->status === 'processing',
                            'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' => $job->status === 'processed',
                            'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => in_array($job->status, ['failed', 'timed_out']),
                        ])>{{ $status?->label() ?? $job->status }}</span>
                    </div>
                    <code class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $job->uuid }}</code>
                </div>

                @if ($canManage)
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="retry"
                            @disabled(! $canAct)
                            @if (! $canAct) title="Only a job still in the failed jobs table can be retried." @endif
                            wire:confirm="Push this job back onto its queue?"
                            class="px-3 py-1.5 text-xs font-medium rounded-md border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-40"
                        >Retry</button>
                        <button
                            type="button"
                            wire:click="forget"
                            @disabled(! $canAct)
                            wire:confirm="Permanently delete this failed job? This cannot be undone."
                            class="px-3 py-1.5 text-xs font-medium rounded-md border border-red-200 dark:border-red-900 text-red-700 dark:text-red-400 bg-white dark:bg-gray-900 hover:bg-red-50 dark:hover:bg-red-950 disabled:opacity-40"
                        >Delete</button>
                    </div>
                @endif
            </div>

            {{-- Facts --}}
            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
                @foreach ([
                    'Connection' => $job->connection,
                    'Queue' => $job->queue,
                    'Attempts' => $job->max_tries ? $job->attempts.' of '.$job->max_tries : $job->attempts,
                    'Duration' => $job->duration_ms === null ? '—' : number_format($job->duration_ms).' ms',
                    'Class' => $job->class ? class_basename($job->class) : '—',
                    'Timeout' => $job->timeout ? $job->timeout.'s' : '—',
                    'Batch' => $job->batch_id ?: '—',
                    'Backend ID' => $job->job_id ?: '—',
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-gray-500 uppercase">{{ $label }}</dt>
                        <dd class="mt-0.5 text-sm text-gray-900 dark:text-gray-100 truncate" title="{{ $value }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Timeline --}}
        @if ($timeline !== [])
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-900/5 p-6 mb-6">
                <h2 class="text-xs text-gray-500 uppercase mb-4">Timeline</h2>
                <ol class="flex flex-wrap items-center gap-x-8 gap-y-3">
                    @foreach ($timeline as $point)
                        <li class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-[#7A5AF8]"></span>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $point['label'] }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">
                                {{ CarbonImmutable::createFromTimestamp($point['at'])->toDateTimeString() }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- Arguments --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-900/5 p-6 mb-6">
            <h2 class="text-xs text-gray-500 uppercase mb-4">Arguments</h2>
            @if ($arguments === null)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Not captured. Job payloads carry personal data and credentials, so capture is off until you
                    enable <code class="text-xs">capture_payload</code> in the Pulse Boosted configuration.
                </p>
            @else
                <pre class="overflow-x-auto text-xs text-gray-700 dark:text-gray-300 leading-relaxed">{{ json_encode($arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </div>

        {{-- Exception --}}
        @if ($job->exception)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-900/5 p-6">
                <h2 class="text-xs text-gray-500 uppercase mb-1">Exception</h2>
                <p class="mb-4 text-sm font-medium text-red-600 dark:text-red-400">{{ $job->exception_class }}</p>
                <pre class="overflow-x-auto text-xs text-gray-600 dark:text-gray-400 leading-relaxed">{{ $job->exception }}</pre>
            </div>
        @endif
    @endif
</div>
