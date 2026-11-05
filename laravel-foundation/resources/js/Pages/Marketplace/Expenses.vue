<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import JlIcon from '../../Components/JlIcon.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import { ApiError, apiRequest, newIdempotencyKey } from '../../lib/api';
import { formatValue } from '../../lib/format';

type Expense = Record<string, any>;

const props = defineProps<{
    stores: Array<Record<string, any>>;
    scope: { company_key: string; store_id: string | null };
    filters: Record<string, any>;
    summary: Record<string, any>;
    expenses: Expense[];
    can_manage: boolean;
}>();

const DEFAULT_CATEGORY = 'Biaya Iklan/Advertising Marketplace';
const today = () => new Date().toISOString().slice(0, 10);

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);
const formError = ref<string | null>(null);

const filter = reactive({
    store_id: props.scope.store_id ?? '',
    search: props.filters.search ?? '',
    category: props.filters.category ?? '',
    date: props.filters.date ?? '',
});

function applyFilters(): void {
    const query: Record<string, string> = {};
    Object.entries(filter).forEach(([key, value]) => {
        if (value !== '') query[key] = String(value);
    });
    router.get('/marketplace/expenses', query, { preserveState: true, replace: true });
}

const exportUrl = computed(() => {
    const query = new URLSearchParams({ store_id: filter.store_id, format: 'csv' });
    if (filter.search) query.set('search', filter.search);
    if (filter.category) query.set('category', filter.category);
    if (filter.date) query.set('date', filter.date);

    return `/marketplace/expenses?${query.toString()}`;
});

// ----- create -----
const creating = ref(false);
const blank = () => ({ description: '', amount: '', expense_date: today(), category: DEFAULT_CATEGORY, vendor_name: '', payment_method: 'Transfer Bank', notes: '', affects_profit: true, is_cashflow_only: false });
const form = reactive(blank());
let attemptKey = newIdempotencyKey();

function openCreate(): void {
    Object.assign(form, blank());
    attemptKey = newIdempotencyKey();
    formError.value = null;
    creating.value = true;
}

async function save(): Promise<void> {
    if (!props.scope.store_id) return;
    busy.value = true;
    formError.value = null;
    try {
        const result = await apiRequest('/pilot/marketplace/expenses', { ...form, store_id: props.scope.store_id }, { headers: { 'X-Idempotency-Key': attemptKey } });
        flash.message = result.message ?? 'Pengeluaran tersimpan.';
        flash.kind = 'success';
        creating.value = false;
        router.reload({ only: ['expenses', 'summary'] });
    } catch (error) {
        formError.value = error instanceof ApiError ? error.message : 'Pengeluaran belum berhasil disimpan.';
    } finally {
        busy.value = false;
    }
}

// ----- delete -----
const pendingDelete = ref<Expense | null>(null);

async function remove(): Promise<void> {
    const target = pendingDelete.value;
    if (!target || !props.scope.store_id) return;
    busy.value = true;
    try {
        const result = await apiRequest(`/pilot/marketplace/expenses/${encodeURIComponent(String(target.id))}?store_id=${encodeURIComponent(props.scope.store_id)}`, undefined, { method: 'DELETE' });
        flash.message = result.message ?? 'Pengeluaran dihapus.';
        flash.kind = 'success';
        pendingDelete.value = null;
        router.reload({ only: ['expenses', 'summary'] });
    } catch (error) {
        flash.message = error instanceof ApiError ? error.message : 'Pengeluaran belum berhasil dihapus.';
        flash.kind = 'error';
        pendingDelete.value = null;
    } finally {
        busy.value = false;
    }
}

