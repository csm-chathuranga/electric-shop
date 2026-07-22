<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount, inject } from 'vue';
import { getProducts, invalidateProducts } from '@/stores/productCache';

const t = inject('t');

// ── Date helpers ──────────────────────────────────────────────────────────────
function localDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}
const todayStr = localDate(new Date());

// ── Form state ────────────────────────────────────────────────────────────────
const destination   = ref('');
const transferDate  = ref(todayStr);
const note          = ref('');
const submitting    = ref(false);
const errorMsg      = ref('');

// ── Product data ──────────────────────────────────────────────────────────────
const allProducts = ref([]);
onMounted(async () => { allProducts.value = await getProducts(); });

// ── Cart ──────────────────────────────────────────────────────────────────────
const cart = ref([{ product_id: null, product_name: '', qty: 1, stock_qty: 0 }]);

function addRow() {
    cart.value.push({ product_id: null, product_name: '', qty: 1, stock_qty: 0 });
    searchQueries.value.push('');
}

function removeRow(idx) {
    if (cart.value.length === 1) return;
    cart.value.splice(idx, 1);
    searchQueries.value.splice(idx, 1);
    if (openIndex.value === idx) openIndex.value = null;
}

const totalQty = computed(() => cart.value.reduce((s, i) => s + Number(i.qty || 0), 0));

// ── Product search ────────────────────────────────────────────────────────────
const searchQueries  = ref(['']);
const openIndex      = ref(null);
const highlightIndex = ref(-1);

function filteredProducts(idx) {
    const q = (searchQueries.value[idx] || '').toLowerCase().trim();
    if (!q) return allProducts.value.slice(0, 50);
    return allProducts.value.filter(p =>
        p.name?.toLowerCase().includes(q) ||
        p.name_si?.toLowerCase().includes(q) ||
        p.barcode?.toLowerCase().includes(q) ||
        p.sku?.toLowerCase().includes(q)
    ).slice(0, 50);
}

function openSearch(idx) {
    openIndex.value = idx;
    highlightIndex.value = -1;
    if (!cart.value[idx].product_id) searchQueries.value[idx] = '';
}

function selectProduct(idx, product) {
    cart.value[idx].product_id   = product.id;
    cart.value[idx].product_name = product.name;
    cart.value[idx].stock_qty    = product.stock_qty || 0;
    searchQueries.value[idx]     = product.name;
    openIndex.value              = null;
}

function clearProduct(idx) {
    cart.value[idx].product_id   = null;
    cart.value[idx].product_name = '';
    cart.value[idx].stock_qty    = 0;
    searchQueries.value[idx]     = '';
    openIndex.value              = idx;
}

function onSearchBlur(idx) {
    setTimeout(() => {
        if (openIndex.value === idx) {
            if (!cart.value[idx].product_id) searchQueries.value[idx] = '';
            openIndex.value = null;
        }
    }, 200);
}

function onSearchKeydown(idx, e) {
    const list = filteredProducts(idx);
    if (e.key === 'ArrowDown') { e.preventDefault(); highlightIndex.value = Math.min(highlightIndex.value + 1, list.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlightIndex.value = Math.max(highlightIndex.value - 1, 0); }
    else if (e.key === 'Enter') {
        e.preventDefault();
        if (highlightIndex.value >= 0 && list[highlightIndex.value]) selectProduct(idx, list[highlightIndex.value]);
    } else if (e.key === 'Escape') { openIndex.value = null; }
}

// ── Keyboard shortcuts ────────────────────────────────────────────────────────
function onGlobalKey(e) {
    if (e.key === 'F1') { e.preventDefault(); addRow(); }
    if (e.key === 'F10') { e.preventDefault(); submit(); }
}
onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey));

// ── Submit ────────────────────────────────────────────────────────────────────
function submit() {
    errorMsg.value = '';
    if (!destination.value.trim()) { errorMsg.value = 'ගමනාන්තය ඇතුළු කරන්න.'; return; }
    if (cart.value.some(i => !i.product_id)) { errorMsg.value = 'සියලු අයිතමවලට නිෂ්පාදනයක් තෝරන්න.'; return; }
    if (cart.value.some(i => Number(i.qty) <= 0)) { errorMsg.value = 'ප්‍රමාණය ශුන්‍යයට වඩා වැඩි විය යුතුය.'; return; }

    submitting.value = true;
    router.post(route('stock-transfers.store'), {
        destination:   destination.value,
        transfer_date: transferDate.value,
        note:          note.value,
        items: cart.value.map(i => ({
            product_id:   i.product_id,
            product_name: i.product_name,
            qty:          i.qty,
        })),
    }, {
        onSuccess: () => invalidateProducts(),
        onError:   (errors) => {
            submitting.value = false;
            errorMsg.value = Object.values(errors)[0] || 'දෝෂයක් ඇති විය.';
        },
        onFinish: () => { submitting.value = false; },
    });
}
</script>

