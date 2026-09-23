<div>
    @if ($users !== [] || $user !== '')
        <label class="sr-only" for="pulse-boosted-user-filter">User</label>
        <select
            id="pulse-boosted-user-filter"
            wire:model.live="user"
            @class([
                'max-w-[11rem] rounded-md border pl-2 pr-7 py-1 text-xs shadow-none focus:ring-0 truncate',
                'border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-300' => $user === '',
                'border-accent-300 dark:border-accent-500/50 bg-accent-50 dark:bg-accent-500/10 text-accent-700 dark:text-accent-300 font-medium' => $user !== '',
            ])
            title="Narrow traces, logs and issues to one user"
        >
            <option value="">All users</option>
            @foreach ($users as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    @endif
</div>
