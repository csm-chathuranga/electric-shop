<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, inject } from 'vue';

const t = inject('t');

const props = defineProps({
    plan:     { type: Object, required: true },
    settings: { type: Object, default: () => ({}) },
});

const page    = usePage();
const auth    = computed(() => page.props.auth);
const isAdmin = computed(() => auth.value?.role === 'admin' || auth.value?.user?.role === 'admin');
const canViewDocs = computed(() => {
    const r = auth.value?.role || auth.value?.user?.role;
    return r === 'admin' || r === 'manager';
});

// ── Pay modal ────────────────────────────────────────────────────────────────
const payModal    = ref(null);   // { payment: {...} }
const payAmount   = ref('');
const payMethod   = ref('cash');
const payRef      = ref('');
const payNotes    = ref('');
const paySubmitting = ref(false);

// Month selector shown inside pay modal when paying DP with no schedule yet
const paySetupMonths     = ref(3);
const paySetupGraceDate  = ref('');
const payPresetMonths    = [2, 3, 6, 12];
const showMonthSelector  = computed(() =>
    payModal.value?.installment_no === 0 && !hasSchedule.value
);
const payEffectiveBalance = computed(() =>
    Math.max(0, (props.plan.balance || 0) - (showMonthSelector.value ? payExcess.value : 0))
);
const payInstallmentAmt  = computed(() => {
    if (!showMonthSelector.value || paySetupMonths.value < 1 || !props.plan.balance) return 0;
    return Math.round(payEffectiveBalance.value / paySetupMonths.value * 100) / 100;
});

function openPay(payment) {
    payModal.value       = payment;
    payAmount.value      = payment.amount_due - payment.amount_paid;
    payMethod.value      = 'cash';
    payRef.value         = '';
    payNotes.value       = '';
    paySetupMonths.value = 3;
    paySetupGraceDate.value = '';
}

const payExcess = computed(() => {
    const amt = parseFloat(payAmount.value) || 0;
    const due = payModal.value ? (payModal.value.amount_due - payModal.value.amount_paid) : 0;
    return Math.max(0, amt - due);
});

// ── Settle-all modal ─────────────────────────────────────────────────────────
const settleModal      = ref(false);
const settleMethod     = ref('cash');
const settleRef        = ref('');
const settleNotes      = ref('');
const settleSubmitting = ref(false);

const remainingBalance = computed(() =>
    props.plan.payments?.reduce((s, p) => s + Math.max(0, Number(p.amount_due) - Number(p.amount_paid)), 0) ?? 0
);

function openSettle() {
    settleMethod.value = 'cash';
    settleRef.value    = '';
    settleNotes.value  = '';
    settleModal.value  = true;
}

function submitSettle() {
    if (settleSubmitting.value) return;
    settleSubmitting.value = true;
    router.post(route('installments.settle-all', props.plan.id), {
        payment_method: settleMethod.value,
        reference:      settleRef.value,
        notes:          settleNotes.value,
    }, {
        onSuccess: () => { settleModal.value = false; },
        onFinish:  () => { settleSubmitting.value = false; },
    });
}

function closePay() { payModal.value = null; }

function submitPay() {
    if (paySubmitting.value) return;
    paySubmitting.value = true;
    const payload = {
        amount_paid:    payAmount.value,
        payment_method: payMethod.value,
        reference:      payRef.value,
        notes:          payNotes.value,
    };
    if (showMonthSelector.value && paySetupMonths.value > 0) {
        payload.installments_count = paySetupMonths.value;
        payload.grace_settle_date  = paySetupGraceDate.value || null;
    }
    router.post(route('installments.pay', { plan: props.plan.id, payment: payModal.value.id }), payload, {
        onSuccess: () => { closePay(); },
        onFinish: () => { paySubmitting.value = false; },
    });
}

// ── Document upload ───────────────────────────────────────────────────────────
const docType     = ref('nic_front');
const docLabel    = ref('');
const docFile      = ref(null);
const docUploading = ref(false);
const docError     = ref('');
const fileInput    = ref(null);
const docPreview   = ref(null);   // data URL for image preview
const dragOver     = ref(false);

const docTypes = [
    { value: 'nic_front',     label: 'NIC Front' },
    { value: 'nic_back',      label: 'NIC Back' },
    { value: 'photo',         label: 'Photo' },
    { value: 'address_proof', label: 'Address Proof' },
    { value: 'guarantor_nic', label: 'Guarantor NIC' },
    { value: 'agreement',     label: 'Agreement' },
    { value: 'other',         label: 'Other' },
];

function setDocFile(file) {
    if (!file) return;
    docFile.value  = file;
    docError.value = '';
    docPreview.value = null;
    if (/\.(jpe?g|png|webp|gif)$/i.test(file.name)) {
        const reader = new FileReader();
        reader.onload = e => { docPreview.value = e.target.result; };
        reader.readAsDataURL(file);
    }
}
function onFileChange(e)  { setDocFile(e.target.files[0]); }
function onDrop(e)        { dragOver.value = false; setDocFile(e.dataTransfer.files[0]); }
function clearDocFile()   { docFile.value = null; docPreview.value = null; if (fileInput.value) fileInput.value.value = ''; }

