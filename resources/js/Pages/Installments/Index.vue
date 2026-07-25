<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    plans:   { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');

let searchTimer = null;
watch([search, status], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('installments.index'), {
            search: search.value,
            status: status.value,
        }, { preserveState: true, replace: true });
    }, 400);
});

const statusMeta = {
    active:    { label: 'Active',    cls: 'bg-blue-100 text-blue-700' },
    completed: { label: 'Completed', cls: 'bg-green-100 text-green-700' },
    defaulted: { label: 'Defaulted', cls: 'bg-red-100 text-red-700' },
    cancelled: { label: 'Cancelled', cls: 'bg-gray-100 text-gray-500' },
};

function fmt(v) {
    return 'Rs. ' + Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function paidAmount(plan) {
    return plan.payments?.reduce((s, p) => s + Number(p.amount_paid || 0), 0) ?? 0;
}

function dpRecord(plan) {
    return (plan.payments || []).find(p => p.installment_no === 0);
}

function dpRemaining(plan) {
    const dp = dpRecord(plan);
    if (!dp) return 0;
    return Math.max(0, Number(dp.amount_due || 0) - Number(dp.amount_paid || 0));
}

function needsSetup(plan) {
    return plan.status === 'active' && plan.installments_count === 0;
}

function nextDue(plan) {
    const pending = (plan.payments || [])
        .filter(p => p.status !== 'paid')
        .sort((a, b) => a.installment_no - b.installment_no)[0];
    return pending ? pending.due_date : null;
}

function fmtDate(val) {
    if (!val) return null;
    const d = new Date(val);
    if (isNaN(d)) return String(val).slice(0, 10);
    return d.toLocaleDateString('en-LK', { year: 'numeric', month: 'short', day: '2-digit' });
}

function isOverdue(dateStr) {
    return dateStr && new Date(dateStr) < new Date(new Date().toDateString());
}

// ── Settle Initial Modal ────────────────────────────────────────────────────────
const settleModal  = ref(false);
const settlePlan   = ref(null);
const settleAmt    = ref('');
const settleMethod = ref('cash');
const settling     = ref(false);

function openSettleModal(plan) {
    settlePlan.value  = plan;
    settleAmt.value   = dpRemaining(plan).toFixed(2);
    settleMethod.value = 'cash';
    settleModal.value = true;
}

function submitSettle() {
    if (!settleAmt.value || Number(settleAmt.value) <= 0) return;
    settling.value = true;
    router.post(route('installments.settle-initial', settlePlan.value.id), {
        amount:         Number(settleAmt.value),
        payment_method: settleMethod.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { settleModal.value = false; },
        onFinish:  () => { settling.value = false; },
    });
}

// ── Setup Installments Modal ────────────────────────────────────────────────────
const setupModal          = ref(false);
const setupPlan           = ref(null);
const setupMonths         = ref(3);
const setupGraceDate      = ref('');
const settingUp           = ref(false);
const presetMonths        = [2, 3, 6, 12];

function openSetupModal(plan) {
    setupPlan.value      = plan;
    setupMonths.value    = 3;
    setupGraceDate.value = '';
    setupModal.value     = true;
}

const setupInstallmentAmt = computed(() => {
    if (!setupPlan.value || setupMonths.value < 1) return 0;
    return Math.round(setupPlan.value.balance / setupMonths.value * 100) / 100;
});

function submitSetup() {
    if (setupMonths.value < 1) return;
    settingUp.value = true;
    router.post(route('installments.setup-installments', setupPlan.value.id), {
        installments_count: setupMonths.value,
        grace_settle_date:  setupGraceDate.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { setupModal.value = false; },
        onFinish:  () => { settingUp.value = false; },
    });
}
</script>

<template>
    <Head title="Installment Plans" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <h1 class="text-xl font-bold text-gray-800">Installment Plans</h1>
                <Link :href="route('installments.create')"
                    class="flex items-center gap-2 text-white px-4 py-2 rounded-lg text-sm font-semibold"
                    style="background-color:#2563EB;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    New Plan
                </Link>
            </div>
        </template>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-4">
            <input
                v-model="search"
                type="text"
                placeholder="Search plan no or customer…"
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300 w-64"
            />
            <select v-model="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="defaulted">Defaulted</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #E2E8F0;">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" style="background:#F8FAFC; border-color:#E2E8F0;">
                        <th class="px-4 py-3 font-semibold text-slate-600">Plan No</th>
                        <th class="px-4 py-3 font-semibold text-slate-600">Customer</th>
                        <th class="px-4 py-3 font-semibold text-slate-600 text-right">Total</th>
                        <th class="px-4 py-3 font-semibold text-slate-600 text-right">Paid</th>
                        <th class="px-4 py-3 font-semibold text-slate-600 text-right">Balance</th>
                        <th class="px-4 py-3 font-semibold text-slate-600">Next Due</th>
                        <th class="px-4 py-3 font-semibold text-slate-600">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color:#F8FAFC;">
                    <tr v-if="plans.data.length === 0">
                        <td colspan="8" class="px-4 py-10 text-center text-slate-400">No installment plans found.</td>
                    </tr>
                    <tr v-for="plan in plans.data" :key="plan.id" class="hover:bg-slate-50 transition-colors">
                        <td class="px-4 py-3 font-mono font-semibold text-blue-700">{{ plan.plan_no }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800">{{ plan.customer?.name }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ fmt(plan.total) }}</td>
                        <td class="px-4 py-3 text-right text-green-700 font-semibold">{{ fmt(paidAmount(plan)) }}</td>
                        <td class="px-4 py-3 text-right font-semibold" :class="plan.total - paidAmount(plan) > 0 ? 'text-red-600' : 'text-slate-400'">
                            {{ fmt(Math.max(0, plan.total - paidAmount(plan))) }}
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="nextDue(plan)" :class="isOverdue(nextDue(plan)) ? 'text-red-600 font-semibold' : 'text-slate-600'">
                                {{ fmtDate(nextDue(plan)) }}
                                <span v-if="isOverdue(nextDue(plan))" class="ml-1 text-xs bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-bold">Overdue</span>
                            </span>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full" :class="(statusMeta[plan.status] || statusMeta.active).cls">
                                {{ (statusMeta[plan.status] || statusMeta.active).label }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 justify-end">
                                <!-- Settle remaining initial payment -->
                                <button
                                    v-if="dpRemaining(plan) > 0"
                                    type="button"
                                    @click="openSettleModal(plan)"
                                    class="text-xs font-semibold px-2 py-1 rounded-lg border transition-colors"
                                    style="color:#EA580C; border-color:#FED7AA; background:#FFF7ED;"
                                >
                                    DP ශේෂය
                                </button>
                                <!-- Setup installment months -->
                                <button
                                    v-if="needsSetup(plan)"
                                    type="button"
                                    @click="openSetupModal(plan)"
                                    class="text-xs font-semibold px-2 py-1 rounded-lg border transition-colors"
                                    style="color:#2563EB; border-color:#BFDBFE; background:#EFF6FF;"
                                >
                                    Setup
                                </button>
                                <Link :href="route('installments.show', plan.id)" class="text-blue-600 hover:underline text-xs font-semibold">View</Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="plans.links?.length > 3" class="px-4 py-3 border-t flex gap-1 flex-wrap" style="border-color:#E2E8F0;">
                <Link
                    v-for="link in plans.links" :key="link.label"
                    :href="link.url || '#'"
                    v-html="link.label"
                    class="px-3 py-1 rounded text-xs border transition-colors"
                    :class="link.active
                        ? 'bg-blue-600 text-white border-blue-600'
                        : link.url ? 'border-gray-200 text-slate-600 hover:bg-slate-50' : 'border-gray-100 text-slate-300 cursor-default'"
                />
            </div>
        </div>
    </AuthenticatedLayout>

    <!-- ── Settle Initial Payment Modal ─────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="settleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm overflow-hidden">
                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b" style="border-color:#E2E8F0;">
                    <div>
                        <p class="font-bold text-gray-800">DP ශේෂය ගෙවීම</p>
                        <p class="text-xs text-slate-400">{{ settlePlan?.plan_no }} — {{ settlePlan?.customer?.name }}</p>
                    </div>
                    <button type="button" @click="settleModal = false" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="px-5 py-4 space-y-4">
                    <!-- Remaining amount info -->
                    <div class="flex items-center justify-between rounded-xl px-4 py-3 text-sm" style="background:#FFF7ED; border:1px solid #FED7AA;">
                        <span class="text-orange-700">ඉතිරි ප්‍රාරම්භ ගෙවීම</span>
                        <span class="font-bold text-orange-700 text-base">{{ fmt(dpRemaining(settlePlan || {})) }}</span>
                    </div>

                    <!-- Amount input -->
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">ගෙවන මුදල / Amount</label>
                        <div class="flex items-center gap-2 rounded-lg px-3 py-2" style="border:1px solid #E2E8F0;">
                            <span class="text-sm font-semibold text-slate-500">Rs.</span>
                            <input
                                v-model="settleAmt"
                                type="number"
                                min="0.01"
                                step="0.01"
                                class="flex-1 bg-transparent text-lg font-bold text-gray-800 focus:outline-none"
                            />
                        </div>
                    </div>

                    <!-- Payment method -->
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">ගෙවීම් ක්‍රමය / Method</label>
                        <div class="flex gap-2">
                            <button v-for="m in ['cash','card','qr']" :key="m"
                                type="button"
                                @click="settleMethod = m"
                                class="flex-1 py-2 rounded-lg text-xs font-bold border capitalize transition-colors"
                                :class="settleMethod === m ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-slate-600 hover:bg-slate-50'"
                            >{{ m }}</button>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-5 py-4 border-t flex gap-3" style="border-color:#E2E8F0;">
                    <button type="button" @click="settleModal = false"
                        class="flex-1 py-2.5 rounded-xl border text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        style="border-color:#E2E8F0;">
                        Cancel
                    </button>
                    <button type="button" @click="submitSettle" :disabled="settling"
                        class="flex-1 py-2.5 rounded-xl text-white text-sm font-bold disabled:opacity-50"
                        style="background:#EA580C;">
                        {{ settling ? 'Saving…' : 'ගෙවීම සුරකින්න' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ── Setup Installments Modal ──────────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="setupModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm overflow-hidden">
                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b" style="border-color:#E2E8F0;">
                    <div>
                        <p class="font-bold text-gray-800">වාරික සැලසුම සාදන්න</p>
                        <p class="text-xs text-slate-400">{{ setupPlan?.plan_no }} — {{ setupPlan?.customer?.name }}</p>
                    </div>
                    <button type="button" @click="setupModal = false" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="px-5 py-4 space-y-4">
                    <!-- Balance info -->
                    <div class="flex items-center justify-between rounded-xl px-4 py-3 text-sm" style="background:#EFF6FF; border:1px solid #BFDBFE;">
                        <span class="text-blue-700">ශේෂය (Balance)</span>
                        <span class="font-bold text-blue-800 text-base">{{ fmt(setupPlan?.balance || 0) }}</span>
                    </div>

                    <!-- Grace settle date (optional — for plans with pending DP) -->
                    <div v-if="dpRemaining(setupPlan || {}) > 0">
                        <label class="block text-xs text-slate-500 mb-1">DP ශේෂය ගෙවන දිනය / Grace Date</label>
                        <input
                            v-model="setupGraceDate"
                            type="date"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                        />
                        <p class="text-xs text-slate-400 mt-1">වාරික ගෙවීම් ආරම්භ වන්නේ මෙම දිනයෙන් පසු</p>
                    </div>

                    <!-- Month count -->
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">මාස ගණන / Months</label>
                        <div class="flex gap-2 items-center">
                            <button v-for="n in presetMonths" :key="n"
                                type="button"
                                @click="setupMonths = n"
                                class="w-11 py-2 rounded-lg text-xs font-bold border transition-colors flex-shrink-0"
                                :class="setupMonths === n ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-slate-600 hover:bg-slate-50'"
                            >{{ n }}m</button>
                            <div class="flex-1 flex items-center gap-1 rounded-lg px-2 py-2" style="border:1px solid #E2E8F0;">
                                <input
                                    type="number"
                                    v-model.number="setupMonths"
                                    min="1" step="1"
                                    class="w-full text-center text-sm font-bold bg-transparent focus:outline-none text-blue-700"
                                />
                                <span class="text-xs text-slate-400 flex-shrink-0">mo</span>
                            </div>
                        </div>
                    </div>

                    <!-- Per installment preview -->
                    <div class="flex items-center justify-between rounded-xl px-4 py-3 text-sm" style="background:#F8FAFC; border:1px solid #E2E8F0;">
                        <span class="text-slate-600">මාසික වාරිකය</span>
                        <span class="font-bold text-gray-800">{{ fmt(setupInstallmentAmt) }}</span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-5 py-4 border-t flex gap-3" style="border-color:#E2E8F0;">
                    <button type="button" @click="setupModal = false"
                        class="flex-1 py-2.5 rounded-xl border text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        style="border-color:#E2E8F0;">
                        Cancel
                    </button>
                    <button type="button" @click="submitSetup" :disabled="settingUp || setupMonths < 1"
                        class="flex-1 py-2.5 rounded-xl text-white text-sm font-bold disabled:opacity-50"
                        style="background:#2563EB;">
                        {{ settingUp ? 'Saving…' : 'සැලසුම සාදන්න' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
