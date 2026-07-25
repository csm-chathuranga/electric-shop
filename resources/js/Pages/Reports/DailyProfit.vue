<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { inject, ref, computed } from 'vue';

const t = inject('t');

const props = defineProps({
    summary:          { type: Object, default: () => ({}) },
    sales:            { type: Array,  default: () => [] },
    installments:     { type: Array,  default: () => [] },
    creditRepayments: { type: Array,  default: () => [] },
    date:             { type: String, default: '' },
});

const selectedDate = ref(props.date);

function localDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const todayStr = localDate(new Date());

function changeDate() {
    router.get(route('reports.daily-profit'), { date: selectedDate.value }, { preserveScroll: false });
}

function fmt(v) {
    return 'Rs. ' + Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function n(v) {
    return Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pct(profit, revenue) {
    if (!revenue) return '—';
    return (profit / revenue * 100).toFixed(1) + '%';
}

function fmtTime(d) {
    return d ? new Date(d).toLocaleTimeString('en-LK', { hour: '2-digit', minute: '2-digit' }) : '';
}

// Sales helpers
function saleCost(sale) {
    return (sale.items ?? []).reduce((s, i) => s + parseFloat(i.cost_price || 0) * parseFloat(i.qty || 0), 0);
}
function saleProfit(sale) {
    return parseFloat(sale.total || 0) - saleCost(sale);
}

const totalRevenue  = computed(() => props.sales.reduce((s, sale) => s + parseFloat(sale.total || 0), 0));
const totalReceived = computed(() => props.sales.reduce((s, sale) => s + Math.min(Number(sale.paid || 0), Number(sale.total || 0)), 0));
const totalCredit   = computed(() => props.sales.reduce((s, sale) => s + Number(sale.balance || 0), 0));
const totalCost     = computed(() => props.sales.reduce((s, sale) => s + saleCost(sale), 0));
const totalProfit   = computed(() => props.sales.reduce((s, sale) => s + saleProfit(sale), 0));

// Installment helpers
function planTotalPaid(inst) {
    return (inst.plan?.payments ?? []).reduce((s, p) => s + parseFloat(p.amount_paid || 0), 0);
}
function planItemCost(inst) {
    return (inst.plan?.items ?? []).reduce((s, i) => s + parseFloat(i.cost_price || 0) * parseFloat(i.qty || 0), 0);
}
function planItemMargin(inst) {
    return (inst.plan?.items ?? []).reduce((s, i) => s + (parseFloat(i.unit_price || 0) - parseFloat(i.cost_price || 0)) * parseFloat(i.qty || 0), 0);
}
function planInterest(inst) {
    return Number(inst.plan?.interest_amount ?? 0);
}
function planProfit(inst) {
    return planItemMargin(inst) + planInterest(inst);
}
function planTotal(inst) {
    return Number(inst.plan?.total ?? 0);
}

// Cash actually received today for this row (last_payment_amount set by backend; fallback for old rows)
function cashToday(inst) {
    return parseFloat(inst.last_payment_amount ?? inst.amount_paid ?? 0);
}

// Proportional values — each payment row earns a share of plan profit proportional to cash received today
function proportional(inst) {
    const total = planTotal(inst);
    const ratio = total > 0 ? cashToday(inst) / total : 0;
    return {
        cost:     planItemCost(inst)   * ratio,
        margin:   planItemMargin(inst) * ratio,
        interest: planInterest(inst)   * ratio,
        profit:   planProfit(inst)     * ratio,
    };
}

// Mark each payment row: first occurrence per plan shows plan-level columns; others show "—"
const annotatedInstallments = computed(() => {
    const seen = new Set();
    return props.installments.map(inst => {
        const pid = inst.plan?.id;
        const isFirst = !!pid && !seen.has(pid);
        if (isFirst) seen.add(pid);
        return { ...inst, _isFirst: isFirst };
    });
});

// Unique plans for footer totals (so plan-level values aren't multiplied)
const uniquePlanRows = computed(() => {
    const map = new Map();
    for (const inst of props.installments) {
        const pid = inst.plan?.id;
        if (pid && !map.has(pid)) map.set(pid, inst);
    }
    return [...map.values()];
});

const instTotalValue   = computed(() => uniquePlanRows.value.reduce((s, i) => s + planTotal(i), 0));
const instTodayTotal   = computed(() => props.installments.reduce((s, i) => s + cashToday(i), 0));
const instTotalPaid    = computed(() => uniquePlanRows.value.reduce((s, i) => s + planTotalPaid(i), 0));
const instTotalCost    = computed(() => props.installments.reduce((s, i) => s + proportional(i).cost, 0));
const instTotalMargin  = computed(() => props.installments.reduce((s, i) => s + proportional(i).margin, 0));
const instTotalInt     = computed(() => props.installments.reduce((s, i) => s + proportional(i).interest, 0));
const instProfit       = computed(() => props.installments.reduce((s, i) => s + proportional(i).profit, 0));

const grandProfit = computed(() => totalProfit.value + instProfit.value); // instProfit is proportional
</script>

<template>
    <Head title="Daily Profit Report" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3">
                    <Link :href="route('reports.index')" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h1 class="text-xl font-bold" style="color:#0F172A;">දෛනික ලාභ වාර්තාව / Daily Profit Report</h1>
                </div>
                <div class="flex items-center gap-2 ml-auto">
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
            </div>
        </template>

        <!-- Summary tiles -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <!-- Revenue -->
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center">
                <p class="text-xs text-slate-500 mb-1">මුළු ආදායම</p>
                <p class="text-2xl font-bold text-blue-600">{{ fmt(totalRevenue) }}</p>
                <p class="text-xs text-slate-400 mt-0.5">{{ sales.length }} invoices</p>
            </div>
            <!-- Cost -->
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center">
                <p class="text-xs text-slate-500 mb-1">මුළු පිරිවැය</p>
                <p class="text-2xl font-bold text-slate-600">{{ fmt(totalCost) }}</p>
            </div>
            <!-- Sales profit -->
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center" style="border-color:#BBF7D0;">
                <p class="text-xs text-slate-500 mb-1">විකුණුම් ලාභය</p>
                <p class="text-2xl font-bold" style="color:#15803D;">{{ fmt(totalProfit) }}</p>
                <p class="text-xs text-slate-400 mt-0.5">{{ pct(totalProfit, totalRevenue) }} margin</p>
            </div>
            <!-- Installment profit -->
            <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100 text-center" style="border-color:#FED7AA;">
                <p class="text-xs text-slate-500 mb-1">වාරික ලාභය</p>
                <p class="text-2xl font-bold" style="color:#EA580C;">{{ fmt(instProfit) }}</p>
                <p class="text-xs text-slate-400 mt-0.5">{{ installments.length }} plans</p>
            </div>
            <!-- Grand profit (highlighted) -->
            <div class="rounded-xl p-4 shadow-lg text-center col-span-2 md:col-span-1" style="background:linear-gradient(135deg,#3730A3 0%,#4F46E5 60%,#6366F1 100%);">
                <p class="text-xs font-semibold uppercase tracking-widest mb-1" style="color:rgba(255,255,255,0.75);">මුළු ලාභය</p>
                <p class="font-black leading-none mb-1" style="color:#fff; font-size:1.75rem;">{{ fmt(grandProfit) }}</p>
                <p class="text-xs px-2 py-0.5 rounded-full inline-block" style="background:rgba(255,255,255,0.15); color:rgba(255,255,255,0.9);">විකුණුම් + වාරික</p>
            </div>
        </div>

        <!-- Sales profit table -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 mb-6">
            <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h2 class="font-semibold text-slate-800">
                    විකුණුම් ලාභ විශ්ලේෂණය / Sales Profit
                    <span class="ml-1 text-xs font-normal text-slate-400">({{ sales.length }})</span>
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase bg-slate-50 border-b border-slate-100">
                            <th class="px-4 py-3 text-left">ඉන්වොයිස්</th>
                            <th class="px-4 py-3 text-left">කැෂියර්</th>
                            <th class="px-4 py-3 text-left">ගෙවීම</th>
                            <th class="px-4 py-3 text-right">වේලාව</th>
                            <th class="px-4 py-3 text-right" style="border-left:2px dashed #E2E8F0;">ඉන්වොයිස් මුදල (Rs.)</th>
                            <th class="px-4 py-3 text-right">ලැබූ මුදල (Rs.)</th>
                            <th class="px-4 py-3 text-right">ණය (Rs.)</th>
                            <th class="px-4 py-3 text-right" style="border-left:2px dashed #E2E8F0;">පිරිවැය (Rs.)</th>
                            <th class="px-4 py-3 text-right" style="border-left:2px dashed #BBF7D0;">ලාභය (Rs.)</th>
                            <th class="px-4 py-3 text-right">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="sales.length === 0">
                            <td colspan="10" class="px-4 py-10 text-center text-slate-400">මෙදින විකුණුම් නොමැත</td>
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
                            <td class="px-4 py-2.5 text-right text-slate-700 font-medium" style="border-left:2px dashed #E2E8F0;">{{ n(sale.total) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-green-700">{{ n(Math.min(Number(sale.paid || 0), Number(sale.total || 0))) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <span v-if="sale.balance > 0" class="font-semibold" style="color:#DC2626;">{{ n(sale.balance) }}</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-slate-500" style="border-left:2px dashed #E2E8F0;">{{ n(saleCost(sale)) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold" style="border-left:2px dashed #BBF7D0;"
                                :style="saleProfit(sale) >= 0 ? 'color:#15803D;' : 'color:#DC2626;'">
                                {{ n(saleProfit(sale)) }}
                            </td>
                            <td class="px-4 py-2.5 text-right text-xs text-slate-500">{{ pct(saleProfit(sale), sale.total) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="sales.length > 0" class="border-t-2 border-slate-200">
                        <tr class="bg-slate-50 font-semibold">
                            <td colspan="4" class="px-4 py-2.5 text-slate-500 text-xs uppercase">එකතුව</td>
                            <td class="px-4 py-2.5 text-right text-slate-700" style="border-left:2px dashed #E2E8F0;">{{ n(totalRevenue) }}</td>
                            <td class="px-4 py-2.5 text-right text-green-700">{{ n(totalReceived) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color:#DC2626;">{{ totalCredit > 0 ? n(totalCredit) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-600" style="border-left:2px dashed #E2E8F0;">{{ n(totalCost) }}</td>
                            <td class="px-4 py-2.5 text-right font-bold" style="border-left:2px dashed #BBF7D0; color:#15803D;">{{ n(totalProfit) }}</td>
                            <td class="px-4 py-2.5 text-right text-xs text-slate-500">{{ pct(totalProfit, totalRevenue) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Installment profit table -->
        <div v-if="installments.length > 0" class="bg-white rounded-xl shadow-sm mb-6" style="border:1px solid #FFEDD5;">
            <div class="px-4 py-3 border-b flex items-center gap-2" style="border-color:#FED7AA; background:#FFF7ED;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="#EA580C">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <h2 class="font-semibold text-sm" style="color:#C2410C;">
                    වාරික සැලසුම් ලාභ / Installment Plan Profit
                    <span class="font-normal text-xs ml-1" style="color:#EA580C;">({{ installments.length }})</span>
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase bg-orange-50 border-b border-orange-100">
                            <th class="px-4 py-3 text-left">සැලසුම් අංකය</th>
                            <th class="px-4 py-3 text-left">පාරිභෝගිකයා</th>
                            <th class="px-4 py-3 text-center">වාරිකය</th>
                            <!-- Revenue group -->
                            <th class="px-4 py-3 text-right" style="border-left:3px solid #FED7AA; background:#FFF7ED;">සැලසුම් මුදල (Rs.)</th>
                            <th class="px-4 py-3 text-right" style="background:#FFF7ED;">අද ගෙවූ (Rs.)</th>
                            <th class="px-4 py-3 text-right" style="background:#FFF7ED;">මුළු ගෙවූ (Rs.)</th>
                            <!-- Cost group -->
                            <th class="px-4 py-3 text-right" style="border-left:3px solid #E2E8F0; background:#F8FAFC;">භාණ්ඩ පිරිවැය (Rs.)</th>
                            <!-- Profit group -->
                            <th class="px-4 py-3 text-right" style="border-left:3px solid #BBF7D0; background:#F0FDF4;">භාණ්ඩ ලාභය (Rs.)</th>
                            <th class="px-4 py-3 text-right" style="background:#F0FDF4;">පොලිය (Rs.)</th>
                            <th class="px-4 py-3 text-right font-bold" style="background:#F0FDF4;">මුළු ලාභය (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="inst in annotatedInstallments" :key="inst.id"
                            class="border-b border-slate-50"
                            :class="inst._isFirst ? 'hover:bg-orange-50' : 'hover:bg-slate-50 bg-slate-50/40'">
                            <td class="px-4 py-2.5 font-medium text-orange-700">
                                {{ inst._isFirst ? inst.plan?.plan_no : '' }}
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ inst._isFirst ? inst.plan?.customer?.name : '' }}
                            </td>
                            <td class="px-4 py-2.5 text-center text-xs text-slate-500">
                                {{ inst.installment_no === 0 ? 'Down Pmt' : '#' + inst.installment_no }}
                            </td>
                            <!-- Revenue group -->
                            <td class="px-4 py-2.5 text-right text-slate-700 font-medium" style="border-left:3px solid #FED7AA; background:#FFFBF5;">
                                <span v-if="inst._isFirst">{{ n(planTotal(inst)) }}</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold text-green-700" style="background:#FFFBF5;">{{ n(cashToday(inst)) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-500" style="background:#FFFBF5;">
                                <span v-if="inst._isFirst">{{ n(planTotalPaid(inst)) }}</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <!-- Cost group (proportional to amount paid) -->
                            <td class="px-4 py-2.5 text-right text-slate-500" style="border-left:3px solid #E2E8F0;">
                                {{ n(proportional(inst).cost) }}
                            </td>
                            <!-- Profit group (proportional to amount paid) -->
                            <td class="px-4 py-2.5 text-right font-semibold" style="border-left:3px solid #BBF7D0; color:#15803D;">
                                {{ n(proportional(inst).margin) }}
                            </td>
                            <td class="px-4 py-2.5 text-right" style="color:#EA580C;">
                                <span v-if="proportional(inst).interest > 0">{{ n(proportional(inst).interest) }}</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-bold" style="color:#C2410C;">
                                {{ n(proportional(inst).profit) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-orange-200">
                        <tr class="bg-orange-50 font-semibold">
                            <td colspan="3" class="px-4 py-2.5 text-xs uppercase" style="color:#EA580C;">එකතුව</td>
                            <td class="px-4 py-2.5 text-right text-slate-700" style="border-left:3px solid #FED7AA; background:#FFFBF5;">{{ n(instTotalValue) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color:#15803D; background:#FFFBF5;">{{ n(instTodayTotal) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-600" style="background:#FFFBF5;">{{ n(instTotalPaid) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-600" style="border-left:3px solid #E2E8F0;">{{ n(instTotalCost) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold" style="border-left:3px solid #BBF7D0; color:#15803D;">{{ n(instTotalMargin) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color:#EA580C;">{{ n(instTotalInt) }}</td>
                            <td class="px-4 py-2.5 text-right font-bold" style="color:#C2410C;">{{ n(instProfit) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Credit repayments (context only) -->
        <div v-if="creditRepayments.length > 0" class="bg-white rounded-xl shadow-sm mb-6" style="border:1px solid #BBF7D0;">
            <div class="px-4 py-3 border-b flex items-center gap-2" style="border-color:#BBF7D0; background:#F0FDF4;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="#15803D">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h2 class="font-semibold text-sm" style="color:#15803D;">
                    ණය එකතු කිරීම් / Credit Repayments
                    <span class="font-normal text-xs ml-1" style="color:#16A34A;">({{ creditRepayments.length }}) — ලාභ නොවේ</span>
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase bg-green-50 border-b border-green-100">
                            <th class="px-4 py-3 text-left">පාරිභෝගිකයා</th>
                            <th class="px-4 py-3 text-left">කැෂියර්</th>
                            <th class="px-4 py-3 text-left">සටහන</th>
                            <th class="px-4 py-3 text-right">ගෙවූ මුදල (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-green-50">
                        <tr v-for="cp in creditRepayments" :key="cp.id" class="hover:bg-green-50">
                            <td class="px-4 py-2.5 font-medium text-slate-800">{{ cp.customer?.name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-500">{{ cp.user?.name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-400 text-xs">{{ cp.note ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold" style="color:#15803D;">{{ n(cp.amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-green-200">
                        <tr class="bg-green-50 font-semibold">
                            <td colspan="3" class="px-4 py-2.5 text-xs uppercase" style="color:#15803D;">එකතුව</td>
                            <td class="px-4 py-2.5 text-right" style="color:#15803D;">{{ n(summary.credit_repayment_total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
