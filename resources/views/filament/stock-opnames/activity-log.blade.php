<div
    x-data="{
        search: '',
        page: 1,
        perPage: 10,
        sortColumn: 'created_at_raw',
        sortDirection: 'desc',
        activities: @js($activities),
        get filteredActivities() {
            const term = this.search.toLowerCase();

            return this.activities.filter((activity) => ! term || [
                activity.created_at,
                activity.action_label,
                activity.pic,
                activity.status_from,
                activity.status_to,
                activity.comment,
            ].some((value) => String(value ?? '').toLowerCase().includes(term)));
        },
        get sortedActivities() {
            const activities = [...this.filteredActivities];

            return activities.sort((first, second) => {
                const firstValue = this.sortColumn === 'created_at_raw' ? first.created_at_raw : String(first[this.sortColumn] ?? '').toLowerCase();
                const secondValue = this.sortColumn === 'created_at_raw' ? second.created_at_raw : String(second[this.sortColumn] ?? '').toLowerCase();
                const result = firstValue < secondValue ? -1 : (firstValue > secondValue ? 1 : 0);

                return this.sortDirection === 'asc' ? result : -result;
            });
        },
        get paginatedActivities() {
            const start = (this.page - 1) * this.perPage;

            return this.sortedActivities.slice(start, start + this.perPage);
        },
        get totalPages() {
            return Math.max(1, Math.ceil(this.sortedActivities.length / this.perPage));
        },
        sort(column) {
            this.sortDirection = this.sortColumn === column && this.sortDirection === 'asc' ? 'desc' : 'asc';
            this.sortColumn = column;
        },
    }"
    x-init="$watch('search', () => page = 1); $watch('sortedActivities', () => { if (page > totalPages) page = totalPages || 1; })"
    class="space-y-3"
>
    <div class="flex items-center justify-between gap-3">
        <label class="fi-input-wrp w-full max-w-sm">
            <span class="sr-only">Search activities</span>
            <input class="fi-input w-full" type="search" x-model.debounce.300ms="search" placeholder="Search activities...">
        </label>
        <span class="text-sm text-gray-500"><span x-text="sortedActivities.length"></span> activities</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="cursor-pointer px-4 py-2" @click="sort('created_at_raw')">Timestamp</th>
                    <th class="cursor-pointer px-4 py-2" @click="sort('action_label')">Action</th>
                    <th class="cursor-pointer px-4 py-2" @click="sort('pic')">PIC</th>
                    <th class="cursor-pointer px-4 py-2" @click="sort('status_to')">Status Transition</th>
                    <th class="cursor-pointer px-4 py-2" @click="sort('comment')">Comment</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <template x-for="activity in paginatedActivities" :key="activity.created_at_raw + activity.action + activity.pic">
                    <tr :class="activity.row_bg">
                        <td class="px-4 py-2.5 text-xs tabular-nums text-gray-500 dark:text-gray-400" x-text="activity.created_at"></td>
                        <td class="px-4 py-2.5 font-medium" :class="activity.action_color" x-text="activity.action_label || activity.action"></td>
                        <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400" x-text="activity.pic"></td>
                        <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-400">
                            <span x-text="activity.status_from || '-'">-</span>
                            <span x-show="activity.status_from && activity.status_to"> -> </span>
                            <span x-text="activity.status_to || '-'">-</span>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400" x-text="activity.comment || '-'" ></td>
                    </tr>
                </template>
                <tr x-show="paginatedActivities.length === 0">
                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">No activities found.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div x-show="sortedActivities.length > perPage" class="flex items-center justify-between">
        <span class="text-sm text-gray-500">
            Showing <span x-text="sortedActivities.length ? ((page - 1) * perPage + 1) : 0"></span>
            to <span x-text="Math.min(page * perPage, sortedActivities.length)"></span>
            of <span x-text="sortedActivities.length"></span> results
        </span>
        <div class="flex gap-2">
            <button class="fi-btn fi-size-sm" type="button" :disabled="page === 1" @click="page = Math.max(1, page - 1)">Previous</button>
            <button class="fi-btn fi-size-sm" type="button" :disabled="page === totalPages" @click="page = Math.min(totalPages, page + 1)">Next</button>
        </div>
    </div>
</div>
