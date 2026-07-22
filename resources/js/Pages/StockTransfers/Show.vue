<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { inject } from 'vue';
import { invalidateProducts } from '@/stores/productCache';

const t = inject('t');

const props = defineProps({
    transfer: { type: Object, required: true },
});

function fmtDate(d) {
    return d ? new Date(d).toLocaleDateString('en-LK') : '—';
}

function deleteTransfer() {
    if (!confirm(`"${props.transfer.transfer_no}" රිසිට්පත අවලංගු කරන්නද? ස්ටොක් නැවත අලේවරු කෙරේ.`)) return;
    router.delete(route('stock-transfers.destroy', props.transfer.id), {
        onSuccess: () => invalidateProducts(),
    });
}
</script>

<template>
    <Head :title="transfer.transfer_no" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('stock-transfers.index')" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">{{ transfer.transfer_no }}</h1>
                        <p class="text-sm text-slate-500">{{ transfer.destination }}</p>
                    </div>
                </div>
                <button type="button" @click="deleteTransfer"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    අවලංගු කරන්න / ස්ටොක් ආපසු
                </button>
            </div>
        </template>

        <div class="py-6 max-w-3xl mx-auto px-4 space-y-4">
            <!-- Info cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-white rounded-xl p-4 border border-slate-100 shadow-sm text-center">
                    <p class="text-xs text-slate-500 mb-1">Transfer No</p>
                    <p class="font-bold text-teal-700">{{ transfer.transfer_no }}</p>
                </div>
                <div class="bg-white rounded-xl p-4 border border-slate-100 shadow-sm text-center">
                    <p class="text-xs text-slate-500 mb-1">ගමනාන්තය</p>
                    <p class="font-bold text-slate-800">{{ transfer.destination }}</p>
                </div>
                <div class="bg-white rounded-xl p-4 border border-slate-100 shadow-sm text-center">
                    <p class="text-xs text-slate-500 mb-1">දිනය</p>
                    <p class="font-bold text-slate-800">{{ fmtDate(transfer.transfer_date) }}</p>
                </div>
                <div class="bg-white rounded-xl p-4 border border-slate-100 shadow-sm text-center">
                    <p class="text-xs text-slate-500 mb-1">කැෂියර්</p>
                    <p class="font-bold text-slate-800">{{ transfer.user?.name }}</p>
                </div>
            </div>

            <!-- Note -->
            <div v-if="transfer.note" class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
                {{ transfer.note }}
            </div>

            <!-- Items -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 bg-slate-50">
                    <h2 class="font-semibold text-slate-700">නිෂ්පාදන ({{ transfer.total_items }})</h2>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase border-b border-slate-100">
                            <th class="px-4 py-3 text-left">නිෂ්පාදනය</th>
                            <th class="px-4 py-3 text-center w-28">ප්‍රමාණය</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="item in transfer.items" :key="item.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-800">{{ item.product_name }}</td>
                            <td class="px-4 py-3 text-center font-semibold text-slate-700">{{ Number(item.qty) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-slate-200 bg-slate-50">
                        <tr>
                            <td class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">මුළු ප්‍රමාණය</td>
                            <td class="px-4 py-3 text-center font-bold text-slate-800">
                                {{ transfer.items?.reduce((s, i) => s + Number(i.qty), 0) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
