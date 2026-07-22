<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, inject } from 'vue';
import axios from 'axios';
import { getProducts } from '@/stores/productCache';

const t = inject('t');

const props = defineProps({
    customers:           { type: Array,  default: () => [] },
    defaultInterestRate: { type: Number, default: 10 },
    defaultGraceDays:    { type: Number, default: 7 },
});

// ── State ─────────────────────────────────────────────────────────────────────
const allProducts       = ref([]);
const searchQuery       = ref('');
const searchResults     = ref([]);
const showDropdown      = ref(false);
const selectedCustomer  = ref(null);
const customerHistory   = ref(null);
const historyLoading    = ref(false);

// ── Customer search ────────────────────────────────────────────────────────────
const customerSearch    = ref('');
const showCustomerDrop  = ref(false);
const allCustomers      = ref([...props.customers]);   // local copy — grows when new customer added

const filteredCustomers = computed(() => {
    const q = customerSearch.value.trim().toLowerCase();
    if (!q) return allCustomers.value.slice(0, 20);
    return allCustomers.value.filter(c =>
        c.name.toLowerCase().includes(q) ||
        (c.phone && c.phone.includes(q)) ||
        (c.email && c.email.toLowerCase().includes(q))
    ).slice(0, 20);
});

// ── Quick customer registration ────────────────────────────────────────────────
const showQuickReg   = ref(false);
const quickName      = ref('');
const quickPhone     = ref('');
const quickSaving    = ref(false);
const quickError     = ref('');

function openQuickReg() {
    quickName.value  = customerSearch.value.trim();  // pre-fill with what was typed
    quickPhone.value = '';
    quickError.value = '';
    showQuickReg.value = true;
}

async function saveQuickCustomer() {
    if (!quickName.value.trim()) { quickError.value = 'Name is required.'; return; }
    quickError.value = '';
    quickSaving.value = true;
    try {
        const res = await axios.post(route('customers.quick-store'), {
            name:  quickName.value.trim(),
            phone: quickPhone.value.trim() || null,
        });
        const c = res.data.customer;
        allCustomers.value.unshift(c);   // add to local list
        selectCustomer(c);
        customerSearch.value = '';
        showCustomerDrop.value = false;
        showQuickReg.value = false;
    } catch (err) {
        quickError.value = err?.response?.data?.message || 'Network error. Try again.';
    } finally {
        quickSaving.value = false;
    }
}
function localDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const todayStr = localDate(new Date());

const cart             = ref([]);
const planDate         = ref(todayStr);
const downPaymentPct   = ref(30);
const installmentCount = ref(3);
const priceMode        = ref('retail'); // 'retail' | 'wholesale'
const interestRate     = ref(props.defaultInterestRate);
const graceEnabled     = ref(false);
const dpGraceDays      = ref(props.defaultGraceDays > 0 ? props.defaultGraceDays : 7);
const notes            = ref('');
const submitting       = ref(false);
const errorMsg         = ref('');

// ── Load products ──────────────────────────────────────────────────────────────
onMounted(async () => {
    allProducts.value = await getProducts();
});

// ── Search ─────────────────────────────────────────────────────────────────────
function onSearchInput(e) {
    const q = (e?.target?.value ?? searchQuery.value).trim().toLowerCase();
    if (!q) { searchResults.value = []; showDropdown.value = false; return; }
    searchResults.value = allProducts.value.filter(p =>
        p.name.toLowerCase().includes(q) ||
        (p.name_si && p.name_si.includes(q)) ||
        (p.barcode && p.barcode.includes(q))
    ).slice(0, 15);
    showDropdown.value = searchResults.value.length > 0;
}

function hideDropdown() { setTimeout(() => { showDropdown.value = false; }, 150); }

async function selectCustomer(customer) {
    selectedCustomer.value = customer;
    customerHistory.value  = null;
    if (!customer) return;
    historyLoading.value = true;
    try {
        const res  = await fetch(route('installments.customer-summary', customer.id));
        const data = await res.json();
        customerHistory.value = data;
    } catch { /* ignore */ } finally {
        historyLoading.value = false;
    }
}

// ── Cart ───────────────────────────────────────────────────────────────────────
function addToCart(product) {
    searchQuery.value  = '';
    searchResults.value = [];
    showDropdown.value = false;

    const existing = cart.value.find(i => i.product_id === product.id);
    if (existing) {
        existing.qty++;
        recalc(existing);
        return;
    }
    const item = {
        _product:     product,                // keep ref for price-mode switching
        product_id:   product.id,
        product_name: product.name_si ? `${product.name} / ${product.name_si}` : product.name,
        barcode:      product.barcode || '',
        qty:          1,
        unit_price:   effectivePrice(product),
        cost_price:   parseFloat(product.cost_price) || 0,
        discount:     0,
        total:        0,
        stock_qty:    product.stock_qty || 0,
    };
    recalc(item);
    cart.value.push(item);
}

