<div x-data="{ search: '', activities: @js($activities), get filtered() { const term = this.search.toLowerCase(); return this.activities.filter(activity => !term || [activity.created_at, activity.action, activity.actor, activity.status_from, activity.status_to, activity.notes].some(value => String(value ?? '').toLowerCase().includes(term))); } }" class="space-y-3">
    <div class="flex items-center justify-between gap-3">
        <label class="fi-input-wrp w-full max-w-sm">
            <span class="sr-only">Search activities</span>
            <input class="fi-input w-full" type="search" x-model.debounce.300ms="search" placeholder="Search activities...">
        </label>
        <span class="text-sm text-gray-500"><span x-text="filtered.length">{{ count($activities) }}</span> activities</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-left text-sm">
            <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr><th class="px-4 py-2">Timestamp</th><th class="px-4 py-2">Action</th><th class="px-4 py-2">PIC</th><th class="px-4 py-2">Status Transition</th><th class="px-4 py-2">Comment</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <template x-for="(activity, index) in filtered" :key="index">
                    <tr>
                        <td class="px-4 py-2.5 text-xs tabular-nums text-gray-500" x-text="activity.created_at"></td>
                        <td class="px-4 py-2.5 font-medium" x-text="activity.action"></td>
                        <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400" x-text="activity.actor"></td>
                        <td class="px-4 py-2.5 text-xs"><span class="rounded bg-gray-100 px-1.5 py-1 dark:bg-gray-800" x-text="activity.status_from"></span> <span class="text-gray-400">&rsaquo;</span> <span class="rounded bg-success-50 px-1.5 py-1 text-success-800 dark:bg-success-900/30 dark:text-success-300" x-text="activity.status_to"></span></td>
                        <td class="px-4 py-2.5 text-xs text-gray-500" x-text="activity.notes"></td>
                    </tr>
                </template>
                <tr x-show="filtered.length === 0"><td colspan="5" class="px-4 py-6 text-center text-gray-500">No activities found.</td></tr>
            </tbody>
        </table>
    </div>
</div>