const activeStore = computed(() => props.stores.find((store) => store.id === props.scope.store_id) ?? null);
const money = (value: unknown) => formatValue(value, 'currency');
const cards = computed<Array<[string, unknown]>>(() => [
    ['Total Pengeluaran', props.summary.total_amount],
    ['Mempengaruhi laba', props.summary.affects_profit],
    ['Arus kas saja', props.summary.cashflow_only],
]);
const opened = ref(new Set<number>());
const toggle = (id: number) => {
    const next = new Set(opened.value);
    next.has(id) ? next.delete(id) : next.add(id);
    opened.value = next;
};
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const dateLabel = (date: unknown) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(date ?? ''));

    return match ? `${match[3]} ${MONTHS[Number(match[2]) - 1]} ${match[1]}` : String(date ?? '');
};
const subCategory = (row: Expense) => {
    if (!/iklan|advertising/i.test(String(row.category ?? ''))) return '—';
    const platform = String(activeStore.value?.platform ?? '');

    return platform === 'TikTok Shop' ? 'TikTok Ads Manager' : platform === 'Shopee' ? 'Shopee Ads Manager' : `${platform} Ads`;
};
const effect = (row: Expense) => (Number(row.is_cashflow_only) ? 'Arus kas saja' : Number(row.affects_profit) ? 'Mengurangi laba' : 'Tidak mempengaruhi laba');
const detail = (row: Expense): Array<[string, string]> => [
    ['Deskripsi', row.description], ['Kategori', row.category], ['Subkategori', subCategory(row)], ['Jumlah', `-${money(row.amount)}`],
    ['Metode Pembayaran', row.payment_method || '—'], ['Vendor', row.vendor_name || '—'], ['Tanggal', dateLabel(row.expense_date)],
    ['Proyek', activeStore.value?.store_name ?? ''], ['Pengaruh', effect(row)], ['Catatan', row.notes || '—'],
];
</script>