<template>
    <Head title="නව ස්ටොක් මාරු කිරීම" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('stock-transfers.index')" class="text-slate-400 hover:text-slate-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h2 class="text-xl font-bold text-gray-800">නව ස්ටොක් මාරු කිරීම</h2>
            </div>
        </template>

        <div class="py-6 max-w-4xl mx-auto px-4 space-y-4">
            <!-- Error -->
            <div v-if="errorMsg" class="rounded-lg px-4 py-3 text-sm font-medium bg-red-50 text-red-700 border border-red-200">
                {{ errorMsg }}
            </div>

            <!-- Header fields -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">ගමනාන්තය *</label>
                        <input
                            v-model="destination"
                            type="text"
                            placeholder="e.g. Kandy Branch"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">දිනය</label>
                        <input
                            v-model="transferDate"
                            type="date"
                            :max="todayStr"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">සටහන</label>
                        <input
                            v-model="note"
                            type="text"
                            placeholder="විකල්ප..."
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400"
                        />
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-slate-700">නිෂ්පාදන</h3>
                    <button type="button" @click="addRow"
                        class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-teal-300 text-teal-700 hover:bg-teal-50 transition-colors">
                        + අයිතමයක් එකතු කරන්න (F1)
                    </button>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 uppercase border-b border-slate-100">
                            <th class="pb-2 text-left">නිෂ්පාදනය</th>
                            <th class="pb-2 text-center w-24">ස්ටොක්</th>
                            <th class="pb-2 text-center w-28">ප්‍රමාණය</th>
                            <th class="pb-2 w-8"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="(item, idx) in cart" :key="idx">
                            <!-- Product search -->
                            <td class="py-2 pr-3 relative">
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="searchQueries[idx]"
                                        @focus="openSearch(idx)"
                                        @blur="onSearchBlur(idx)"
                                        @keydown="onSearchKeydown(idx, $event)"
                                        type="text"
                                        placeholder="නිෂ්පාදනයක් සොයන්න..."
                                        class="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400"
                                    />
                                    <button v-if="item.product_id" type="button" @click="clearProduct(idx)"
                                        class="text-slate-300 hover:text-red-400 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <!-- Dropdown -->
                                <div v-if="openIndex === idx && filteredProducts(idx).length > 0"
                                    class="absolute left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-lg z-50 max-h-60 overflow-y-auto">
                                    <button
                                        v-for="(p, pi) in filteredProducts(idx)" :key="p.id"
                                        type="button"
                                        @mousedown.prevent="selectProduct(idx, p)"
                                        class="w-full text-left px-4 py-2.5 hover:bg-teal-50 transition-colors border-b border-slate-50 last:border-0"
                                        :class="highlightIndex === pi ? 'bg-teal-50' : ''"
                                    >
                                        <p class="font-medium text-slate-800 text-sm">{{ p.name }}</p>
                                        <p class="text-xs text-slate-400">ස්ටොක්: {{ p.stock_qty }}</p>
                                    </button>
                                </div>
                            </td>

                            <!-- Stock -->
                            <td class="py-2 text-center">
                                <span class="text-xs px-2 py-1 rounded-full"
                                    :class="item.stock_qty > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600'">
                                    {{ item.product_id ? item.stock_qty : '—' }}
                                </span>
                            </td>

                            <!-- Qty -->
                            <td class="py-2 text-center">
                                <input
                                    v-model.number="item.qty"
                                    type="number"
                                    min="0.001"
                                    step="1"
                                    class="w-24 text-center border border-slate-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400"
                                    :class="item.qty > item.stock_qty && item.product_id ? 'border-red-400 bg-red-50' : ''"
                                    @focus="$event.target.select()"
                                />
                            </td>

                            <!-- Remove -->
                            <td class="py-2 text-center">
                                <button type="button" @click="removeRow(idx)"
                                    class="text-red-300 hover:text-red-500 transition-colors" :disabled="cart.length === 1">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-slate-100">
                        <tr>
                            <td colspan="2" class="pt-3 text-right text-xs text-slate-500 pr-3">මුළු ප්‍රමාණය</td>
                            <td class="pt-3 text-center font-semibold text-slate-700">{{ totalQty }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-3">
                <Link :href="route('stock-transfers.index')"
                    class="px-6 py-2.5 rounded-lg border border-slate-200 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors">
                    අවලංගු කරන්න
                </Link>
                <button type="button" @click="submit" :disabled="submitting"
                    class="px-8 py-2.5 rounded-lg text-white text-sm font-semibold transition-colors disabled:opacity-60"
                    style="background:#0F766E;">
                    {{ submitting ? 'සුරකිමින්...' : 'ස්ටොක් මාරු කරන්න (F10)' }}
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