function uploadDoc() {
    if (!docFile.value) { docError.value = 'Please select a file.'; return; }
    docError.value   = '';
    docUploading.value = true;

    const form = new FormData();
    form.append('type',  docType.value);
    form.append('label', docLabel.value);
    form.append('file',  docFile.value);

    router.post(route('installments.documents.upload', props.plan.id), form, {
        forceFormData: true,
        onSuccess: () => {
            clearDocFile();
            docLabel.value = '';
        },
        onError: (errs) => { docError.value = Object.values(errs).flat().join(' '); },
        onFinish: () => { docUploading.value = false; },
    });
}

// ── Setup installments (inline, after DP is paid) ────────────────────────────
const setupMonths     = ref(3);
const setupGraceDate  = ref('');
const settingUp       = ref(false);
const presetMonths    = [2, 3, 6, 12];
const hasSchedule     = computed(() => props.plan.installments_count > 0);
const dpPaid          = computed(() => dpPayment.value?.status === 'paid');

const setupInstallmentAmt = computed(() => {
    if (setupMonths.value < 1 || !props.plan.balance) return 0;
    return Math.round(props.plan.balance / setupMonths.value * 100) / 100;
});

function submitSetup() {
    if (settingUp.value || setupMonths.value < 1) return;
    settingUp.value = true;
    router.post(route('installments.setup-installments', props.plan.id), {
        installments_count: setupMonths.value,
        grace_settle_date:  setupGraceDate.value || null,
    }, {
        onFinish: () => { settingUp.value = false; },
    });
}

// Payments split by type
const installmentPayments = computed(() =>
    (props.plan.payments || []).filter(p => p.installment_no > 0)
);

