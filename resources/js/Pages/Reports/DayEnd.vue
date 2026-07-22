<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { inject, ref, computed } from 'vue';

const t = inject('t');

const props = defineProps({
    summary:         { type: Object, default: () => ({}) },
    byPaymentMethod: { type: Array,  default: () => [] },
    sales:           { type: Array,  default: () => [] },
    installments:    { type: Array,  default: () => [] },
    date:            { type: String, default: '' },
    settings:        { type: Object, default: () => ({}) },
});

const selectedDate = ref(props.date);

function changeDate() {
    router.get(route('reports.day-end'), { date: selectedDate.value }, { preserveScroll: false });
}

function localDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const todayStr = localDate(new Date());

function fmt(v) {
    return 'Rs. ' + Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function saleCost(sale) {
    return (sale.items ?? []).reduce((s, i) => s + parseFloat(i.cost_price || 0) * parseFloat(i.qty || 0), 0);
}
const totalCost = computed(() => props.sales.reduce((s, sale) => s + saleCost(sale), 0));

function planTotalPaid(inst) {
    return (inst.plan?.payments ?? []).reduce((s, p) => s + parseFloat(p.amount_paid || 0), 0);
}
function planBalance(inst) {
    return Math.max(0, (inst.plan?.total ?? 0) - planTotalPaid(inst));
}
const installmentTotalValue   = computed(() => props.installments.reduce((s, i) => s + parseFloat(i.plan?.total || 0), 0));
const installmentTotalPaidSum = computed(() => props.installments.reduce((s, i) => s + planTotalPaid(i), 0));
const installmentBalanceSum   = computed(() => props.installments.reduce((s, i) => s + planBalance(i), 0));
function fmtTime(d) {
    return d ? new Date(d).toLocaleTimeString('en-LK', { hour: '2-digit', minute: '2-digit' }) : '';
}
function now() {
    return new Date().toLocaleTimeString('en-LK', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

const methodLabel = {
    cash:   'මුදල් / Cash',
    card:   'කාඩ් / Card',
    qr:     'QR',
    credit: 'ණය / Credit',
};

async function printReport() {
    const isElectron = typeof window !== 'undefined' && !!window.electronAPI?.isElectron;
    if (isElectron) {
        const printer = localStorage.getItem('pos_printer') || usePage().props.appSettings?.printer_name || '';
        const result  = await window.electronAPI.printReceipt(printer);
        if (!result?.success) window.print();
    } else {
        window.print();
    }
}
</script>

<template>
    <Head title="Day End Report" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3">
                    <Link :href="route('reports.index')" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h1 class="text-xl font-bold" style="color:#0F172A;">දවස් අවසාන වාර්තාව / Day End Report</h1>
                </div>
                <div class="no-print flex items-center gap-3 ml-auto">
                    <!-- Date picker -->
                    <div class="flex items-center gap-2">
                        <button type="button" @click="selectedDate = todayStr; changeDate()"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                            :class="selectedDate === todayStr ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            Today
                        </button>
                        <input
                            v-model="selectedDate"
                            type="date"
                            :max="todayStr"
                            @change="changeDate"
                            class="rounded-lg px-3 py-1.5 text-sm outline-none font-medium"
                            style="border:1px solid #E2E8F0; color:#0F172A;"
                        />
                    </div>
                    <button
                        type="button"
                        @click="printReport"
                        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Print Report
                    </button>
                </div>
            </div>
        </template>

        <!-- Screen view -->
        <div class="no-print grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center">
                <p class="text-xs text-slate-500 mb-1">මුළු ඉන්වොයිස්</p>
                <p class="text-3xl font-bold text-blue-600">{{ summary.total_bills }}</p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center">
                <p class="text-xs text-slate-500 mb-1">මුළු ආදායම (ලැබූ)</p>
                <p class="text-2xl font-bold text-green-600">{{ fmt(summary.total_revenue) }}</p>
                <p v-if="summary.total_billed > summary.total_revenue" class="text-xs font-medium mt-0.5" style="color:#DC2626;">
                    Billed: {{ fmt(summary.total_billed) }}
                </p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center">
                <p class="text-xs text-slate-500 mb-1">ණය ශේෂය (නොගෙවූ)</p>
                <p class="text-2xl font-bold" style="color:#DC2626;">{{ fmt(summary.total_credit) }}</p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center" style="border-color:#FFEDD5;">
                <p class="text-xs text-slate-500 mb-1">වාරික ගෙවීම්</p>
                <p class="text-2xl font-bold" style="color:#EA580C;">{{ fmt(summary.installment_total) }}</p>
                <p class="text-xs text-slate-400 mt-0.5">{{ summary.installment_count }} payments</p>
            </div>
            <!-- Total Daily Income = sales received + installments -->
            <div class="rounded-xl p-4 shadow-sm text-center" style="border:1px solid #C7D2FE; background:linear-gradient(135deg,#EEF2FF 0%,#fff 100%);">
                <p class="text-xs text-slate-500 mb-1">දෛනික මුළු ආදායම</p>
                <p class="text-2xl font-bold" style="color:#3730A3;">{{ fmt(Number(summary.total_revenue) + Number(summary.installment_total)) }}</p>
                <p class="text-xs mt-0.5" style="color:#6366F1;">විකුණුම් + වාරික</p>
            </div>
        </div>

        <!-- Invoice list (full width) -->
        <div class="no-print bg-white rounded-xl shadow-sm border border-slate-100 mb-6">
            <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h2 class="font-semibold text-slate-800">ඉන්වොයිස් ලැයිස්තුව / Invoices
                    <span class="ml-1 text-xs font-normal text-slate-400">({{ sales.length }})</span>
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase bg-slate-50 border-b border-slate-100">
                            <th class="px-4 py-3 text-left">ඉන්වොයිස්</th>
                            <th class="px-4 py-3 text-left">කැෂියර්</th>
                            <th class="px-4 py-3 text-left">ගෙවීම් ක්‍රමය</th>
                            <th class="px-4 py-3 text-right">වේලාව</th>
                            <th class="px-4 py-3 text-right" style="border-left:2px dashed #E2E8F0;">වියදම</th>
                            <th class="px-4 py-3 text-right" style="border-left:2px dashed #E2E8F0;">ඉන්වොයිස් මුදල</th>
                            <th class="px-4 py-3 text-right">ලැබූ</th>
                            <th class="px-4 py-3 text-right">ණය</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="sales.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center text-slate-400">මෙදින විකුණුම් නොමැත</td>
                        </tr>
                        <tr v-for="sale in sales" :key="sale.id" class="hover:bg-slate-50 border-b border-slate-50">
                            <td class="px-4 py-2.5 font-medium text-blue-600">
                                <Link :href="route('sales.show', sale.id)">{{ sale.invoice_no }}</Link>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ sale.user?.name }}</td>
                            <td class="px-4 py-2.5">
                                <span v-for="p in sale.payments" :key="p.id"
                                    class="inline-block text-xs px-1.5 py-0.5 rounded mr-1 capitalize"
                                    :style="p.method === 'cash' ? 'background:#DCFCE7;color:#15803D;' : p.method === 'credit' ? 'background:#FEF2F2;color:#DC2626;' : p.method === 'card' ? 'background:#DBEAFE;color:#1D4ED8;' : 'background:#F3E8FF;color:#7C3AED;'">
                                    {{ p.method }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-slate-400">{{ fmtTime(sale.created_at) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-500" style="border-left:2px dashed #E2E8F0;">{{ fmt(saleCost(sale)) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-700" style="border-left:2px dashed #E2E8F0;">{{ fmt(sale.total) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-green-600">{{ fmt(Math.min(Number(sale.paid), Number(sale.total))) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <span v-if="sale.balance > 0" class="font-semibold" style="color:#DC2626;">{{ fmt(sale.balance) }}</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="sales.length > 0" class="border-t-2 border-slate-200">
                        <tr class="bg-slate-50 font-semibold">
                            <td colspan="4" class="px-4 py-2.5 text-slate-500 text-xs uppercase">එකතුව</td>
                            <td class="px-4 py-2.5 text-right text-slate-600 font-semibold" style="border-left:2px dashed #E2E8F0;">{{ fmt(totalCost) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-700" style="border-left:2px dashed #E2E8F0;">{{ fmt(summary.total_billed) }}</td>
                            <td class="px-4 py-2.5 text-right text-green-600">{{ fmt(summary.total_revenue) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color:#DC2626;">{{ summary.total_credit > 0 ? fmt(summary.total_credit) : '—' }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Installment collections (full width, always shown) -->
        <div class="no-print bg-white rounded-xl shadow-sm mb-6" style="border:1px solid #FFEDD5;">
            <div class="px-4 py-3 border-b flex items-center gap-2" style="border-color:#FED7AA; background:#FFF7ED;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="#EA580C">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <h2 class="font-semibold text-sm" style="color:#C2410C;">
                    වාරික ගෙවීම් / Installment Collections
                    <span class="font-normal text-xs ml-1" style="color:#EA580C;">({{ installments.length }})</span>
                </h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase bg-orange-50 border-b border-orange-100">
                            <th class="px-4 py-3 text-left">Plan No</th>
                            <th class="px-4 py-3 text-left">Customer</th>
                            <th class="px-4 py-3 text-center">Installment #</th>
                            <th class="px-4 py-3 text-center">Due Date</th>
                            <th class="px-4 py-3 text-center">Method</th>
                            <th class="px-4 py-3 text-right" style="border-left:2px dashed #FED7AA;">Total Value</th>
                            <th class="px-4 py-3 text-right">Total Paid</th>
                            <th class="px-4 py-3 text-right">Balance Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="installments.length === 0">
                            <td colspan="10" class="px-4 py-10 text-center text-slate-400">මෙදින වාරික ගෙවීම් නොමැත</td>
                        </tr>
                        <tr v-for="inst in installments" :key="inst.id" class="hover:bg-orange-50 border-b border-slate-50">
                            <td class="px-4 py-2.5 font-medium text-orange-700">{{ inst.plan?.plan_no }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ inst.plan?.customer?.name }}</td>
                            <td class="px-4 py-2.5 text-center text-slate-500">
                                {{ inst.installment_no === 0 ? 'Down Pmt' : '#' + inst.installment_no }}
                            </td>
                            <td class="px-4 py-2.5 text-center text-slate-400 text-xs">{{ inst.due_date ? new Date(inst.due_date).toLocaleDateString('en-LK') : '—' }}</td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-block text-xs px-1.5 py-0.5 rounded capitalize"
                                    style="background:#DCFCE7;color:#15803D;">
                                    {{ inst.payment_method || '—' }}
                                </span>
                            </td>
                            <!-- Plan-level summary -->
                            <td class="px-4 py-2.5 text-right text-slate-700 font-medium" style="border-left:2px dashed #FED7AA;">{{ fmt(inst.plan?.total) }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-green-700">{{ fmt(planTotalPaid(inst)) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold"
                                :style="planBalance(inst) > 0 ? 'color:#DC2626;' : 'color:#94A3B8;'">
                                {{ fmt(planBalance(inst)) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="installments.length > 0" class="border-t-2 border-orange-200">
                        <tr class="bg-orange-50 font-semibold">
                            <td colspan="5" class="px-4 py-2.5 text-xs uppercase" style="color:#EA580C;">එකතුව</td>
                            <td class="px-4 py-2.5 text-right text-slate-700" style="border-left:2px dashed #FED7AA;">{{ fmt(installmentTotalValue) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color:#16A34A;">{{ fmt(installmentTotalPaidSum) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color:#DC2626;">{{ fmt(installmentBalanceSum) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ══════════════════════════════════════════
             THERMAL RECEIPT (print only)
        ═══════════════════════════════════════════ -->
        <div id="day-end-receipt" class="receipt-root">
            <!-- Header -->
            <div class="receipt-center">
                <p class="receipt-shop-name">{{ settings.shop_name || 'LMUC POS' }}</p>
                <p v-if="settings.shop_address" class="receipt-small">{{ settings.shop_address }}</p>
                <p v-if="settings.shop_phone" class="receipt-small">Tel: {{ settings.shop_phone }}</p>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-center">
                <p style="font-size:12px;font-weight:bold;letter-spacing:1px;">*** දවස් අවසාන වාර්තාව ***</p>
                <p style="font-size:12px;font-weight:bold;letter-spacing:1px;">*** DAY END REPORT ***</p>
            </div>

            <div class="receipt-divider"></div>

            <!-- Date / time -->
            <table class="receipt-table">
                <tr><td class="receipt-label">දිනය / Date</td><td class="receipt-value">: {{ date }}</td></tr>
                <tr><td class="receipt-label">ජනනය / Generated</td><td class="receipt-value">: {{ now() }}</td></tr>
            </table>

            <div class="receipt-divider"></div>

            <!-- Summary -->
            <table class="receipt-table">
                <tr><td class="receipt-label">මුළු ඉන්වොයිස්</td><td class="receipt-value text-right">: {{ summary.total_bills }}</td></tr>
            </table>

            <div class="receipt-divider-dashed"></div>

            <!-- Payment breakdown -->
            <p style="font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;margin:4px 0 2px;">ගෙවීම් විස්තරය / Payment Breakdown</p>
            <div v-if="byPaymentMethod.length === 0" style="font-size:11px;color:#666;text-align:center;padding:4px 0;">අද විකුණුම් නොමැත</div>
            <table v-else class="receipt-table">
                <tr v-for="pm in byPaymentMethod" :key="pm.method">
                    <td class="receipt-label">{{ methodLabel[pm.method] || pm.method }} ({{ pm.count }})</td>
                    <td class="receipt-value" style="text-align:right;">{{ fmt(pm.total) }}</td>
                </tr>
            </table>

            <div class="receipt-divider-dashed"></div>

            <!-- Totals -->
            <table class="receipt-table">
                <tr v-if="Number(summary.total_discount) > 0">
                    <td class="receipt-label">මුළු වට්ටම</td>
                    <td class="receipt-value" style="text-align:right;">- {{ fmt(summary.total_discount) }}</td>
                </tr>
                <tr v-if="Number(summary.total_credit) > 0">
                    <td class="receipt-label">ණය ශේෂය (නොගෙවූ)</td>
                    <td class="receipt-value" style="text-align:right;">{{ fmt(summary.total_credit) }}</td>
                </tr>
            </table>

            <div class="receipt-divider"></div>

            <!-- Grand total (sales) -->
            <div class="receipt-grand-total">
                <span>මුළු ආදායම (ලැබූ)</span>
                <span>{{ fmt(summary.total_revenue) }}</span>
            </div>
            <div v-if="summary.total_billed > summary.total_revenue" style="display:flex;justify-content:space-between;font-size:11px;padding:1px 0;">
                <span>Billed Total</span>
                <span>{{ fmt(summary.total_billed) }}</span>
            </div>

            <!-- Installments on receipt -->
            <div v-if="summary.installment_total > 0">
                <div class="receipt-divider-dashed"></div>
                <p style="font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;margin:4px 0 2px;">වාරික ගෙවීම් / Installments</p>
                <table class="receipt-table">
                    <tr v-for="inst in installments" :key="inst.id">
                        <td class="receipt-label">{{ inst.plan?.plan_no }} — #{{ inst.installment_no }}</td>
                        <td class="receipt-value" style="text-align:right;">{{ fmt(inst.amount_paid) }}</td>
                    </tr>
                </table>
                <div style="display:flex;justify-content:space-between;font-size:12px;padding:2px 0;border-top:1px dashed #666;margin-top:2px;">
                    <span>වාරික එකතුව / Installments Total</span>
                    <span>{{ fmt(summary.installment_total) }}</span>
                </div>
            </div>

            <div class="receipt-divider"></div>

            <!-- Daily total income (sales + installments) -->
            <div style="display:flex;justify-content:space-between;font-size:15px;padding:3px 0;font-weight:900;">
                <span>දෛනික මුළු ආදායම</span>
                <span>{{ fmt(Number(summary.total_revenue) + Number(summary.installment_total)) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:15px;padding:3px 0;font-weight:900;">
                <span>TOTAL DAILY INCOME</span>
                <span>{{ fmt(Number(summary.total_revenue) + Number(summary.installment_total)) }}</span>
            </div>

            <div class="receipt-divider"></div>

            <!-- Invoice list -->
            <p style="font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;margin:4px 0 2px;">ඉන්වොයිස් / Invoices</p>
            <div class="receipt-items-header" style="font-size:9px;">
                <span style="flex:1;">Invoice</span>
                <span style="width:36px;text-align:center;">Time</span>
                <span style="width:64px;text-align:right;">Total</span>
            </div>
            <div class="receipt-divider-dashed"></div>
            <div v-for="sale in sales" :key="sale.id" style="display:flex;font-size:10px;padding:1px 0;">
                <span style="flex:1;">{{ sale.invoice_no }}</span>
                <span style="width:36px;text-align:center;">{{ fmtTime(sale.created_at) }}</span>
                <span style="width:64px;text-align:right;">{{ fmt(sale.total) }}</span>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-center receipt-footer">
                <p class="receipt-small">*** End of Day Report ***</p>
                <p class="receipt-small">{{ settings.shop_name || 'LMUC POS' }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ─── Screen: hide receipt ─────────────────────────────── */
#day-end-receipt {
    display: none;
}

/* ─── Print: show receipt only ─────────────────────────── */
@media print {
    .no-print { display: none !important; }

    #day-end-receipt {
        display: block !important;
    }

    /* Receipt styles */
    .receipt-root {
        width: 100%;
        max-width: 100%;
        font-family: 'Courier New', Courier, monospace;
        font-size: 12px;
        font-weight: 800;
        line-height: 1.5;
        color: #111;
        background: #fff;
        padding: 8px 6px;
        margin: 0 auto;
    }

    /* All text 800 weight */
    .receipt-root * { font-weight: 800 !important; }

    .receipt-center   { text-align: center; margin: 2px 0; }
    .receipt-small    { font-size: 12px; }
    .receipt-label    { color: #333; padding-right: 4px; font-size: 12px; }
    .receipt-value    { font-size: 12px; }

    .receipt-shop-name {
        font-size: 16px;
        letter-spacing: 1px;
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .receipt-divider {
        border-top: 1px solid #333;
        margin: 5px 0;
    }
    .receipt-divider-dashed {
        border-top: 1px dashed #666;
        margin: 3px 0;
    }

    .receipt-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .receipt-table td { padding: 1px 0; }

    .receipt-items-header {
        display: flex;
        font-size: 12px;
        text-transform: uppercase;
    }

    .receipt-grand-total {
        display: flex;
        justify-content: space-between;
        font-size: 15px;
        padding: 3px 0;
    }

    .receipt-footer { margin-top: 4px; }

    @page {
        size: 80mm auto;
        margin: 3mm 3mm 3mm 1mm;
    }
}
</style>
