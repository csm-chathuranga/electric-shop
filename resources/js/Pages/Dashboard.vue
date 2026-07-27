<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { inject, computed, ref } from 'vue';

const t = inject('t');
const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'cashier');
const isCashier = computed(() => userRole.value === 'cashier');

const props = defineProps({
    todaySales:     { type: Number, default: 0 },
    todayBills:     { type: Number, default: 0 },
    todayTotal:         { type: Number, default: 0 },
    todayInstallments:  { type: Number, default: 0 },
    todayCredit:        { type: Number, default: 0 },
    monthSales:         { type: Number, default: 0 },
    monthBills:         { type: Number, default: 0 },
    monthTotal:         { type: Number, default: 0 },
    monthInstallments:  { type: Number, default: 0 },
    totalProducts:  { type: Number, default: 0 },
    lowStockCount:  { type: Number, default: 0 },
    todayByPayment: { type: Array,  default: () => [] },
    last3Days:      { type: Array,  default: () => [] },
    heatmap:        { type: Array,  default: () => [] },
    fastMoving:     { type: Array,  default: () => [] },
    recentSales:    { type: Array,  default: () => [] },
    expiringSoon:           { type: Array,  default: () => [] },
    overdueInstallments:    { type: Array,  default: () => [] },
    upcomingInstallments:   { type: Array,  default: () => [] },
    creditCustomers:        { type: Array,  default: () => [] },
    filters:  { type: Object, default: () => ({}) },
    isToday:  { type: Boolean, default: true },
});

const clearForm = useForm({});
function clearCache() {
    clearForm.post(route('dashboard.clear-cache'), {
        onSuccess: () => router.reload(),
    });
}

// ── Date filter ───────────────────────────────────────────────────────────────
function localDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const dateFilter = ref(props.filters?.date || localDate(new Date()));

function todayStr() { return localDate(new Date()); }
function yesterdayStr() {
    const d = new Date(); d.setDate(d.getDate() - 1);
    return localDate(d);
}
function monthStartStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

const activePeriod = computed(() => {
    const t = todayStr(), y = yesterdayStr(), m = monthStartStr();
    if (dateFilter.value === t) return 'today';
    if (dateFilter.value === y) return 'yesterday';
    if (dateFilter.value === m) return 'month';
    return null;
});

function goDate(date) {
    dateFilter.value = date;
    router.get(route('dashboard'), date !== todayStr() ? { date } : {}, { preserveScroll: true });
}

// date label shown in the stat tile header
const dateLabel = computed(() => {
    if (props.isToday) return null;
    return new Date(dateFilter.value + 'T00:00:00').toLocaleDateString('en-LK', { day: '2-digit', month: 'short', year: 'numeric' });
});

// month label for the month tile
const monthLabel = computed(() => {
    return new Date(dateFilter.value + 'T00:00:00').toLocaleDateString('en-LK', { month: 'long', year: 'numeric' });
});


// ── Heatmap helpers ──
const hoveredCell = ref(null);

const heatmapMax = computed(() => Math.max(...props.heatmap.map(c => c.total), 1));

function heatColor(total) {
    if (!total) return '#F1F5F9';
    const intensity = total / heatmapMax.value;
    if (intensity < 0.2)  return '#DCFCE7';
    if (intensity < 0.4)  return '#86EFAC';
    if (intensity < 0.6)  return '#22C55E';
    if (intensity < 0.8)  return '#16A34A';
    return '#15803D';
}

function heatTextColor(total) {
    if (!total) return '#CBD5E1';
    const intensity = total / heatmapMax.value;
    return intensity >= 0.6 ? '#fff' : '#166534';
}

const DOW_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// heatmap rows are compact arrays: [date, dow, week, total, bills]
const heatmapWeeks = computed(() => {
    const weeks = {};
    props.heatmap.forEach(([date, dow, week, total, bills]) => {
        if (!weeks[week]) weeks[week] = {};
        weeks[week][dow] = { date, dow, week, total, bills };
    });
    return Object.values(weeks);
});

