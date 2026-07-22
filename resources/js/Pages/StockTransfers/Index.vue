<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, inject } from 'vue';

const t = inject('t');

const props = defineProps({
    transfers: { type: Object, default: () => ({ data: [], links: [] }) },
    filters:   { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? '');

function doSearch() {
    router.get(route('stock-transfers.index'), { search: search.value }, { preserveState: true, replace: true });
}

function fmt(v) {
    return Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function fmtDate(d) {
    return d ? new Date(d).toLocaleDateString('en-LK') : '—';
}
</script>

<template>
    <Head title="Stock Transfers" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-800">ස්ටොක් මාරු කිරීම් / Stock Transfers</h2>
                <Link :href="route('stock-transfers.create')"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                    style="background:#0F766E;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    නව මාරු කිරීම
                </Link>
            </div>
        </template>

        <div class="py-6 max-w-5xl mx-auto px-4">
            <!-- Search -->
            <div class="mb-4">
                <input
                    v-model="search"
                    @keydown.enter="doSearch"
                    type="text"
                    placeholder="Transfer No හෝ ගමනාන්තය සොයන්න..."
                    class="w-full max-w-sm rounded-lg border border-slate-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400"
                />
            </div>

            <!-- Table -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase bg-slate-50 border-b border-slate-100">
                            <th class="px-4 py-3 text-left">Transfer No</th>
                            <th class="px-4 py-3 text-left">ගමනාන්තය</th>
                            <th class="px-4 py-3 text-left">දිනය</th>
                            <th class="px-4 py-3 text-center">අයිතම</th>
                            <th class="px-4 py-3 text-left">කැෂියර්</th>
                            <th class="px-4 py-3 text-right">ක්‍රියා</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-if="transfers.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">මාරු කිරීම් නොමැත</td>
                        </tr>
                        <tr v-for="t in transfers.data" :key="t.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-teal-700">{{ t.transfer_no }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ t.destination }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ fmtDate(t.transfer_date) }}</td>
                            <td class="px-4 py-3 text-center text-slate-600">{{ t.total_items }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ t.user?.name }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="route('stock-transfers.show', t.id)"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-slate-200 hover:border-teal-400 hover:text-teal-700 transition-colors">
                                    බලන්න
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="transfers.links?.length > 3" class="px-4 py-3 border-t border-slate-100 flex gap-1 flex-wrap">
                    <Link
                        v-for="link in transfers.links" :key="link.label"
                        :href="link.url ?? '#'"
                        v-html="link.label"
                        class="px-3 py-1 text-xs rounded border transition-colors"
                        :class="link.active ? 'bg-teal-600 text-white border-teal-600' : 'border-slate-200 text-slate-600 hover:border-teal-400'"
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