function recalc(item) {
    item.total = Math.max(0, item.qty * item.unit_price - Number(item.discount || 0));
}

function removeItem(idx) { cart.value.splice(idx, 1); }

function updateQty(item, val) {
    const n = parseFloat(val);
    if (isNaN(n) || n <= 0) return;
    item.qty = Math.min(n, item.stock_qty > 0 ? item.stock_qty : Infinity);
    recalc(item);
}

// ── Totals ─────────────────────────────────────────────────────────────────────
const subtotal       = computed(() => cart.value.reduce((s, i) => s + i.total, 0));
const interestAmount = computed(() => Math.round(subtotal.value * interestRate.value * 100) / 10000);
const total          = computed(() => subtotal.value + interestAmount.value);

// downPaymentAmt = REQUIRED initial payment (% of total) — drives installment calculation
const downPaymentAmt = ref(0);
let _suppressPctWatch = false;

// initialPaidAmt = what the customer actually hands over today (can be less than required)
// Defaults to the full required amount; resets when required amount changes
const initialPaidAmt     = ref(0);
const gracePeriodBalance = computed(() => Math.max(0, r2(downPaymentAmt.value - initialPaidAmt.value)));

// When total changes, recalculate required DP from the current percentage
watch(total, (newTotal) => {
    downPaymentAmt.value = r2(newTotal * downPaymentPct.value / 100);
    initialPaidAmt.value = downPaymentAmt.value; // reset to full payment when cart changes
});

// When slider moves, recalculate required DP
watch(downPaymentPct, (pct) => {
    if (_suppressPctWatch) return;
    downPaymentAmt.value = r2(total.value * pct / 100);
    initialPaidAmt.value = downPaymentAmt.value; // reset to full payment when % changes
});

// Called when user edits the required DP amount directly
function onDownPaymentAmtInput(raw) {
    const amt = Math.min(Math.max(0, parseFloat(raw) || 0), total.value);
    downPaymentAmt.value = r2(amt);
    initialPaidAmt.value = downPaymentAmt.value; // reset initial paid to match new required
    _suppressPctWatch = true;
    downPaymentPct.value = total.value > 0
        ? Math.min(90, Math.max(10, Math.round(amt / total.value * 100)))
        : downPaymentPct.value;
    _suppressPctWatch = false;
}

// Auto-enable grace period when customer pays less than the required down payment
watch(gracePeriodBalance, (bal) => {
    if (bal > 0) graceEnabled.value = true;
});

// Called when user types in the "Initial Payment Received" field
function onInitialPaidInput(raw) {
    const amt = Math.min(Math.max(0, parseFloat(raw) || 0), downPaymentAmt.value);
    initialPaidAmt.value = r2(amt);
}

function r2(v) { return Math.round(v * 100) / 100; }

// Price mode helpers
function effectivePrice(product) {
    if (priceMode.value === 'wholesale' && parseFloat(product.wholesale_price) > 0) {
        return parseFloat(product.wholesale_price);
    }
    return parseFloat(product.selling_price) || 0;
}

watch(priceMode, () => {
    cart.value.forEach(item => {
        item.unit_price = effectivePrice(item._product);
        recalc(item);
    });
});

// Installment count helpers
const presetCounts = [2, 3, 6];

// Balance for monthly installments = total − REQUIRED DP (not affected by initial paid amount)
const balance        = computed(() => Math.max(0, r2(total.value - downPaymentAmt.value)));
const installmentAmt = computed(() => installmentCount.value > 0 ? balance.value / installmentCount.value : 0);

// Down payment due date = plan_date + grace days (or plan_date if no grace)
const dpDueDate = computed(() => {
    const d = new Date(planDate.value + 'T00:00:00');
    if (graceEnabled.value && dpGraceDays.value > 0) {
        d.setDate(d.getDate() + dpGraceDays.value);
    }
    return localDate(d);
});