<template>
    <MarketplaceShell active="expenses" title="Pengeluaran" :store-id="scope.store_id" :company-key="scope.company_key">
        <section class="space-y-4" id="global-expenses">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs text-slate-500 mb-1">{{ activeStore?.platform }} · {{ activeStore?.store_name }}</p><h1 class="text-2xl font-extrabold text-slate-900">Pengeluaran</h1><p class="text-xs text-slate-500 mt-2">Biaya toko yang dicatat manual. Tidak memanggil provider Marketplace.</p></div>
                <div class="flex gap-2">
                    <a v-if="scope.store_id" class="rounded-xl border bg-white px-4 py-2 text-xs font-bold" :href="exportUrl">↓ Unduh CSV</a>
                    <button v-if="can_manage && scope.store_id" class="rounded-xl bg-brand-600 text-white px-4 py-2 text-xs font-bold" type="button" @click="openCreate">+ Catat pengeluaran</button>
                </div>
            </header>
            <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

            <form class="flex flex-wrap gap-3 bg-white rounded-2xl border p-4 items-end" @submit.prevent="applyFilters">
                <label class="flex-1 min-w-48 text-xs font-semibold">Pencarian<input v-model="filter.search" type="search" maxlength="200" placeholder="Keterangan, vendor, catatan…" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="text-xs font-semibold">Kategori<input v-model="filter.category" maxlength="100" class="block mt-2 border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="text-xs font-semibold">Tanggal<input v-model="filter.date" type="date" class="block mt-2 border rounded-xl px-3 py-2 font-normal" /></label>
                <button class="rounded-xl bg-brand-600 text-white text-xs font-bold px-4 py-2" type="submit">Terapkan</button>
            </form>

            <section v-if="!scope.store_id" class="bg-white rounded-2xl border p-8 text-center"><h2 class="font-extrabold">Belum ada toko</h2><p class="text-xs text-slate-500 mt-2">Tambahkan toko di menu Toko, lalu catat pengeluarannya di sini.</p></section>

            <template v-else>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div v-for="[label, value] in cards" :key="label" class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-rose-500 p-5"><p class="text-xs text-slate-500">{{ label }}</p><p class="text-xl font-extrabold text-rose-600 mt-2">{{ money(value) }}</p><p class="text-xs text-slate-400 mt-1">Sesuai filter aktif</p></div>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
                    <div class="p-5 border-b border-slate-100 flex flex-wrap justify-between gap-3 items-center">
                        <div><h2 class="font-extrabold">Pengeluaran Toko</h2><p class="text-xs text-slate-500 mt-1">{{ summary.count ?? 0 }} entri · Pengeluaran harian toko ini</p></div>
                        <button type="button" class="rounded-xl border px-3 py-2 text-xs font-bold" @click="applyFilters">Perbarui</button>
                    </div>
                    <p class="px-5 py-3 bg-amber-50 text-amber-900 text-xs">Pengambilan otomatis biaya iklan TikTok/Shopee belum aktif. Data di bawah berasal dari pengeluaran yang sudah tercatat. PPN mengikuti catatan transaksi.</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs jl-table">
                            <thead class="bg-slate-50 text-slate-500 uppercase"><tr><th v-for="heading in ['Deskripsi', 'Tanggal', 'Kategori', 'Jumlah', 'Metode', 'Catatan', 'Aksi']" :key="heading" class="p-3 text-left">{{ heading }}</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <template v-for="row in expenses" :key="row.id">
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 min-w-[260px]"><b>{{ row.description }}</b><span class="block text-slate-500 mt-1">{{ subCategory(row) }}</span><span class="block text-blue-600">Proyek: {{ activeStore?.store_name }}</span><span class="block text-slate-400">Vendor: {{ row.vendor_name || '—' }}</span></td>
                                        <td class="p-3 whitespace-nowrap">{{ dateLabel(row.expense_date) }}</td>
                                        <td class="p-3"><span class="inline-block rounded-full bg-purple-100 text-purple-700 px-2 py-1">{{ row.category }}</span></td>
                                        <td class="p-3 font-bold text-rose-600 whitespace-nowrap">-{{ money(row.amount) }}</td>
                                        <td class="p-3">{{ row.payment_method || '—' }}</td>
                                        <td class="p-3">{{ row.notes || '—' }}</td>
                                        <td class="p-3 whitespace-nowrap">
                                            <button type="button" :aria-expanded="opened.has(row.id)" class="rounded-lg border px-3 py-2 text-slate-600 hover:bg-slate-100" :aria-label="`Lihat detail ${row.description}`" @click="toggle(row.id)"><JlIcon name="eye" /></button>
                                            <button v-if="can_manage" type="button" class="rounded-lg border px-3 py-2 text-rose-600 hover:bg-rose-50 ml-1" @click="pendingDelete = row">Hapus</button>
                                        </td>
                                    </tr>
                                    <tr v-if="opened.has(row.id)"><td colspan="7" class="p-4 bg-slate-50"><h3 class="font-bold mb-3">Detail Pengeluaran</h3>
                                        <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 rounded-xl bg-white border p-4">
                                            <div v-for="[label, value] in detail(row)" :key="label"><dt class="text-slate-400 mb-1">{{ label }}</dt><dd class="font-bold break-words" :class="{ 'text-rose-600': label === 'Jumlah' }">{{ value }}</dd></div>
                                        </dl></td></tr>
                                </template>
                                <tr v-if="!expenses.length"><td colspan="7" class="p-12 text-center text-slate-500">Belum ada pengeluaran untuk filter ini. Catat pengeluaran dengan tombol Catat pengeluaran.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </section>

        <Modal :open="creating" title="Catat pengeluaran" :busy="busy" @close="creating = false">
            <form class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs" @submit.prevent="save">
                <label class="sm:col-span-2 font-semibold">Keterangan<input v-model="form.description" required maxlength="255" placeholder="Contoh: biaya iklan Oktober" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="font-semibold">Nominal<input v-model="form.amount" required inputmode="decimal" placeholder="Rp 12.500" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="font-semibold">Tanggal<input v-model="form.expense_date" required type="date" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="font-semibold">Kategori<input v-model="form.category" required maxlength="100" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="font-semibold">Vendor<input v-model="form.vendor_name" maxlength="150" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="font-semibold">Metode pembayaran<input v-model="form.payment_method" required maxlength="50" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="font-semibold">Catatan<input v-model="form.notes" placeholder="Opsional" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <div class="sm:col-span-2 flex flex-wrap gap-5 font-semibold">
                    <label class="flex items-center gap-2"><input v-model="form.affects_profit" type="checkbox" /> Mempengaruhi laba</label>
                    <label class="flex items-center gap-2"><input v-model="form.is_cashflow_only" type="checkbox" /> Hanya arus kas</label>
                </div>
                <p v-if="formError" class="sm:col-span-2" style="color: #b42318" role="alert">{{ formError }}</p>
                <div class="sm:col-span-2 flex justify-end gap-2 pt-3 border-t">
                    <button class="border rounded-xl bg-white px-4 py-2 font-bold" type="button" @click="creating = false">Batal</button>
                    <button class="rounded-xl bg-brand-600 text-white px-4 py-2 font-bold" type="submit" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </Modal>

        <Modal :open="pendingDelete !== null" title="Hapus pengeluaran?" :busy="busy" @close="pendingDelete = null">
            <div class="p-6 text-xs">
                <p v-if="pendingDelete">Pengeluaran “{{ pendingDelete.description }}” akan dihapus dan tidak dapat dikembalikan.</p>
                <div class="flex justify-end gap-2 mt-5"><button class="border rounded-xl bg-white px-4 py-2 font-bold" type="button" @click="pendingDelete = null">Batal</button><button class="rounded-xl bg-rose-600 text-white px-4 py-2 font-bold" type="button" :disabled="busy" @click="remove">{{ busy ? 'Menghapus…' : 'Hapus' }}</button></div>
            </div>
        </Modal>
    </MarketplaceShell>
</template>