// ── Delete plan ───────────────────────────────────────────────────────────────
function deletePlan() {
    if (!confirm(`Cancel plan ${props.plan.plan_no}? This will restore stock and cannot be undone.`)) return;
    router.delete(route('installments.destroy', props.plan.id));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt(v) {
    return 'Rs. ' + Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtDate(val) {
    if (!val) return '—';
    const d = new Date(val);
    if (isNaN(d)) return String(val).slice(0, 10);
    return d.toLocaleDateString('en-LK', { year: 'numeric', month: 'short', day: '2-digit' });
}

function isOverdue(dateStr) {
    return dateStr && new Date(dateStr) < new Date(new Date().toDateString());
}

const statusMeta = {
    active:    { label: 'Active',    cls: 'bg-blue-100 text-blue-700' },
    completed: { label: 'Completed', cls: 'bg-green-100 text-green-700' },
    defaulted: { label: 'Defaulted', cls: 'bg-red-100 text-red-700' },
    cancelled: { label: 'Cancelled', cls: 'bg-gray-100 text-gray-500' },
};

const paymentStatus = {
    pending:  { label: 'Pending',  cls: 'bg-yellow-100 text-yellow-700' },
    paid:     { label: 'Paid',     cls: 'bg-green-100 text-green-700' },
    partial:  { label: 'Partial',  cls: 'bg-orange-100 text-orange-700' },
    overdue:  { label: 'Overdue',  cls: 'bg-red-100 text-red-700' },
};

const totalPaid = computed(() => props.plan.payments?.reduce((s, p) => s + Number(p.amount_paid || 0), 0) ?? 0);
const balance   = computed(() => Number(props.plan.total) - totalPaid.value);

// Grace period: the down payment row (installment_no=0) may be partially paid at plan creation
const dpPayment = computed(() => props.plan.payments?.find(p => p.installment_no === 0) ?? null);
const graceBalance = computed(() => {
    const dp = dpPayment.value;
    if (!dp || dp.status === 'paid') return 0;
    return Math.max(0, Number(dp.amount_due) - Number(dp.amount_paid));
});
const graceDaysLeft = computed(() => {
    const dp = dpPayment.value;
    if (!dp || dp.status === 'paid' || !dp.due_date) return null;
    const due  = new Date(dp.due_date);
    const now  = new Date(new Date().toDateString()); // midnight today
    const diff = Math.ceil((due - now) / 86400000);
    return diff;
});

const typeLabel = {
    nic_front:     'NIC Front',
    nic_back:      'NIC Back',
    photo:         'Photo',
    address_proof: 'Address Proof',
    guarantor_nic: 'Guarantor NIC',
    agreement:     'Agreement',
    other:         'Other',
};

function isImage(name) {
    return /\.(jpe?g|png|webp|gif)$/i.test(name || '');
}

// Append ImageKit transformation for a compact thumbnail
function thumbUrl(url) {
    if (!url) return '';
    // ImageKit URL transform: width 300, height 192, crop
    return url.includes('imagekit.io') ? url + '?tr=w-300,h-192,fo-auto' : url;
}
</script>

<template>
    <Head :title="`Plan ${plan.plan_no}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-3">
                    <Link :href="route('installments.index')" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">{{ plan.plan_no }}</h1>
                        <p class="text-sm text-slate-500">{{ plan.customer?.name }}</p>
                    </div>
                    <span class="text-xs font-semibold px-2 py-1 rounded-full" :class="(statusMeta[plan.status] || statusMeta.active).cls">
                        {{ (statusMeta[plan.status] || statusMeta.active).label }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Full settlement button (shown when plan is active and has balance) -->
                    <button v-if="plan.status === 'active' && remainingBalance > 0"
                        @click="openSettle"
                        class="flex items-center gap-1.5 text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors"
                        style="background-color:#15803D;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ t('inst.settle_all') }}
                    </button>
                    <button v-if="isAdmin" @click="deletePlan"
                        class="flex items-center gap-1.5 text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors"
                        style="background-color:#7F1D1D;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        {{ t('inst.cancel_plan') }}
                    </button>
                </div>
            </div>
        </template>

        <!-- Flash -->
        <div v-if="$page.props.flash?.success" class="mb-4 bg-green-50 border border-green-200 rounded-lg px-4 py-3 text-sm text-green-700">
            {{ $page.props.flash.success }}
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <!-- Left: summary + payments + items -->
            <div class="lg:col-span-2 space-y-4">

                <!-- Summary cards -->
                <div class="grid gap-3" :class="plan.interest_amount > 0 ? 'grid-cols-4' : 'grid-cols-3'">
                    <div class="bg-white rounded-xl shadow-sm p-4 text-center" style="border:1px solid #E2E8F0;">
                        <p class="text-xs text-slate-500 mb-1">Total Value</p>
                        <p class="text-lg font-bold text-gray-800">{{ fmt(plan.total) }}</p>
                    </div>
                    <div v-if="plan.interest_amount > 0" class="bg-white rounded-xl shadow-sm p-4 text-center" style="border:1px solid #FED7AA;">
                        <p class="text-xs text-slate-500 mb-1">පොලිය / Interest ({{ plan.interest_rate }}%)</p>
                        <p class="text-lg font-bold text-orange-600">{{ fmt(plan.interest_amount) }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm p-4 text-center" style="border:1px solid #E2E8F0;">
                        <p class="text-xs text-slate-500 mb-1">Total Paid</p>
                        <p class="text-lg font-bold text-green-700">{{ fmt(totalPaid) }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm p-4 text-center" style="border:1px solid #E2E8F0;">
                        <p class="text-xs text-slate-500 mb-1">Balance Due</p>
                        <p class="text-lg font-bold" :class="balance > 0 ? 'text-red-600' : 'text-slate-400'">{{ fmt(balance) }}</p>
                    </div>
                </div>

                <!-- Grace Period Alert — shown when the down payment is partially paid -->
                <div v-if="graceBalance > 0 && plan.status === 'active'"
                    class="rounded-xl px-4 py-3 flex items-start gap-3"
                    :style="graceDaysLeft !== null && graceDaysLeft < 0
                        ? 'background:#FFF5F5; border:1px solid #FECACA;'
                        : 'background:#FFF7ED; border:1px solid #FED7AA;'">
                    <div class="flex-shrink-0 mt-0.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" :class="graceDaysLeft !== null && graceDaysLeft < 0 ? 'text-red-500' : 'text-orange-500'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold" :class="graceDaysLeft !== null && graceDaysLeft < 0 ? 'text-red-700' : 'text-orange-700'">
                            {{ t('inst.grace_balance') }}
                            <span v-if="graceDaysLeft !== null && graceDaysLeft < 0" class="ml-2 text-xs font-bold bg-red-500 text-white px-1.5 py-0.5 rounded-full">Overdue</span>
                        </p>
                        <div class="mt-1 flex flex-wrap gap-3 text-xs">
                            <div>
                                <span class="text-slate-500">{{ t('inst.initial_received') }}:</span>
                                <span class="ml-1 font-semibold text-green-700">{{ fmt(dpPayment?.amount_paid) }}</span>
                            </div>
                            <div>
                                <span class="text-slate-500">Pending:</span>
                                <span class="ml-1 font-bold" :class="graceDaysLeft !== null && graceDaysLeft < 0 ? 'text-red-700' : 'text-orange-700'">{{ fmt(graceBalance) }}</span>
                            </div>
                            <div v-if="dpPayment?.due_date">
                                <span class="text-slate-500">Due by:</span>
                                <span class="ml-1 font-semibold text-gray-700">{{ fmtDate(dpPayment.due_date) }}</span>
                            </div>
                            <div v-if="graceDaysLeft !== null">
                                <span class="font-semibold" :class="graceDaysLeft < 0 ? 'text-red-600' : graceDaysLeft <= 2 ? 'text-orange-600' : 'text-slate-600'">
                                    {{ graceDaysLeft < 0 ? Math.abs(graceDaysLeft) + ' days overdue' : graceDaysLeft === 0 ? 'Due today' : graceDaysLeft + ' days left' }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <!-- Quick-pay grace balance -->
                    <button v-if="dpPayment && dpPayment.status !== 'paid'"
                        @click="openPay(dpPayment)"
                        class="flex-shrink-0 text-xs text-white font-semibold px-3 py-1.5 rounded-lg"
                        :style="graceDaysLeft !== null && graceDaysLeft < 0 ? 'background:#DC2626;' : 'background:#D97706;'">
                        Pay
                    </button>
                </div>

                <!-- Payment schedule — hidden while initial payment has a remaining balance -->
                <div v-if="graceBalance === 0" class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #E2E8F0;">
                    <div class="px-4 py-3 border-b" style="border-color:#E2E8F0; background:#F8FAFC;">
                        <p class="text-sm font-semibold text-gray-700">Payment Schedule</p>
                    </div>
                    <div class="divide-y" style="border-color:#F8FAFC;">

                        <!-- DP row — always shown -->
                        <template v-if="dpPayment">
                            <div class="flex items-center gap-4 px-4 py-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold text-white"
                                    :style="dpPayment.status === 'paid' ? 'background:#16a34a;' : 'background:#2563EB;'">
                                    ↓
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-800">{{ t('inst.down_pmt_label') }}</p>
                                    <p class="text-xs" :class="isOverdue(dpPayment.due_date) && dpPayment.status !== 'paid' ? 'text-red-500 font-semibold' : 'text-slate-400'">
                                        Due: {{ fmtDate(dpPayment.due_date) }}
                                        <span v-if="isOverdue(dpPayment.due_date) && dpPayment.status !== 'paid'" class="ml-1">⚠ Overdue</span>
                                    </p>
                                    <template v-if="dpPayment.status !== 'paid'">
                                        <p v-if="dpPayment.amount_paid > 0" class="text-xs text-green-600">
                                            {{ t('inst.initial_received') }}: {{ fmt(dpPayment.amount_paid) }}
                                        </p>
                                        <p v-if="dpPayment.amount_due - dpPayment.amount_paid > 0" class="text-xs text-orange-600">
                                            {{ t('inst.grace_balance') }}: {{ fmt(dpPayment.amount_due - dpPayment.amount_paid) }}
                                        </p>
                                    </template>
                                    <p v-else class="text-xs text-green-600">Paid: {{ fmt(dpPayment.amount_paid) }}</p>
                                    <p v-if="dpPayment.paid_at" class="text-xs text-slate-400">{{ fmtDate(dpPayment.paid_at) }} via {{ dpPayment.payment_method }}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-sm font-bold text-gray-800">{{ fmt(dpPayment.amount_due) }}</p>
                                    <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full" :class="(paymentStatus[dpPayment.status] || paymentStatus.pending).cls">
                                        {{ (paymentStatus[dpPayment.status] || paymentStatus.pending).label }}
                                    </span>
                                </div>
                                <button v-if="dpPayment.status !== 'paid'"
                                    @click="openPay(dpPayment)"
                                    class="text-xs text-white font-semibold px-3 py-1.5 rounded-lg flex-shrink-0"
                                    style="background-color:#2563EB;">
                                    Pay
                                </button>
                                <div v-else class="w-14 flex-shrink-0"></div>
                            </div>
                        </template>

                        <!-- Installment rows — only when schedule exists -->
                        <template v-if="hasSchedule">
                            <div v-for="payment in installmentPayments" :key="payment.id"
                                class="flex items-center gap-4 px-4 py-3"
                                :class="{ 'bg-red-50': payment.status === 'overdue' }">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold text-white"
                                    :style="payment.status === 'paid' ? 'background:#16a34a;' : payment.status === 'overdue' ? 'background:#dc2626;' : 'background:#64748B;'">
                                    {{ payment.installment_no }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-800">{{ t('nav.installments') }} {{ payment.installment_no }}</p>
                                    <p class="text-xs" :class="isOverdue(payment.due_date) && payment.status !== 'paid' ? 'text-red-500 font-semibold' : 'text-slate-400'">
                                        Due: {{ fmtDate(payment.due_date) }}
                                        <span v-if="isOverdue(payment.due_date) && payment.status !== 'paid'" class="ml-1">⚠ Overdue</span>
                                    </p>
                                    <p v-if="payment.amount_paid > 0" class="text-xs text-green-600">Paid: {{ fmt(payment.amount_paid) }}</p>
                                    <p v-if="payment.paid_at" class="text-xs text-slate-400">{{ fmtDate(payment.paid_at) }} via {{ payment.payment_method }}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-sm font-bold text-gray-800">{{ fmt(payment.amount_due) }}</p>
                                    <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full" :class="(paymentStatus[payment.status] || paymentStatus.pending).cls">
                                        {{ (paymentStatus[payment.status] || paymentStatus.pending).label }}
                                    </span>
                                </div>
                                <button v-if="payment.status !== 'paid'"
                                    @click="openPay(payment)"
                                    class="text-xs text-white font-semibold px-3 py-1.5 rounded-lg flex-shrink-0"
                                    style="background-color:#2563EB;">
                                    Pay
                                </button>
                                <div v-else class="w-14 flex-shrink-0"></div>
                            </div>
                        </template>

                        <!-- No schedule yet: DP paid → show month selector -->
                        <div v-else-if="dpPaid" class="px-4 py-5">
                            <p class="text-sm font-semibold text-gray-700 mb-3">වාරික සැලසුම සාදන්න</p>

                            <!-- Balance info -->
                            <div class="flex items-center justify-between rounded-xl px-4 py-2.5 mb-3 text-sm" style="background:#EFF6FF; border:1px solid #BFDBFE;">
                                <span class="text-blue-700">ශේෂය (Balance)</span>
                                <span class="font-bold text-blue-800">{{ fmt(plan.balance) }}</span>
                            </div>

                            <!-- Month presets -->
                            <label class="block text-xs text-slate-500 mb-1.5">මාස ගණන / Months</label>
                            <div class="flex gap-2 items-center mb-3">
                                <button v-for="n in presetMonths" :key="n"
                                    type="button"
                                    @click="setupMonths = n"
                                    class="w-11 py-2 rounded-lg text-xs font-bold border transition-colors flex-shrink-0"
                                    :class="setupMonths === n ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-slate-600 hover:bg-slate-50'"
                                >{{ n }}m</button>
                                <div class="flex-1 flex items-center gap-1 rounded-lg px-2 py-2" style="border:1px solid #E2E8F0;">
                                    <input type="number" v-model.number="setupMonths" min="1" step="1"
                                        class="w-full text-center text-sm font-bold bg-transparent focus:outline-none text-blue-700" />
                                    <span class="text-xs text-slate-400 flex-shrink-0">mo</span>
                                </div>
                            </div>

                            <!-- Per installment -->
                            <div class="flex justify-between text-xs text-slate-500 mb-3 px-1">
                                <span>මාසික වාරිකය</span>
                                <span class="font-bold text-gray-700">{{ fmt(setupInstallmentAmt) }}</span>
                            </div>

                            <button type="button" @click="submitSetup" :disabled="settingUp"
                                class="w-full py-2.5 rounded-xl text-white text-sm font-bold disabled:opacity-50"
                                style="background:#2563EB;">
                                {{ settingUp ? 'Saving…' : 'සැලසුම සාදන්න' }}
                            </button>
                        </div>

                        <!-- No schedule yet: DP not paid → waiting message -->
                        <div v-else class="px-4 py-5 text-center">
                            <div class="inline-flex items-center gap-2 rounded-xl px-4 py-3 text-sm" style="background:#FFF7ED; border:1px solid #FED7AA;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-orange-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span class="text-orange-700">ප්‍රාරම්භ ගෙවීම සම්පූර්ණ කිරීමෙන් පසු වාරික සැලසුම සකසනු ඇත</span>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Items table -->
                <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border:1px solid #E2E8F0;">
                    <div class="px-4 py-3 border-b" style="border-color:#E2E8F0; background:#F8FAFC;">
                        <p class="text-sm font-semibold text-gray-700">Items</p>
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left" style="border-color:#E2E8F0;">
                                <th class="px-4 py-2 font-semibold text-slate-500">Item</th>
                                <th class="px-4 py-2 font-semibold text-slate-500 text-center w-16">Qty</th>
                                <th class="px-4 py-2 font-semibold text-slate-500 text-right w-28">Price</th>
                                <th class="px-4 py-2 font-semibold text-slate-500 text-right w-28">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y" style="border-color:#F8FAFC;">
                            <tr v-for="item in plan.items" :key="item.id">
                                <td class="px-4 py-2 font-medium text-gray-800">{{ item.product_name }}</td>
                                <td class="px-4 py-2 text-center text-slate-600">{{ item.qty }}</td>
                                <td class="px-4 py-2 text-right text-slate-600">{{ fmt(item.unit_price) }}</td>
                                <td class="px-4 py-2 text-right font-semibold text-gray-800">{{ fmt(item.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t" style="border-color:#E2E8F0; background:#F8FAFC;">
                            <tr v-if="plan.interest_amount > 0">
                                <td colspan="3" class="px-4 py-2 text-right text-slate-500">උප එකතුව / Subtotal</td>
                                <td class="px-4 py-2 text-right text-slate-600">{{ fmt(plan.total - plan.interest_amount) }}</td>
                            </tr>
                            <tr v-if="plan.interest_amount > 0">
                                <td colspan="3" class="px-4 py-2 text-right text-orange-600">පොලිය / Interest ({{ plan.interest_rate }}%)</td>
                                <td class="px-4 py-2 text-right font-semibold text-orange-600">+ {{ fmt(plan.interest_amount) }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="px-4 py-2 text-right font-bold text-gray-700">Total</td>
                                <td class="px-4 py-2 text-right font-bold text-gray-800">{{ fmt(plan.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Right: info + documents -->
            <div class="space-y-4">

                <!-- Plan info -->
                <div class="bg-white rounded-xl shadow-sm p-4" style="border:1px solid #E2E8F0;">
                    <p class="text-sm font-semibold text-gray-700 mb-3">{{ t('inst.plan_details') }}</p>
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Plan No</span>
                            <span class="font-mono font-bold text-blue-700">{{ plan.plan_no }}</span>
                        </div>
                        <div v-if="plan.plan_date" class="flex justify-between">
                            <span class="text-slate-500">{{ t('inst.plan_date') }}</span>
                            <span class="text-gray-700">{{ fmtDate(plan.plan_date) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ t('inst.customer') }}</span>
                            <span class="font-semibold text-gray-700">{{ plan.customer?.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ t('th.phone') }}</span>
                            <span class="text-gray-700">{{ plan.customer?.phone || '—' }}</span>
                        </div>
                        <div v-if="plan.interest_rate > 0" class="flex justify-between">
                            <span class="text-slate-500">{{ t('inst.interest_rate_lbl') }}</span>
                            <span class="font-semibold text-orange-600">{{ plan.interest_rate }}% ({{ fmt(plan.interest_amount) }})</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ t('inst.down_payment') }}</span>
                            <span class="font-semibold text-blue-700">{{ fmt(plan.down_payment) }} ({{ plan.down_payment_percent }}%)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ t('inst.balance') }}</span>
                            <span class="font-semibold text-orange-600">{{ fmt(plan.balance) }}</span>
                        </div>
                        <div v-if="plan.installments_count > 0" class="flex justify-between">
                            <span class="text-slate-500">{{ t('nav.installments') }}</span>
                            <span class="text-gray-700">{{ plan.installments_count }} × {{ fmt(plan.installment_amount) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ t('inst.created_by') }}</span>
                            <span class="text-gray-700">{{ plan.user?.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ t('inst.created') }}</span>
                            <span class="text-gray-700">{{ fmtDate(plan.created_at) }}</span>
                        </div>
                    </div>
                    <p v-if="plan.notes" class="mt-3 text-xs text-slate-500 italic border-t pt-2" style="border-color:#E2E8F0;">{{ plan.notes }}</p>
                </div>

                <!-- Documents -->
                <div class="bg-white rounded-xl shadow-sm p-4" style="border:1px solid #E2E8F0;">
                    <p class="text-sm font-semibold text-gray-700 mb-3">{{ t('inst.documents') }}</p>

                    <!-- Existing docs -->
                    <div v-if="plan.documents?.length > 0" class="grid grid-cols-2 gap-2 mb-4">
                        <a v-for="doc in plan.documents" :key="doc.id"
                            :href="canViewDocs ? route('installments.documents.serve', { plan: plan.id, document: doc.id }) : undefined"
                            :target="canViewDocs ? '_blank' : undefined"
                            class="block rounded-lg overflow-hidden border group relative"
                            style="border-color:#E2E8F0;"
                            :class="canViewDocs ? 'cursor-pointer' : 'cursor-default'">

                            <!-- Image preview -->
                            <img v-if="isImage(doc.original_name)"
                                :src="thumbUrl(doc.file_path)"
                                :alt="typeLabel[doc.type] || doc.type"
                                class="w-full h-24 object-cover bg-slate-100"
                                loading="lazy"
                            />

                            <!-- PDF placeholder -->
                            <div v-else class="w-full h-24 flex items-center justify-center bg-red-50">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    <text x="8" y="17" font-size="5" fill="#f87171" font-family="sans-serif" font-weight="bold">PDF</text>
                                </svg>
                            </div>

                            <!-- Label overlay -->
                            <div class="px-2 py-1.5 bg-white">
                                <p class="text-xs font-semibold text-gray-700 truncate">{{ typeLabel[doc.type] || doc.type }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ doc.original_name }}</p>
                            </div>

                            <!-- Hover overlay -->
                            <div v-if="canViewDocs" class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
                                <span class="opacity-0 group-hover:opacity-100 transition-opacity bg-white text-xs font-bold text-gray-800 px-2 py-1 rounded shadow">Open ↗</span>
                            </div>
                        </a>
                    </div>
                    <p v-else class="text-xs text-slate-400 mb-4">No documents uploaded yet.</p>

                    <!-- Upload form -->
                    <div class="border-t pt-3 space-y-2" style="border-color:#E2E8F0;">
                        <p class="text-xs font-semibold text-gray-600">Upload Document</p>

                        <!-- Type + label row -->
                        <div class="flex gap-2">
                            <select v-model="docType" class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-300">
                                <option v-for="dt in docTypes" :key="dt.value" :value="dt.value">{{ dt.label }}</option>
                            </select>
                        </div>

                        <!-- Drop zone -->
                        <div
                            class="relative rounded-xl border-2 border-dashed transition-colors cursor-pointer"
                            :class="dragOver ? 'border-blue-400 bg-blue-50' : docFile ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-gray-50 hover:border-blue-300 hover:bg-blue-50'"
                            @click="fileInput.click()"
                            @dragover.prevent="dragOver = true"
                            @dragleave="dragOver = false"
                            @drop.prevent="onDrop"
                        >
                            <!-- Preview thumbnail -->
                            <div v-if="docPreview" class="relative">
                                <img :src="docPreview" class="w-full h-28 object-cover rounded-xl" />
                                <button type="button" @click.stop="clearDocFile"
                                    class="absolute top-1.5 right-1.5 bg-black/50 hover:bg-black/70 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs transition-colors">
                                    ×
                                </button>
                            </div>

                            <!-- PDF selected state -->
                            <div v-else-if="docFile" class="flex items-center gap-3 px-3 py-3">
                                <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-gray-700 truncate">{{ docFile.name }}</p>
                                    <p class="text-xs text-slate-400">{{ (docFile.size / 1024).toFixed(0) }} KB</p>
                                </div>
                                <button type="button" @click.stop="clearDocFile"
                                    class="text-slate-400 hover:text-red-500 transition-colors flex-shrink-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Empty state -->
                            <div v-else class="flex flex-col items-center gap-1.5 py-5 px-3">
                                <div class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <p class="text-xs font-semibold text-gray-600">Click or drag file here</p>
                                <p class="text-xs text-slate-400">JPG, PNG or PDF · max 5 MB</p>
                            </div>
                        </div>

                        <input ref="fileInput" type="file" accept=".jpg,.jpeg,.png,.pdf" class="hidden" @change="onFileChange" />

                        <p v-if="docError" class="text-xs text-red-600">{{ docError }}</p>

                        <button type="button" @click="uploadDoc" :disabled="docUploading || !docFile"
                            class="w-full py-2 text-xs font-bold text-white rounded-xl transition-all disabled:opacity-40"
                            :style="docFile && !docUploading ? 'background-color:#2563EB;' : 'background-color:#93C5FD;'">
                            <span v-if="docUploading" class="flex items-center justify-center gap-1.5">
                                <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Uploading…
                            </span>
                            <span v-else>Upload {{ docTypes.find(d => d.value === docType)?.label }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settle-all modal -->
        <div v-if="settleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-sm mx-4">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-bold text-gray-800">{{ t('inst.settle_all_title') }}</h2>
                </div>
                <p class="text-sm text-slate-500 mb-4 ml-10">{{ plan.plan_no }} — {{ plan.customer?.name }}</p>

                <!-- Remaining balance highlight -->
                <div class="rounded-xl px-4 py-3 mb-4 flex items-center justify-between" style="background:#F0FDF4; border:1px solid #BBF7D0;">
                    <span class="text-sm font-semibold text-green-700">{{ t('inst.remaining_balance') }}</span>
                    <span class="text-xl font-bold text-green-700">{{ fmt(remainingBalance) }}</span>
                </div>

                <div class="space-y-3">
                    <!-- Payment method -->
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ t('inst.payment_method') }}</label>
                        <div class="flex gap-2">
                            <button v-for="m in ['cash', 'card', 'qr']" :key="m" type="button"
                                @click="settleMethod = m"
                                class="flex-1 py-1.5 text-xs font-bold rounded-lg border transition-colors"
                                :class="settleMethod === m ? 'bg-green-600 text-white border-green-600' : 'border-gray-200 text-slate-600 hover:bg-slate-50'">
                                {{ m.toUpperCase() }}
                            </button>
                        </div>
                    </div>
                    <!-- Reference -->
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ t('inst.reference') }}</label>
                        <input v-model="settleRef" type="text"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-300"
                            placeholder="Optional…" />
                    </div>
                    <!-- Notes -->
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">{{ t('inst.notes') }}</label>
                        <textarea v-model="settleNotes" rows="2"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-300"></textarea>
                    </div>
                </div>

                <div class="flex gap-3 mt-5">
                    <button type="button" @click="settleModal = false"
                        class="flex-1 py-2 text-sm font-semibold text-slate-600 border border-gray-200 rounded-xl hover:bg-slate-50 transition-colors">
                        {{ t('btn.cancel') }}
                    </button>
                    <button type="button" @click="submitSettle" :disabled="settleSubmitting"
                        class="flex-1 py-2 text-sm font-bold text-white rounded-xl transition-colors disabled:opacity-50"
                        style="background-color:#15803D;">
                        {{ settleSubmitting ? '…' : t('inst.settle_all') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Pay modal -->
        <div v-if="payModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-sm mx-4">
                <h2 class="text-lg font-bold text-gray-800 mb-1">{{ t('inst.record_payment') }}</h2>
                <p class="text-sm text-slate-500 mb-4">
                    {{ payModal.installment_no === 0 ? 'Down Payment' : `Installment ${payModal.installment_no}` }}
                    — Due {{ fmtDate(payModal.due_date) }}
                </p>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Amount (Rs.)</label>
                        <input v-model="payAmount" type="number" min="0.01" step="0.01"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300" />
                        <p class="text-xs text-slate-400 mt-0.5">Balance due: {{ fmt(payModal.amount_due - payModal.amount_paid) }}</p>
                        <!-- Carry-over hint: shown when a previous excess already reduced this installment -->
                        <div v-if="payModal.amount_paid > 0" class="mt-1.5 flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs" style="background:#F0FDF4; border:1px solid #BBF7D0;">
                            <span class="text-green-700">පෙර ගෙවීමෙන් කප්පාදු:</span>
                            <span class="font-semibold text-green-800">{{ fmt(payModal.amount_paid) }}</span>
                            <span class="text-green-600 ml-auto">(මුල් වාරිකය: {{ fmt(payModal.amount_due) }})</span>
                        </div>
                        <!-- Overpayment hint -->
                        <div v-if="payExcess > 0" class="mt-1.5 flex items-start gap-1.5 rounded-lg px-2.5 py-2 text-xs" style="background:#EFF6FF; border:1px solid #BFDBFE;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 110 20A10 10 0 0112 2z" />
                            </svg>
                            <span class="text-blue-700">
                                Excess <strong>{{ fmt(payExcess) }}</strong> will be automatically applied to the next installment.
                            </span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Payment Method</label>
                        <div class="flex gap-2">
                            <button v-for="m in ['cash', 'card', 'qr']" :key="m" type="button"
                                @click="payMethod = m"
                                class="flex-1 py-1.5 text-xs font-bold rounded-lg border transition-colors"
                                :class="payMethod === m ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-slate-600 hover:bg-slate-50'">
                                {{ m.toUpperCase() }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Reference (optional)</label>
                        <input v-model="payRef" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300" placeholder="Cheque No / Txn ID…" />
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Notes (optional)</label>
                        <textarea v-model="payNotes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"></textarea>
                    </div>

                    <!-- Month selector — shown when paying DP with no schedule yet -->
                    <div v-if="showMonthSelector" class="rounded-xl p-3 space-y-2.5" style="background:#F0F9FF; border:1px solid #BAE6FD;">
                        <p class="text-xs font-bold text-cyan-800">වාරික සැලසුම සකසන්න / Setup Installment Plan</p>

                        <!-- Balance info -->
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-500">ශේෂය (Balance)</span>
                            <span class="font-bold text-gray-800">{{ fmt(payEffectiveBalance) }}</span>
                        </div>
                        <div v-if="payExcess > 0" class="flex justify-between text-xs">
                            <span class="text-green-600">අතිරික්ත ගෙවීම් කප්පාදු</span>
                            <span class="text-green-700 font-semibold">- {{ fmt(payExcess) }}</span>
                        </div>

                        <!-- Month presets -->
                        <div class="flex gap-1.5 items-center">
                            <button v-for="n in payPresetMonths" :key="n" type="button"
                                @click="paySetupMonths = n"
                                class="w-10 py-1.5 rounded-lg text-xs font-bold border transition-colors flex-shrink-0"
                                :class="paySetupMonths === n ? 'bg-cyan-600 text-white border-cyan-600' : 'border-gray-200 text-slate-600 hover:bg-slate-50'"
                            >{{ n }}m</button>
                            <div class="flex-1 flex items-center gap-1 rounded-lg px-2 py-1.5" style="border:1px solid #E2E8F0; background:#fff;">
                                <input type="number" v-model.number="paySetupMonths" min="1" step="1"
                                    class="w-full text-center text-xs font-bold bg-transparent focus:outline-none text-cyan-700" />
                                <span class="text-xs text-slate-400 flex-shrink-0">mo</span>
                            </div>
                        </div>

                        <!-- Per installment -->
                        <div class="flex justify-between text-xs px-0.5">
                            <span class="text-slate-500">මාසික වාරිකය</span>
                            <span class="font-bold text-cyan-800">{{ fmt(payInstallmentAmt) }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 mt-5">
                    <button type="button" @click="closePay"
                        class="flex-1 py-2 text-sm font-semibold text-slate-600 border border-gray-200 rounded-xl hover:bg-slate-50 transition-colors">
                        Cancel
                    </button>
                    <button type="button" @click="submitPay" :disabled="paySubmitting"
                        class="flex-1 py-2 text-sm font-bold text-white rounded-xl transition-colors disabled:opacity-50"
                        style="background-color:#2563EB;">
                        {{ paySubmitting ? 'Saving…' : 'Record Payment' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