function fmt(v) {
    return 'Rs. ' + Number(v || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const editingPriceIdx = ref(null);
const editingPriceVal = ref('');
function startPriceEdit(idx, item) {
    editingPriceIdx.value = idx;
    editingPriceVal.value = String(item.unit_price);
}
function commitPriceEdit(item) {
    item.unit_price = parseFloat(String(editingPriceVal.value).replace(/,/g, '')) || 0;
    recalc(item);
    editingPriceIdx.value = null;
}

// ── Schedule preview ───────────────────────────────────────────────────────────
const schedule = computed(() => {
    const rows = [];
    const base = new Date(planDate.value + 'T00:00:00');
    rows.push({ no: 0, label: t('inst.down_pmt_label'), due: dpDueDate.value, amount: downPaymentAmt.value });
    for (let i = 1; i <= installmentCount.value; i++) {
        const d = new Date(base);
        d.setMonth(d.getMonth() + i);
        const isLast = i === installmentCount.value;
        const amt = isLast
            ? balance.value - (installmentAmt.value * (installmentCount.value - 1))
            : installmentAmt.value;
        rows.push({ no: i, label: `${t('nav.installments')} ${i}`, due: localDate(d), amount: Math.round(amt * 100) / 100 });
    }
    return rows;
});

// ── Submit ─────────────────────────────────────────────────────────────────────
function submit() {
    errorMsg.value = '';
    if (!selectedCustomer.value) { errorMsg.value = 'Please select a customer.'; return; }
    if (cart.value.length === 0)  { errorMsg.value = 'Add at least one item.'; return; }
    if (total.value <= 0)         { errorMsg.value = 'Total must be greater than 0.'; return; }

    submitting.value = true;
    router.post(route('installments.store'), {
        customer_id:          selectedCustomer.value.id,
        items:                cart.value.map(i => ({
            product_id:   i.product_id,
            product_name: i.product_name,
            qty:          i.qty,
            unit_price:   i.unit_price,
            cost_price:   i.cost_price,
            discount:     i.discount,
            total:        i.total,
        })),
        plan_date:            planDate.value,
        subtotal:             subtotal.value,
        discount:             0,
        total:                total.value,
        down_payment_amount:  downPaymentAmt.value,
        down_payment_percent: downPaymentPct.value,
        initial_paid:         initialPaidAmt.value,
        installments_count:   installmentCount.value,
        interest_rate:        interestRate.value,
        dp_grace_days:        graceEnabled.value ? dpGraceDays.value : 0,
        notes:                notes.value,
    }, {
        onError: (errs) => {
            errorMsg.value = Object.values(errs).flat().join(' ');
            submitting.value = false;
        },
        onFinish: () => { submitting.value = false; },
    });
}
</script>

<template>
    <Head :title="t('inst.new_plan')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('installments.index')" class="text-slate-400 hover:text-slate-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-bold text-gray-800">{{ t('inst.new_plan') }}</h1>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <!-- Left: items -->
            <div class="lg:col-span-2 space-y-4">

                <!-- Plan Date (for backdating old records) -->
                <div class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-4" style="border:1px solid #E2E8F0;">
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <span class="text-sm font-semibold text-gray-700">{{ t('inst.plan_date') }}</span>
                    </div>
                    <input
                        v-model="planDate"
                        type="date"
                        :max="todayStr"
                        class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                    />
                    <p v-if="planDate !== todayStr" class="text-xs text-orange-600 font-medium flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                        {{ t('inst.plan_date_hint') }}
                    </p>
                </div>

                <!-- Customer -->
                <div class="bg-white rounded-xl shadow-sm p-4" style="border:1px solid #E2E8F0;">
                    <p class="text-sm font-semibold text-gray-700 mb-2">{{ t('inst.customer') }} <span class="text-red-500">*</span></p>

                    <!-- Selected badge -->
                    <div v-if="selectedCustomer" class="flex items-center gap-3 border border-blue-200 bg-blue-50 rounded-lg px-3 py-2 mb-2">
                        <div class="w-8 h-8 rounded-full bg-blue-200 flex items-center justify-center flex-shrink-0 text-sm font-bold text-blue-700">
                            {{ selectedCustomer.name.charAt(0).toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate">{{ selectedCustomer.name }}</p>
                            <p class="text-xs text-slate-500">{{ selectedCustomer.phone || selectedCustomer.email || '' }}</p>
                        </div>
                        <button type="button" @click="selectCustomer(null); customerSearch = ''; showCustomerDrop = false;"
                            class="text-slate-400 hover:text-red-500 transition-colors flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Search input -->
                    <div v-if="!selectedCustomer" class="relative">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z" />
                            </svg>
                            <input
                                v-model="customerSearch"
                                type="text"
                                placeholder="Search by name or phone…"
                                class="w-full border border-gray-300 rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                                @focus="showCustomerDrop = true"
                                @blur="setTimeout(() => showCustomerDrop = false, 150)"
                            />
                        </div>

                        <!-- Dropdown -->
                        <div v-if="showCustomerDrop" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-xl shadow-lg z-20 overflow-hidden">
                            <div class="max-h-52 overflow-y-auto">
                                <p v-if="filteredCustomers.length === 0" class="text-xs text-slate-400 text-center py-4">No customers found.</p>
                                <div
                                    v-for="c in filteredCustomers" :key="c.id"
                                    class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-blue-50 transition-colors"
                                    @mousedown.prevent="selectCustomer(c); customerSearch = ''; showCustomerDrop = false;"
                                >
                                    <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0 text-xs font-bold text-slate-600">
                                        {{ c.name.charAt(0).toUpperCase() }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-800 truncate">{{ c.name }}</p>
                                        <p class="text-xs text-slate-400 truncate">{{ c.phone || c.email || 'No contact' }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick register footer -->
                            <div class="border-t" style="border-color:#E2E8F0;">
                                <button type="button"
                                    class="w-full flex items-center gap-2 px-3 py-2.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 transition-colors"
                                    @mousedown.prevent="openQuickReg">
                                    <div class="w-6 h-6 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                    </div>
                                    Add "{{ customerSearch.trim() || 'New Customer' }}"
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Loading -->
                    <div v-if="historyLoading" class="mt-3 flex items-center gap-2 text-xs text-slate-400">
                        <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Loading history…
                    </div>

                    <!-- Customer history -->
                    <div v-else-if="customerHistory" class="mt-3">

                        <!-- No plans -->
                        <div v-if="customerHistory.total_plans === 0" class="flex items-center gap-2 text-xs text-green-700 bg-green-50 border border-green-200 rounded-lg px-3 py-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            New customer — no existing installment plans.
                        </div>

                        <!-- Has plans -->
                        <div v-else class="space-y-2">

                            <!-- Summary chips -->
                            <div class="grid grid-cols-4 gap-2">
                                <div class="rounded-lg px-2 py-2 text-center" style="background:#EFF6FF; border:1px solid #BFDBFE;">
                                    <p class="text-base font-bold text-blue-700">{{ customerHistory.total_plans }}</p>
                                    <p class="text-xs text-blue-500">Plans</p>
                                </div>
                                <div class="rounded-lg px-2 py-2 text-center"
                                    :style="customerHistory.active_plans > 0 ? 'background:#FFF7ED; border:1px solid #FED7AA;' : 'background:#F0FDF4; border:1px solid #BBF7D0;'">
                                    <p class="text-base font-bold" :class="customerHistory.active_plans > 0 ? 'text-orange-600' : 'text-green-600'">{{ customerHistory.active_plans }}</p>
                                    <p class="text-xs" :class="customerHistory.active_plans > 0 ? 'text-orange-400' : 'text-green-400'">Active</p>
                                </div>
                                <div class="rounded-lg px-2 py-2 text-center" style="background:#F0FDF4; border:1px solid #BBF7D0;">
                                    <p class="text-base font-bold text-green-700">{{ customerHistory.completed_plans }}</p>
                                    <p class="text-xs text-green-500">Done</p>
                                </div>
                                <div class="rounded-lg px-2 py-2 text-center"
                                    :style="customerHistory.overdue_count > 0 ? 'background:#FFF5F5; border:1px solid #FECACA;' : 'background:#F8FAFC; border:1px solid #E2E8F0;'">
                                    <p class="text-base font-bold" :class="customerHistory.overdue_count > 0 ? 'text-red-600' : 'text-slate-400'">{{ customerHistory.overdue_count }}</p>
                                    <p class="text-xs" :class="customerHistory.overdue_count > 0 ? 'text-red-400' : 'text-slate-400'">Late</p>
                                </div>
                            </div>

                            <!-- Outstanding balance warning -->
                            <div v-if="customerHistory.total_balance > 0"
                                class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-xs"
                                style="background:#FFF7ED; border:1px solid #FED7AA;">
                                <div class="flex items-center gap-1.5 text-orange-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                    </svg>
                                    Outstanding balance across all plans
                                </div>
                                <span class="font-bold text-orange-700">{{ fmt(customerHistory.total_balance) }}</span>
                            </div>

                            <!-- Overdue payments -->
                            <div v-if="customerHistory.overdue_count > 0" class="rounded-lg overflow-hidden" style="border:1px solid #FECACA;">
                                <div class="flex items-center justify-between px-3 py-1.5" style="background:#FFF5F5;">
                                    <div class="flex items-center gap-1.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span class="text-xs font-bold text-red-700">Late Payments ({{ customerHistory.overdue_count }})</span>
                                    </div>
                                    <span class="text-xs font-bold text-red-700">{{ fmt(customerHistory.overdue_amount) }}</span>
                                </div>
                                <div class="divide-y" style="border-color:#FEE2E2;">
                                    <div v-for="op in customerHistory.overdue_payments" :key="op.id"
                                        class="flex items-center gap-3 px-3 py-2 bg-red-50">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="text-xs font-mono font-bold text-red-700">{{ op.plan_no }}</span>
                                                <span class="text-xs text-red-500">— {{ op.installment }}</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 mt-0.5">
                                                <span class="text-xs text-red-400">Due {{ op.due_date }}</span>
                                                <span class="text-xs font-bold text-white bg-red-500 px-1.5 py-0.5 rounded-full">{{ op.days_overdue }}d late</span>
                                            </div>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <p class="text-xs font-bold text-red-700">{{ fmt(op.balance) }}</p>
                                            <p v-if="op.amount_paid > 0" class="text-xs text-red-400">Partial: {{ fmt(op.amount_paid) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Plans list -->
                            <div class="rounded-lg overflow-hidden" style="border:1px solid #E2E8F0;">
                                <div class="px-3 py-1.5 text-xs font-semibold text-slate-500" style="background:#F8FAFC;">Recent Plans</div>
                                <div class="divide-y" style="border-color:#F1F5F9;">
                                    <div v-for="p in customerHistory.plans.slice(0, 5)" :key="p.id"
                                        class="px-3 py-2">
                                        <!-- Top row: plan no + status + amount -->
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-1.5 flex-wrap">
                                                    <span class="text-xs font-mono font-bold text-blue-700">{{ p.plan_no }}</span>
                                                    <span class="text-xs px-1.5 py-0.5 rounded-full font-semibold"
                                                        :class="{
                                                            'bg-blue-100 text-blue-700':   p.status === 'active',
                                                            'bg-green-100 text-green-700': p.status === 'completed',
                                                            'bg-red-100 text-red-700':     p.status === 'defaulted',
                                                            'bg-gray-100 text-gray-500':   p.status === 'cancelled',
                                                        }">{{ p.status }}</span>
                                                </div>
                                                <p class="text-xs text-slate-400">{{ p.created_at }}</p>
                                            </div>
                                            <div class="text-right flex-shrink-0">
                                                <p class="text-xs font-bold text-gray-700">{{ fmt(p.total) }}</p>
                                                <p v-if="p.balance > 0" class="text-xs text-orange-500">{{ fmt(p.balance) }} left</p>
                                                <p v-else class="text-xs text-green-500">Settled</p>
                                            </div>
                                        </div>
                                        <!-- Payment habit row -->
                                        <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                            <span v-if="p.on_time_count > 0"
                                                class="inline-flex items-center gap-0.5 text-xs font-semibold bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full">
                                                ✓ {{ p.on_time_count }} on-time
                                            </span>
                                            <span v-if="p.late_count > 0"
                                                class="inline-flex items-center gap-0.5 text-xs font-semibold bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full">
                                                ⚠ {{ p.late_count }} late
                                            </span>
                                            <span v-if="p.on_time_count === 0 && p.late_count === 0 && p.total_payments > 0"
                                                class="text-xs text-slate-400">No payments yet</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product search -->
                <div class="bg-white rounded-xl shadow-sm p-4" style="border:1px solid #E2E8F0;">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-semibold text-gray-700">{{ t('inst.add_items') }}</p>
                        <!-- Retail / Wholesale toggle -->
                        <div class="flex rounded-lg overflow-hidden text-xs font-bold" style="border:1px solid #E2E8F0;">
                            <button type="button"
                                @click="priceMode = 'retail'"
                                class="px-3 py-1.5 transition-colors"
                                :class="priceMode === 'retail' ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-50'">
                                Retail
                            </button>
                            <button type="button"
                                @click="priceMode = 'wholesale'"
                                class="px-3 py-1.5 transition-colors"
                                :class="priceMode === 'wholesale' ? 'bg-purple-600 text-white' : 'text-slate-500 hover:bg-slate-50'">
                                Wholesale
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search product name or barcode…"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                            @input="onSearchInput($event)"
                            @blur="hideDropdown"
                        />
                        <div v-if="showDropdown" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-20 max-h-60 overflow-y-auto">
                            <div
                                v-for="p in searchResults" :key="p.id"
                                class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-blue-50 transition-colors"
                                @mousedown.prevent="addToCart(p)"
                            >
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-800 truncate">{{ p.name }}</p>
                                    <p v-if="p.name_si" class="text-xs text-slate-400 truncate">{{ p.name_si }}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-sm font-bold" :class="priceMode === 'wholesale' ? 'text-purple-700' : 'text-blue-700'">
                                        {{ fmt(effectivePrice(p)) }}
                                    </p>
                                    <p v-if="priceMode === 'wholesale' && p.selling_price" class="text-xs text-slate-400 line-through">{{ fmt(p.selling_price) }}</p>
                                    <p class="text-xs text-slate-400">Stock: {{ p.stock_qty }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cart table -->
                    <div v-if="cart.length > 0" class="mt-3 overflow-x-auto">
                        <!-- Price mode banner -->
                        <div v-if="priceMode === 'wholesale'"
                            class="mb-2 flex items-center gap-1.5 text-xs font-semibold px-2 py-1 rounded-lg"
                            style="background:#F3E8FF; color:#7C3AED; border:1px solid #E9D5FF;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5l6 6v5a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z" />
                            </svg>
                            Wholesale prices applied
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-slate-500" style="border-color:#E2E8F0;">
                                    <th class="pb-2 font-semibold">Item</th>
                                    <th class="pb-2 font-semibold text-center w-20">Qty</th>
                                    <th class="pb-2 font-semibold text-right w-28">
                                        Price
                                        <span v-if="priceMode === 'wholesale'" class="ml-1 text-xs text-purple-500">(WS)</span>
                                    </th>
                                    <th class="pb-2 font-semibold text-right w-28">Total</th>
                                    <th class="pb-2 w-8"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y" style="border-color:#F8FAFC;">
                                <tr v-for="(item, idx) in cart" :key="idx">
                                    <td class="py-2 pr-2">
                                        <p class="font-medium text-gray-800 leading-tight">{{ item.product_name }}</p>
                                        <p class="text-xs text-slate-400 font-mono">{{ item.barcode }}</p>
                                    </td>
                                    <td class="py-2 text-center">
                                        <input
                                            type="number"
                                            :value="item.qty"
                                            min="1"
                                            :max="item.stock_qty || undefined"
                                            class="w-16 text-center border border-gray-300 rounded px-1 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                                            @change="e => updateQty(item, e.target.value)"
                                        />
                                    </td>
                                    <td class="py-2 text-right">
                                        <input
                                            type="text"
                                            :value="editingPriceIdx === idx ? editingPriceVal : Number(item.unit_price).toLocaleString('en-LK', { minimumFractionDigits: 0, maximumFractionDigits: 0 })"
                                            @focus="startPriceEdit(idx, item); $event.target.select()"
                                            @input="editingPriceVal = $event.target.value"
                                            @blur="commitPriceEdit(item)"
                                            @keydown.enter.prevent="$event.target.blur()"
                                            class="w-28 text-right border rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400"
                                            :class="priceMode === 'wholesale' ? 'text-purple-700 border-purple-200' : 'text-gray-700 border-gray-200'"
                                        />
                                    </td>
                                    <td class="py-2 text-right font-semibold text-gray-800">{{ fmt(item.total) }}</td>
                                    <td class="py-2 text-center">
                                        <button type="button" @click="removeItem(idx)" class="text-red-400 hover:text-red-600 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-3 text-sm text-slate-400 text-center py-4">No items added yet.</p>
                </div>

                <!-- Notes -->
                <div class="bg-white rounded-xl shadow-sm p-4" style="border:1px solid #E2E8F0;">
                    <p class="text-sm font-semibold text-gray-700 mb-2">{{ t('inst.notes') }}</p>
                    <textarea v-model="notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300" :placeholder="t('lbl.optional') + '…'"></textarea>
                </div>
            </div>

            <!-- Right: plan settings + schedule -->
            <div class="space-y-4">

                <!-- Plan settings -->
                <div class="bg-white rounded-xl shadow-sm p-4" style="border:1px solid #E2E8F0;">
                    <p class="text-sm font-semibold text-gray-700 mb-3">{{ t('inst.plan_settings') }}</p>
                    <div class="space-y-3">

                        <!-- Down payment — required % + editable amount -->
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">{{ t('inst.dp_percent') }}</label>
                            <!-- Slider row -->
                            <div class="flex items-center gap-2 mb-1.5">
                                <input type="range" v-model.number="downPaymentPct" min="10" max="90" step="5" class="flex-1" />
                                <span class="text-sm font-bold text-blue-700 w-10 text-right">{{ downPaymentPct }}%</span>
                            </div>
                            <!-- Required DP amount -->
                            <div class="flex items-center gap-2 rounded-lg px-2 py-1.5" style="background:#EFF6FF; border:1px solid #BFDBFE;">
                                <span class="text-xs font-semibold text-blue-600 flex-shrink-0">Rs.</span>
                                <input
                                    type="number"
                                    :value="downPaymentAmt"
                                    min="0"
                                    :max="total"
                                    step="1"
                                    class="flex-1 bg-transparent text-sm font-bold text-blue-700 focus:outline-none min-w-0"
                                    @change="e => onDownPaymentAmtInput(e.target.value)"
                                    @blur="e => onDownPaymentAmtInput(e.target.value)"
                                />
                                <span class="text-xs text-blue-400 flex-shrink-0">/ {{ fmt(total) }}</span>
                            </div>

                            <!-- Initial payment received today (can be less than required) -->
                            <div class="mt-2">
                                <label class="block text-xs text-slate-500 mb-1">{{ t('inst.initial_received') }}</label>
                                <div class="flex items-center gap-2 rounded-lg px-2 py-1.5" style="background:#F0FDF4; border:1px solid #86EFAC;">
                                    <span class="text-xs font-semibold text-green-600 flex-shrink-0">Rs.</span>
                                    <input
                                        type="number"
                                        :value="initialPaidAmt"
                                        min="0"
                                        :max="downPaymentAmt"
                                        step="1"
                                        class="flex-1 bg-transparent text-sm font-bold text-green-700 focus:outline-none min-w-0"
                                        @change="e => onInitialPaidInput(e.target.value)"
                                        @blur="e => onInitialPaidInput(e.target.value)"
                                    />
                                    <span class="text-xs text-green-400 flex-shrink-0">/ {{ fmt(downPaymentAmt) }}</span>
                                </div>
                                <!-- Grace balance (shown when customer pays less than required) -->
                                <div v-if="gracePeriodBalance > 0"
                                    class="mt-1.5 flex items-center justify-between rounded-lg px-2 py-1.5 text-xs"
                                    style="background:#FFF7ED; border:1px solid #FED7AA;">
                                    <div class="flex items-center gap-1.5 text-orange-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span>{{ t('inst.grace_balance') }}
                                            <span v-if="graceEnabled"> ({{ dpGraceDays }}d)</span>
                                        </span>
                                    </div>
                                    <span class="font-bold text-orange-700">{{ fmt(gracePeriodBalance) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Installment count -->
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">{{ t('inst.inst_count') }}</label>
                            <div class="flex gap-2 items-center">
                                <!-- Preset quick-pick buttons -->
                                <button
                                    v-for="n in presetCounts" :key="n"
                                    type="button"
                                    @click="installmentCount = n"
                                    class="w-10 py-1.5 rounded-lg text-xs font-bold border transition-colors flex-shrink-0"
                                    :class="installmentCount === n
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : 'border-gray-200 text-slate-600 hover:bg-slate-50'"
                                >{{ n }}</button>
                                <!-- Custom months input — v-model so typing is uninterrupted -->
                                <div class="flex-1 flex items-center gap-1 rounded-lg px-2 py-1" style="border:1px solid #E2E8F0;">
                                    <input
                                        type="number"
                                        v-model.number="installmentCount"
                                        min="1"
                                        step="1"
                                        class="w-full text-center text-xs font-bold bg-transparent focus:outline-none"
                                        :class="!presetCounts.includes(installmentCount) ? 'text-blue-700' : 'text-slate-400'"
                                    />
                                    <span class="text-xs text-slate-400 flex-shrink-0">mo</span>
                                </div>
                            </div>
                        </div>

                        <!-- Interest rate -->
                        <div class="pt-2 border-t" style="border-color:#E2E8F0;">
                            <label class="block text-xs text-slate-500 mb-1">{{ t('inst.interest_rate') }}</label>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    v-model.number="interestRate"
                                    min="0" max="100" step="0.5"
                                    class="w-20 border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-orange-300"
                                />
                                <span class="text-xs text-slate-500">
                                    % — {{ t('inst.interest_adds') }} {{ fmt(interestAmount) }}
                                </span>
                            </div>
                        </div>

                        <!-- Down payment grace period — optional toggle -->
                        <div class="pt-1">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-xs text-slate-500">{{ t('inst.grace_period') }}</label>
                                <!-- Toggle -->
                                <button
                                    type="button"
                                    @click="graceEnabled = !graceEnabled"
                                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0"
                                    :style="graceEnabled ? 'background:#2563EB;' : 'background:#D1D5DB;'"
                                >
                                    <span
                                        class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform"
                                        :class="graceEnabled ? 'translate-x-4' : 'translate-x-1'"
                                    ></span>
                                </button>
                            </div>
                            <div v-if="graceEnabled" class="flex items-center gap-2">
                                <input
                                    type="number"
                                    v-model.number="dpGraceDays"
                                    min="1" max="365" step="1"
                                    class="w-20 border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-300"
                                />
                                <span class="text-xs text-slate-500">
                                    {{ t('inst.grace_days') }} — {{ t('inst.grace_due_by') }}: <strong>{{ dpDueDate }}</strong>
                                </span>
                            </div>
                            <p v-else class="text-xs text-slate-400 italic">{{ t('inst.no_grace') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Payment schedule preview -->
                <div class="bg-white rounded-xl shadow-sm p-4" style="border:1px solid #E2E8F0;">
                    <p class="text-sm font-semibold text-gray-700 mb-3">{{ t('inst.schedule') }}</p>

                    <div class="space-y-2 mb-4">
                        <div class="flex justify-between text-xs text-slate-500">
                            <span>{{ t('inst.items_subtotal') }}</span>
                            <span class="font-semibold text-gray-800">{{ fmt(subtotal) }}</span>
                        </div>
                        <div v-if="interestRate > 0" class="flex justify-between text-xs text-slate-500">
                            <span class="text-orange-600">{{ t('inst.interest_label') }} ({{ interestRate }}%)</span>
                            <span class="font-semibold text-orange-600">+ {{ fmt(interestAmount) }}</span>
                        </div>
                        <div class="flex justify-between text-xs border-t pt-2" style="border-color:#E2E8F0;">
                            <span class="font-bold text-gray-700">{{ t('inst.total_financed') }}</span>
                            <span class="font-bold text-gray-800">{{ fmt(total) }}</span>
                        </div>
                        <div class="flex justify-between text-xs text-slate-500">
                            <span>{{ t('inst.down_payment') }} ({{ downPaymentPct }}%)</span>
                            <span class="font-semibold text-blue-700">{{ fmt(downPaymentAmt) }}</span>
                        </div>
                        <div class="flex justify-between text-xs text-slate-500">
                            <span>{{ t('inst.balance') }}</span>
                            <span class="font-semibold text-orange-600">{{ fmt(balance) }}</span>
                        </div>
                        <div class="flex justify-between text-xs text-slate-500 border-t pt-2" style="border-color:#E2E8F0;">
                            <span>{{ t('inst.per_installment') }}</span>
                            <span class="font-semibold text-gray-700">{{ fmt(installmentAmt) }}</span>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <div v-for="row in schedule" :key="row.no"
                            class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs"
                            :class="row.no === 0 ? 'bg-blue-50' : 'bg-gray-50'">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold text-white"
                                :style="row.no === 0 ? 'background:#2563EB;' : 'background:#64748B;'">
                                {{ row.no === 0 ? '↓' : row.no }}
                            </div>
                            <div class="flex-1">
                                <p class="font-semibold text-gray-700">{{ row.label }}</p>
                                <p class="text-slate-400">Due: {{ row.due }}</p>
                                <!-- Show initial paid / grace balance breakdown for down payment -->
                                <template v-if="row.no === 0 && gracePeriodBalance > 0">
                                    <p class="text-green-600">Paid now: {{ fmt(initialPaidAmt) }}</p>
                                    <p class="text-orange-500">Grace: {{ fmt(gracePeriodBalance) }}</p>
                                </template>
                            </div>
                            <span class="font-bold" :class="row.no === 0 ? 'text-blue-700' : 'text-gray-700'">{{ fmt(row.amount) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Error + Submit -->
                <div v-if="errorMsg" class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">{{ errorMsg }}</div>

                <button
                    type="button"
                    @click="submit"
                    :disabled="submitting || cart.length === 0 || !selectedCustomer"
                    class="w-full py-3 rounded-xl text-white font-bold text-sm transition-colors disabled:opacity-50"
                    style="background-color:#2563EB;"
                >
                    {{ submitting ? t('inst.creating') : t('inst.create_btn') }}
                </button>
            </div>
        </div>
    </AuthenticatedLayout>

    <!-- Quick customer registration modal -->
    <Teleport to="body">
        <div v-if="showQuickReg" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 overflow-hidden">
                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b" style="border-color:#E2E8F0;">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0M12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <h2 class="text-sm font-bold text-gray-800">New Customer</h2>
                    </div>
                    <button type="button" @click="showQuickReg = false" class="text-slate-400 hover:text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="px-5 py-4 space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Full Name <span class="text-red-500">*</span></label>
                        <input
                            v-model="quickName"
                            type="text"
                            placeholder="e.g. Kamal Perera"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                            @keydown.enter="saveQuickCustomer"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Phone</label>
                        <input
                            v-model="quickPhone"
                            type="tel"
                            placeholder="07x xxx xxxx"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                            @keydown.enter="saveQuickCustomer"
                        />
                    </div>
                    <p v-if="quickError" class="text-xs text-red-600">{{ quickError }}</p>
                </div>

                <!-- Footer -->
                <div class="flex gap-3 px-5 pb-5">
                    <button type="button" @click="showQuickReg = false"
                        class="flex-1 py-2 text-sm font-semibold text-slate-600 border border-gray-200 rounded-xl hover:bg-slate-50 transition-colors">
                        Cancel
                    </button>
                    <button type="button" @click="saveQuickCustomer" :disabled="quickSaving"
                        class="flex-1 py-2 text-sm font-bold text-white rounded-xl transition-colors disabled:opacity-50"
                        style="background-color:#2563EB;">
                        <span v-if="quickSaving" class="flex items-center justify-center gap-1.5">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Saving…
                        </span>
                        <span v-else>Save &amp; Select</span>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