function fmt(v) {
    return 'Rs. ' + Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function nextDueDays(dateStr) {
    if (!dateStr) return null;
    const due = new Date(dateStr + 'T00:00:00');
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return Math.round((due - today) / 86400000);
}
function nextDueLabel(dateStr) {
    const d = nextDueDays(dateStr);
    if (d === null) return '';
    if (d < 0)  return `${Math.abs(d)}d අකිමි`;
    if (d === 0) return 'අද';
    if (d === 1) return 'හෙට';
    return dateStr.slice(5).replace('-', '/');
}
function nextDueStyle(dateStr) {
    const d = nextDueDays(dateStr);
    if (d === null) return '';
    if (d <= 3) return 'background:#FEE2E2; color:#DC2626;';
    return 'background:#F1F5F9; color:#64748B;';
}
// Credit book sort
const creditSort = ref('urgency'); // 'balance' | 'urgency'
const urgentCount = computed(() => props.creditCustomers.filter(c => {
    if (c.next_due)      return nextDueDays(c.next_due) <= 3;
    if (c.credit_since)  return creditSinceDays(c.credit_since) > 30;
    return false;
}).length);
const sortedCreditCustomers = computed(() => {
    const list = [...props.creditCustomers];
    if (creditSort.value === 'urgency') {
        list.sort((a, b) => {
            const scoreA = urgencyScore(a);
            const scoreB = urgencyScore(b);
            return scoreA - scoreB;
        });
    }
    // default: already sorted by balance desc from server
    return list;
});
function urgencyScore(c) {
    if (c.next_due) {
        const d = nextDueDays(c.next_due);
        return d ?? 9999;
    }
    if (c.credit_since) {
        return 1000 - creditSinceDays(c.credit_since);  // older debt = lower score = first
    }
    return 9999;
}

// Credit-since helpers (for pure credit-sale customers with no installment schedule)
function creditSinceDays(dateStr) {
    if (!dateStr) return null;
    const since = new Date(dateStr + 'T00:00:00');
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return Math.round((today - since) / 86400000);
}
function creditSinceLabel(dateStr) {
    const d = creditSinceDays(dateStr);
    if (d === null) return '';
    if (d === 0) return 'අද';
    return `${d}d ණය`;
}
function creditSinceStyle(dateStr) {
    const d = creditSinceDays(dateStr);
    if (d === null) return '';
    if (d > 30) return 'background:#FEE2E2; color:#DC2626;';
    if (d > 7)  return 'background:#FEF9C3; color:#A16207;';
    return 'background:#F1F5F9; color:#64748B;';
}

function parseUtc(d) {
    if (!d) return null;
    // DB::table() returns "YYYY-MM-DD HH:mm:ss" with no timezone — treat as UTC
    // Eloquent returns "YYYY-MM-DDTHH:mm:ss.000000Z" which JS already handles correctly
    if (typeof d === 'string' && !d.includes('T') && !d.endsWith('Z')) {
        return new Date(d.replace(' ', 'T') + 'Z');
    }
    return new Date(d);
}

function formatTime(d) {
    if (!d) return '';
    const dt      = parseUtc(d);
    const todayStr = new Date().toDateString();
    const timeStr  = dt.toLocaleTimeString('en-LK', { hour: '2-digit', minute: '2-digit' });
    if (dt.toDateString() === todayStr) return timeStr;
    return dt.toLocaleDateString('en-LK', { day: '2-digit', month: 'short' }) + ' · ' + timeStr;
}

function daysUntilExpiry(dateStr) {
    return Math.ceil((new Date(dateStr) - new Date()) / 86400000);
}

function expiryLabel(days) {
    if (days < 0)   return { text: `Expired ${Math.abs(days)}d ago`, cls: 'bg-red-100 text-red-700' };
    if (days === 0) return { text: 'Expires today',                  cls: 'bg-red-100 text-red-700' };
    if (days <= 7)  return { text: `${days}d left`,                  cls: 'bg-red-100 text-red-700' };
    return               { text: `${days}d left`,                    cls: 'bg-amber-100 text-amber-700' };
}


</script>

<template>
    <Head :title="t('page.dashboard')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2 w-full">
                <h1 class="text-xl font-bold text-gray-800 mr-2">{{ t('page.dashboard') }}</h1>

                <!-- Quick period buttons -->
                <div class="flex gap-1">
                    <button type="button" @click="goDate(todayStr())"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                        :class="activePeriod === 'today' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                        Today
                    </button>
                    <button type="button" @click="goDate(yesterdayStr())"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                        :class="activePeriod === 'yesterday' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                        Yesterday
                    </button>
                    <button type="button" @click="goDate(monthStartStr())"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                        :class="activePeriod === 'month' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                        Month Start
                    </button>
                </div>

                <!-- Date input -->
                <input
                    v-model="dateFilter"
                    type="date"
                    :max="todayStr()"
                    @change="goDate(dateFilter)"
                    class="border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 bg-white"
                />

                <div class="flex-1"></div>

                <!-- Clear cache (non-cashier) -->
                <button
                    v-if="!isCashier"
                    type="button"
                    @click="clearCache"
                    :disabled="clearForm.processing"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 bg-gray-50 hover:bg-red-50 hover:border-red-300 hover:text-red-600 text-gray-500 transition-colors disabled:opacity-50"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ clearForm.processing ? 'Clearing…' : 'Clear Cache' }}
                </button>
            </div>
        </template>

        <!-- ── STAT TILES + BAR CHART (non-cashier: side by side; cashier: 2 tiles only) ── -->
        <div v-if="!isCashier" class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-4">
            <!-- 4 tiles in 2×2 sub-grid -->
            <div class="lg:col-span-2 grid grid-cols-2 gap-3 content-start">
                <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #E2E8F0;">
                    <div class="flex items-center gap-3 px-4 pt-4 pb-2">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#DCFCE7;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="#16A34A"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 leading-tight">{{ dateLabel || t('dash.today_sales') }}</p>
                            <p class="font-bold text-lg leading-tight truncate" style="color:#15803D;">{{ fmt(todaySales) }}</p>
                            <p v-if="todayTotal > todaySales" class="text-xs leading-tight" style="color:#DC2626;">
                                Billed: {{ fmt(todayTotal) }}
                            </p>
                        </div>
                    </div>
                    <div class="px-4 pb-3"><span class="text-xs text-slate-400">{{ todayBills }} {{ todayBills === 1 ? 'bill' : 'bills' }}</span></div>
                </div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #E2E8F0;">
                    <div class="flex items-center gap-3 px-4 pt-4 pb-2">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#DBEAFE;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="#2563EB"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 leading-tight">{{ monthLabel }}</p>
                            <p class="font-bold text-lg leading-tight truncate" style="color:#1D4ED8;">{{ fmt(monthSales) }}</p>
                            <p v-if="monthTotal > monthSales" class="text-xs leading-tight" style="color:#DC2626;">
                                Billed: {{ fmt(monthTotal) }}
                            </p>
                        </div>
                    </div>
                    <div class="px-4 pb-3"><span class="text-xs text-slate-400">{{ monthBills }} bills</span></div>
                </div>
                <!-- Total Collected = today sales + installments -->
                <div class="col-span-2 bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #C7D2FE; background:linear-gradient(135deg,#EEF2FF 0%,#fff 100%);">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#E0E7FF;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="#4338CA"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-slate-500 leading-tight">{{ t('dash.total_collected') }}</p>
                            <p class="font-bold text-xl leading-tight" style="color:#3730A3;">{{ fmt(Number(todaySales) + Number(todayInstallments)) }}</p>
                        </div>
                        <div class="text-right text-xs text-slate-400 leading-relaxed flex-shrink-0">
                            <p>Sales <span class="font-semibold text-slate-600">{{ fmt(todaySales) }}</span></p>
                            <p v-if="todayInstallments > 0">Installments <span class="font-semibold" style="color:#EA580C;">{{ fmt(todayInstallments) }}</span></p>
                        </div>
                    </div>
                </div>

                <Link :href="route('products.index')" class="bg-white rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow" style="border:1px solid #E2E8F0;">
                    <div class="flex items-center gap-3 px-4 pt-4 pb-2">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#F3E8FF;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="#7C3AED"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 leading-tight">{{ t('dash.total_products') }}</p>
                            <p class="font-bold text-lg leading-tight" style="color:#6D28D9;">{{ totalProducts }}</p>
                        </div>
                    </div>
                    <div class="px-4 pb-3"><span class="text-xs text-slate-400">active products</span></div>
                </Link>
                <Link :href="route('reports.low-stock')" class="bg-white rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow" style="border:1px solid #E2E8F0;">
                    <div class="flex items-center gap-3 px-4 pt-4 pb-2">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" :style="lowStockCount > 0 ? 'background:#FEE2E2;' : 'background:#F1F5F9;'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" :stroke="lowStockCount > 0 ? '#DC2626' : '#94A3B8'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 leading-tight">{{ t('dash.low_stock') }}</p>
                            <p class="font-bold text-lg leading-tight" :style="lowStockCount > 0 ? 'color:#DC2626;' : 'color:#64748B;'">{{ lowStockCount }}</p>
                        </div>
                    </div>
                    <div class="px-4 pb-3"><span class="text-xs" :class="lowStockCount > 0 ? 'text-red-400' : 'text-slate-400'">{{ lowStockCount > 0 ? 'needs attention' : 'all good' }}</span></div>
                </Link>
            </div>

            <!-- Peak Days heatmap inline -->
            <div class="lg:col-span-3 bg-white rounded-xl shadow-sm" style="border:1px solid #E2E8F0;">
                <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:#F1F5F9;">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Peak Days</p>
                        <p class="text-xs text-slate-400">Sales heatmap — last 10 weeks</p>
                    </div>
                </div>
                <div class="px-4 py-3 overflow-x-auto">
                    <div class="flex gap-1 min-w-0">
                        <div class="flex flex-col gap-1 mr-1 flex-shrink-0">
                            <div style="height:16px;"></div>
                            <div v-for="label in DOW_LABELS" :key="label"
                                class="flex items-center justify-end"
                                style="height:16px; font-size:9px; color:#94A3B8; width:22px;">
                                {{ label }}
                            </div>
                        </div>
                        <div class="flex gap-1 flex-1 min-w-0">
                            <div v-for="(week, wi) in heatmapWeeks" :key="wi" class="flex flex-col gap-1 flex-1 min-w-0">
                                <div style="height:16px; font-size:8px; color:#94A3B8; text-align:center; overflow:hidden;">
                                    {{ week[1]?.date?.slice(5,7) === '01' || wi === 0 ? week[1]?.date?.slice(5,7) : '' }}
                                </div>
                                <div
                                    v-for="dow in [1,2,3,4,5,6,7]"
                                    :key="dow"
                                    class="rounded-sm cursor-default"
                                    style="height:16px;"
                                    :style="`background:${week[dow] ? heatColor(week[dow].total) : '#F8FAFC'};`"
                                    @mouseenter="week[dow] && (hoveredCell = week[dow])"
                                    @mouseleave="hoveredCell = null"
                                ></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 mt-3">
                        <span class="text-slate-400" style="font-size:9px;">Less</span>
                        <div v-for="c in ['#F1F5F9','#DCFCE7','#86EFAC','#22C55E','#16A34A','#15803D']" :key="c"
                            class="w-3 h-3 rounded-sm flex-shrink-0" :style="`background:${c};`"></div>
                        <span class="text-slate-400" style="font-size:9px;">More</span>
                    </div>
                    <div v-if="hoveredCell" class="mt-2 px-3 py-2 rounded-lg text-xs" style="background:#F8FAFC; border:1px solid #E2E8F0;">
                        <span class="font-semibold text-gray-700">{{ new Date(hoveredCell.date).toLocaleDateString('en-LK', { weekday:'short', month:'short', day:'numeric' }) }}</span>
                        — <span style="color:#16A34A;">{{ fmt(hoveredCell.total) }}</span>
                        <span class="text-slate-400 ml-2">{{ hoveredCell.bills }} bill{{ hoveredCell.bills !== 1 ? 's' : '' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cashier: 2 tiles only -->
        <div v-else class="grid grid-cols-2 gap-3 mb-4">
            <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #E2E8F0;">
                <div class="flex items-center gap-3 px-4 pt-4 pb-2">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#DCFCE7;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="#16A34A"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500 leading-tight">{{ dateLabel || t('dash.today_sales') }}</p>
                        <p class="font-bold text-lg leading-tight truncate" style="color:#15803D;">{{ fmt(todaySales) }}</p>
                        <p v-if="todayTotal > todaySales" class="text-xs leading-tight" style="color:#DC2626;">
                            Billed: {{ fmt(todayTotal) }}
                        </p>
                    </div>
                </div>
                <div class="px-4 pb-3"><span class="text-xs text-slate-400">{{ todayBills }} {{ todayBills === 1 ? 'bill' : 'bills' }} today</span></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #E2E8F0;">
                <div class="flex items-center gap-3 px-4 pt-4 pb-2">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#DBEAFE;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="#2563EB"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500 leading-tight">{{ monthLabel }}</p>
                        <p class="font-bold text-lg leading-tight truncate" style="color:#1D4ED8;">{{ fmt(monthSales) }}</p>
                        <p v-if="monthTotal > monthSales" class="text-xs leading-tight" style="color:#DC2626;">
                            Billed: {{ fmt(monthTotal) }}
                        </p>
                    </div>
                </div>
                <div class="px-4 pb-3"><span class="text-xs text-slate-400">{{ monthBills }} bills this month</span></div>
            </div>
            <!-- Total Collected = today sales + installments -->
            <div class="col-span-2 bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #C7D2FE; background:linear-gradient(135deg,#EEF2FF 0%,#fff 100%);">
                <div class="flex items-center gap-3 px-4 py-3">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#E0E7FF;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="#4338CA"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-slate-500 leading-tight">Total Collected</p>
                        <p class="font-bold text-xl leading-tight" style="color:#3730A3;">{{ fmt(Number(todaySales) + Number(todayInstallments)) }}</p>
                    </div>
                    <div class="text-right text-xs text-slate-400 leading-relaxed flex-shrink-0">
                        <p>Sales <span class="font-semibold text-slate-600">{{ fmt(todaySales) }}</span></p>
                        <p v-if="todayInstallments > 0">Installments <span class="font-semibold" style="color:#EA580C;">{{ fmt(todayInstallments) }}</span></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── QUICK ACTIONS ── -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
            <Link :href="route('sales.create')" class="quick-action-card group" style="--from:#2563EB;--to:#1d4ed8;">
                <div class="quick-action-glow" style="background:radial-gradient(circle at 70% 30%, rgba(255,255,255,0.18) 0%, transparent 70%);"></div>
                <div class="quick-action-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="quick-action-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-1.5 6h13M10 19a1 1 0 100 2 1 1 0 000-2zm7 0a1 1 0 100 2 1 1 0 000-2z" /></svg>
                </div>
                <span class="quick-action-plus">+</span>
                <span class="quick-action-label">{{ t('btn.new_sale') }}</span>
                <div class="quick-action-arrow"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>
            </Link>

            <!-- Cashier: View Invoices -->
            <Link v-if="isCashier" :href="route('sales.index')" class="quick-action-card group" style="--from:#0369a1;--to:#075985;">
                <div class="quick-action-glow" style="background:radial-gradient(circle at 70% 30%, rgba(255,255,255,0.18) 0%, transparent 70%);"></div>
                <div class="quick-action-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="quick-action-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                </div>
                <span class="quick-action-label">{{ t('nav.sales') }}</span>
                <div class="quick-action-arrow"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>
            </Link>

            <!-- Non-cashier: Products, Purchases, Reports -->
            <Link v-if="!isCashier" :href="route('products.create')" class="quick-action-card group" style="--from:#7C3AED;--to:#6D28D9;">
                <div class="quick-action-glow" style="background:radial-gradient(circle at 70% 30%, rgba(255,255,255,0.18) 0%, transparent 70%);"></div>
                <div class="quick-action-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="quick-action-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                </div>
                <span class="quick-action-plus">+</span>
                <span class="quick-action-label">{{ t('btn.new_product') }}</span>
                <div class="quick-action-arrow"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>
            </Link>

            <Link v-if="!isCashier" :href="route('purchases.create')" class="quick-action-card group" style="--from:#059669;--to:#047857;">
                <div class="quick-action-glow" style="background:radial-gradient(circle at 70% 30%, rgba(255,255,255,0.18) 0%, transparent 70%);"></div>
                <div class="quick-action-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="quick-action-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>
                </div>
                <span class="quick-action-plus">+</span>
                <span class="quick-action-label">{{ t('btn.new_purchase') }}</span>
                <div class="quick-action-arrow"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>
            </Link>

            <Link v-if="!isCashier" :href="route('reports.index')" class="quick-action-card group" style="--from:#D97706;--to:#B45309;">
                <div class="quick-action-glow" style="background:radial-gradient(circle at 70% 30%, rgba(255,255,255,0.18) 0%, transparent 70%);"></div>
                <div class="quick-action-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="quick-action-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                </div>
                <span class="quick-action-label">{{ t('btn.report') }}</span>
                <div class="quick-action-arrow"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>
            </Link>

            <Link :href="route('reports.daily-profit')" class="quick-action-card group" style="--from:#0F766E;--to:#0D5C55;">
                <div class="quick-action-glow" style="background:radial-gradient(circle at 70% 30%, rgba(255,255,255,0.18) 0%, transparent 70%);"></div>
                <div class="quick-action-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="quick-action-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <span class="quick-action-label">ආදායම් වාර්තාව</span>
                <div class="quick-action-arrow"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>
            </Link>

            <Link :href="route('stock-transfers.create')" class="quick-action-card group" style="--from:#B45309;--to:#92400E;">
                <div class="quick-action-glow" style="background:radial-gradient(circle at 70% 30%, rgba(255,255,255,0.18) 0%, transparent 70%);"></div>
                <div class="quick-action-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="quick-action-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
                </div>
                <span class="quick-action-label">ස්ටොක් මාරු කිරීම</span>
                <div class="quick-action-arrow"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>
            </Link>
        </div>


        <!-- ── INSTALLMENT ALERTS (non-cashier only) ── -->
        <div v-if="!isCashier && (overdueInstallments.length > 0 || upcomingInstallments.length > 0)" class="mb-4 grid grid-cols-1 lg:grid-cols-2 gap-4">

            <!-- Overdue -->
            <div v-if="overdueInstallments.length > 0" class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #FECACA;">
                <div class="flex items-center gap-2 px-4 py-3 border-b" style="border-color:#FECACA; background:#FFF5F5;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-red-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                    <p class="text-sm font-semibold text-red-700">Overdue Installments ({{ overdueInstallments.length }})</p>
                </div>
                <div class="divide-y" style="border-color:#FEE2E2;">
                    <div v-for="item in overdueInstallments.slice(0, 5)" :key="item.id" class="flex items-center gap-3 px-4 py-2.5 bg-red-50">
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-bold text-red-800">{{ item.plan_no }}</p>
                            <p class="text-xs text-red-600 truncate">{{ item.customer }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-xs font-semibold text-red-700">Rs. {{ Number(item.amount_due - item.amount_paid).toLocaleString('en-LK', { minimumFractionDigits: 2 }) }}</p>
                            <p class="text-xs text-red-500">{{ item.days_overdue }}d overdue</p>
                        </div>
                        <Link :href="route('installments.show', item.plan_id)" class="text-xs text-red-600 hover:underline font-semibold flex-shrink-0">View</Link>
                    </div>
                </div>
            </div>

            <!-- Due Soon -->
            <div v-if="upcomingInstallments.length > 0" class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #FDE68A;">
                <div class="flex items-center gap-2 px-4 py-3 border-b" style="border-color:#FDE68A; background:#FFFBEB;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm font-semibold text-amber-700">Due in 2 Days ({{ upcomingInstallments.length }})</p>
                </div>
                <div class="divide-y" style="border-color:#FEF3C7;">
                    <div v-for="item in upcomingInstallments.slice(0, 5)" :key="item.id" class="flex items-center gap-3 px-4 py-2.5 bg-amber-50">
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-bold text-amber-800">{{ item.plan_no }}</p>
                            <p class="text-xs text-amber-600 truncate">{{ item.customer }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-xs font-semibold text-amber-700">Rs. {{ Number(item.amount_due - item.amount_paid).toLocaleString('en-LK', { minimumFractionDigits: 2 }) }}</p>
                            <p class="text-xs text-amber-500">Due {{ item.due_date }}</p>
                        </div>
                        <Link :href="route('installments.show', item.plan_id)" class="text-xs text-amber-600 hover:underline font-semibold flex-shrink-0">View</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CREDIT BOOK OUTSTANDING (non-cashier only) ── -->
        <div v-if="creditCustomers.length > 0" class="mb-4 bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #BFDBFE;">
            <div class="flex items-center justify-between px-4 py-3 border-b" style="border-color:#BFDBFE; background:#EFF6FF;">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    <p class="text-sm font-semibold text-blue-700">ණය පොත — Credit Book <span class="ml-1 text-xs font-normal text-blue-500">({{ creditCustomers.length }} customers)</span></p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex rounded-lg overflow-hidden border border-blue-200 text-xs">
                        <button @click="creditSort = 'urgency'"
                            class="px-2 py-1 font-semibold transition-colors flex items-center gap-1"
                            :class="creditSort === 'urgency' ? 'bg-blue-600 text-white' : 'bg-white text-blue-500 hover:bg-blue-50'">
                            ⏰ Urgent
                            <span v-if="urgentCount > 0" class="text-xs rounded-full px-1.5 font-bold"
                                :style="creditSort === 'urgency' ? 'background:rgba(255,255,255,0.25);' : 'background:#FEE2E2; color:#DC2626;'">
                                {{ urgentCount }}
                            </span>
                        </button>
                        <button @click="creditSort = 'balance'"
                            class="px-2 py-1 font-semibold transition-colors border-l border-blue-200"
                            :class="creditSort === 'balance' ? 'bg-blue-600 text-white' : 'bg-white text-blue-500 hover:bg-blue-50'">
                            Rs. Balance
                        </button>
                    </div>
                    <Link :href="route('reports.credit-customers')" class="text-xs font-semibold text-blue-600 hover:underline">සියල්ල බලන්න</Link>
                </div>
            </div>
            <div class="divide-y" style="border-color:#DBEAFE;">
                <div v-for="c in sortedCreditCustomers.slice(0, 8)" :key="c.id"
                    class="flex items-center gap-3 px-4 py-2.5 transition-colors"
                    :class="(c.next_due && nextDueDays(c.next_due) <= 3) || (c.credit_since && creditSinceDays(c.credit_since) > 30)
                        ? 'bg-red-50 hover:bg-red-100'
                        : 'hover:bg-blue-50'">
                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0 text-xs font-bold text-blue-700">
                        {{ c.name.charAt(0).toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-gray-800 truncate">{{ c.name }}</p>
                        <p class="text-xs text-slate-400">{{ c.phone ?? '—' }}</p>
                    </div>
                    <!-- Next payment due (installment) or credit-since badge -->
                    <div class="flex-shrink-0 text-right mr-2">
                        <template v-if="c.next_due">
                            <span class="text-xs px-1.5 py-0.5 rounded font-semibold"
                                :style="nextDueStyle(c.next_due)">
                                {{ nextDueLabel(c.next_due) }}
                            </span>
                        </template>
                        <template v-else-if="c.credit_since">
                            <span class="text-xs px-1.5 py-0.5 rounded font-semibold"
                                :style="creditSinceStyle(c.credit_since)">
                                {{ creditSinceLabel(c.credit_since) }}
                            </span>
                        </template>
                    </div>
                    <p class="text-sm font-bold text-blue-700 flex-shrink-0">Rs. {{ Number(c.credit_balance).toLocaleString('en-LK', { minimumFractionDigits: 2 }) }}</p>
                </div>
            </div>
            <div v-if="creditCustomers.length > 8" class="px-4 py-2 text-xs text-center text-blue-500 border-t" style="border-color:#DBEAFE;">
                + {{ creditCustomers.length - 8 }} more —
                <Link :href="route('reports.credit-customers')" class="font-semibold hover:underline">සියල්ල බලන්න</Link>
            </div>
        </div>

        <!-- ── MAIN CONTENT GRID ── -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <!-- Recent Sales (full width for cashier, 2/3 for others) -->
            <div :class="isCashier ? 'lg:col-span-3' : 'lg:col-span-2'" class="bg-white rounded-xl shadow-sm" style="border:1px solid #E2E8F0;">
                <div class="flex items-center justify-between px-4 py-3 border-b" style="border-color:#F1F5F9;">
                    <h2 class="font-semibold text-gray-800 text-sm">{{ t('dash.recent_sales') }}</h2>
                    <Link :href="route('sales.index')" class="text-xs hover:underline" style="color:#2563EB;">{{ t('dash.view_all') }}</Link>
                </div>
                <div class="divide-y" style="border-color:#F8FAFC;">
                    <div v-if="recentSales.length === 0" class="px-4 py-8 text-center text-slate-400 text-sm">{{ t('dash.no_sales') }}</div>
                    <div v-for="sale in recentSales" :key="sale.id" class="flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition-colors">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-xs font-bold text-white" style="background:#2563EB;">
                            {{ sale.user_name?.charAt(0)?.toUpperCase() || '?' }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <Link :href="route('sales.show', sale.id)" class="text-sm font-semibold hover:underline" style="color:#1E40AF;">{{ sale.invoice_no }}</Link>
                            <p class="text-xs text-slate-400">{{ sale.user_name }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-sm font-bold" style="color:#16A34A;">{{ fmt(sale.total) }}</p>
                            <p v-if="sale.balance > 0" class="text-xs font-medium" style="color:#DC2626;">
                                Credit: {{ fmt(sale.balance) }}
                            </p>
                            <p class="text-xs text-slate-400">{{ formatTime(sale.created_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right column: Expiring Soon + Fast Moving (non-cashier only) -->
            <div v-if="!isCashier" class="flex flex-col gap-4">

                <!-- Expiring Soon -->
                <div v-if="expiringSoon.length > 0" class="bg-white rounded-xl shadow-sm" style="border:1px solid #FCD34D;">
                    <div class="flex items-center justify-between px-4 py-3 border-b" style="border-color:#FEF3C7; background:#FFFBEB;">
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                            <h2 class="font-semibold text-amber-800 text-sm">Expiring Soon</h2>
                        </div>
                        <span class="text-xs font-bold bg-amber-500 text-white px-2 py-0.5 rounded-full">{{ expiringSoon.length }}</span>
                    </div>
                    <div class="divide-y" style="border-color:#FEF9C3;">
                        <div v-for="product in expiringSoon" :key="product.id" class="flex items-center gap-2 px-3 py-2 hover:bg-amber-50 transition-colors">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ product.name }}</p>
                                <p class="text-xs text-slate-400">{{ product.stock_qty }}{{ product.unit ? ' ' + product.unit : '' }} · {{ product.expiry_date ? new Date(product.expiry_date).toLocaleDateString('en-LK') : '' }}</p>
                            </div>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full flex-shrink-0"
                                :class="expiryLabel(daysUntilExpiry(product.expiry_date)).cls">
                                {{ expiryLabel(daysUntilExpiry(product.expiry_date)).text }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Fast Moving Items -->
                <div v-if="fastMoving.length > 0" class="bg-white rounded-xl shadow-sm" style="border:1px solid #E2E8F0;">
                    <div class="flex items-center justify-between px-4 py-3 border-b" style="border-color:#F1F5F9; background:#F8FAFC;">
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="#F59E0B"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                            <p class="text-sm font-semibold text-gray-800">Fast Moving</p>
                        </div>
                        <span class="text-xs text-slate-400">last 30 days</span>
                    </div>
                    <div class="divide-y" style="border-color:#F8FAFC;">
                        <div v-for="(item, idx) in fastMoving" :key="item.product_id"
                            class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 transition-colors">
                            <!-- Rank badge -->
                            <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold"
                                :style="idx === 0 ? 'background:#FEF3C7;color:#D97706;' : idx === 1 ? 'background:#F1F5F9;color:#94A3B8;' : idx === 2 ? 'background:#FEF3C7;color:#B45309;' : 'background:#F1F5F9;color:#CBD5E1;'">
                                {{ idx + 1 }}
                            </div>
                            <!-- Product image -->
                            <img v-if="item.image" :src="item.image" :alt="item.product_name"
                                class="w-9 h-9 rounded-md object-cover flex-shrink-0 border border-gray-100" />
                            <div v-else class="w-9 h-9 rounded-md flex-shrink-0 flex items-center justify-center" style="background:#F1F5F9;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ item.product_name }}</p>
                                <p class="text-xs text-slate-400">{{ item.bill_count }} bills</p>
                            </div>
                            <!-- Qty bar + count -->
                            <div class="text-right flex-shrink-0">
                                <p class="text-sm font-bold" style="color:#D97706;">{{ Number(item.total_qty).toFixed(0) }}<span class="text-xs font-normal text-slate-400"> sold</span></p>
                                <div class="h-1 rounded-full mt-0.5" style="background:#F1F5F9; width:60px;">
                                    <div class="h-1 rounded-full"
                                        :style="`width:${Math.round((item.total_qty / fastMoving[0].total_qty) * 100)}%; background:#F59E0B;`">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>

<style scoped>
.quick-action-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 1.25rem 1rem;
    min-height: 100px;
    border-radius: 1rem;
    background: linear-gradient(135deg, var(--from), var(--to));
    color: #fff;
    text-decoration: none;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.12);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.quick-action-card:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
}
.quick-action-card:active { transform: translateY(0) scale(0.98); }
.quick-action-glow { position: absolute; inset: 0; pointer-events: none; }
.quick-action-icon-wrap {
    width: 44px; height: 44px; border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255,255,255,0.3);
    transition: background 0.2s;
}
.quick-action-card:hover .quick-action-icon-wrap { background: rgba(255,255,255,0.3); }
.quick-action-icon { width: 22px; height: 22px; color: #fff; }
.quick-action-plus { position: absolute; top: 10px; right: 14px; font-size: 1.4rem; font-weight: 300; opacity: 0.7; line-height: 1; }
.quick-action-label { font-size: 0.78rem; font-weight: 600; letter-spacing: 0.01em; text-align: center; line-height: 1.3; }
.quick-action-arrow { position: absolute; bottom: 10px; right: 12px; opacity: 0; transform: translateX(-4px); transition: opacity 0.2s, transform 0.2s; }
.quick-action-card:hover .quick-action-arrow { opacity: 0.7; transform: translateX(0); }
</style>
