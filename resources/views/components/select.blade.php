@use('Illuminate\Support\Str')
@props([
    'id' => 'select-'.Str::random(),
    'label',
    'options',
])
<div {{ $attributes->only('class')->merge(['class' => 'flex border border-gray-200 dark:border-gray-800 overflow-hidden rounded-md focus-within:ring-2 focus-within:ring-accent-500/40']) }}>
    <label 
        for="{{ $id }}" class="px-3 flex items-center border-r border-gray-200 dark:border-gray-700 text-xs sm:text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap bg-gray-50 dark:bg-gray-900">{{ $label }}</label>
    <select
        id="{{ $id }}"
        {{ $attributes->except('class') }}
        class="overflow-ellipsis w-full border-0 pl-3 pr-8 py-1 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 text-xs sm:text-sm shadow-none focus:ring-0"
    >
        @foreach ($options as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
</div>
