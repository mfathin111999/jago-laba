<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DataTable, { type Column } from '../../Components/DataTable.vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import KpiCard from '../../Components/KpiCard.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest, newIdempotencyKey } from '../../lib/api';
import { formatDate, formatText } from '../../lib/format';

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

const columns: Column[] = [
    { key: 'expense_date', label: 'Tanggal', kind: 'date' },
    { key: 'description', label: 'Keterangan' },
    { key: 'category', label: 'Kategori' },
    { key: 'vendor_name', label: 'Vendor' },
    { key: 'amount', label: 'Nominal', kind: 'currency', align: 'right' },
    { key: 'effect', label: 'Pengaruh' },
];
</script>

<template>
    <MarketplaceShell
        active="expenses"
        title="Pengeluaran"
        eyebrow="Marketplace / Pengeluaran"
        subtitle="Biaya toko yang dicatat manual. Tidak memanggil provider Marketplace."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <template #actions>
            <a v-if="scope.store_id" class="button button-secondary" :href="exportUrl">Unduh CSV</a>
            <button v-if="can_manage && scope.store_id" class="button button-primary" type="button" @click="openCreate">Catat pengeluaran</button>
        </template>

        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <form class="panel filter-bar" @submit.prevent="applyFilters">
            <div class="field filter-store">
                <label for="e-store">Toko</label>
                <select id="e-store" v-model="filter.store_id"><option value="" disabled>Pilih toko</option><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
            <div class="field"><label for="e-search">Pencarian</label><input id="e-search" v-model="filter.search" type="search" maxlength="200" placeholder="Keterangan, vendor, catatan…" /></div>
            <div class="field"><label for="e-category">Kategori</label><input id="e-category" v-model="filter.category" maxlength="100" /></div>
            <div class="field"><label for="e-date">Tanggal</label><input id="e-date" v-model="filter.date" type="date" /></div>
            <button class="button button-primary" type="submit">Terapkan</button>
        </form>

        <section v-if="!scope.store_id" class="panel empty-state"><h2>Belum ada toko</h2><p class="muted">Tambahkan toko di menu Toko, lalu catat pengeluarannya di sini.</p></section>

        <template v-else>
            <section class="kpi-grid" aria-label="Ringkasan pengeluaran">
                <KpiCard label="Total pengeluaran" :value="summary.total_amount" />
                <KpiCard label="Mempengaruhi laba" :value="summary.affects_profit" />
                <KpiCard label="Arus kas saja" :value="summary.cashflow_only" />
                <KpiCard label="Jumlah catatan" :value="summary.count" kind="count" />
            </section>

            <section class="panel">
                <DataTable :columns="columns" :rows="expenses" row-key="id" empty="Belum ada pengeluaran pada filter ini.">
                    <template #cell-expense_date="{ row }">{{ formatDate(row.expense_date) }}</template>
                    <template #cell-description="{ row }">{{ row.description }}<div v-if="row.notes" class="muted cell-note">{{ row.notes }}</div></template>
                    <template #cell-vendor_name="{ row }">{{ formatText(row.vendor_name) }}</template>
                    <template #cell-effect="{ row }">
                        <StatusBadge v-if="Number(row.is_cashflow_only)" label="Arus kas saja" tone="info" />
                        <StatusBadge v-else-if="Number(row.affects_profit)" label="Mengurangi laba" tone="warn" />
                        <StatusBadge v-else label="Tidak mempengaruhi laba" tone="muted" />
                    </template>
                    <template #actions="{ row }"><button v-if="can_manage" class="button button-danger button-small" type="button" @click="pendingDelete = row">Hapus</button></template>
                </DataTable>
            </section>
        </template>

        <Modal :open="creating" title="Catat pengeluaran" :busy="busy" @close="creating = false">
            <form class="form-grid" @submit.prevent="save">
                <div class="field field-wide"><label for="x-desc">Keterangan</label><input id="x-desc" v-model="form.description" required maxlength="255" placeholder="Contoh: biaya iklan Oktober" /></div>
                <div class="field"><label for="x-amount">Nominal</label><input id="x-amount" v-model="form.amount" required inputmode="decimal" placeholder="Rp 12.500" /></div>
                <div class="field"><label for="x-date">Tanggal</label><input id="x-date" v-model="form.expense_date" required type="date" /></div>
                <div class="field"><label for="x-cat">Kategori</label><input id="x-cat" v-model="form.category" required maxlength="100" /></div>
                <div class="field"><label for="x-vendor">Vendor</label><input id="x-vendor" v-model="form.vendor_name" maxlength="150" /></div>
                <div class="field"><label for="x-method">Metode pembayaran</label><input id="x-method" v-model="form.payment_method" required maxlength="50" /></div>
                <div class="field field-wide"><label for="x-notes">Catatan</label><input id="x-notes" v-model="form.notes" placeholder="Opsional" /></div>
                <div class="checkbox-row field-wide">
                    <label><input v-model="form.affects_profit" type="checkbox" /> Mempengaruhi laba</label>
                    <label><input v-model="form.is_cashflow_only" type="checkbox" /> Hanya arus kas</label>
                </div>
                <p v-if="formError" class="inline-error field-wide" role="alert">{{ formError }}</p>
                <div class="form-actions field-wide">
                    <button class="button button-secondary" type="button" @click="creating = false">Batal</button>
                    <button class="button button-primary" type="submit" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </Modal>

        <Modal :open="pendingDelete !== null" title="Hapus pengeluaran?" :busy="busy" @close="pendingDelete = null">
            <p v-if="pendingDelete">Pengeluaran “{{ pendingDelete.description }}” akan dihapus dan tidak dapat dikembalikan.</p>
            <div class="form-actions">
                <button class="button button-secondary" type="button" @click="pendingDelete = null">Batal</button>
                <button class="button button-danger" type="button" :disabled="busy" @click="remove">{{ busy ? 'Menghapus…' : 'Hapus' }}</button>
            </div>
        </Modal>
    </MarketplaceShell>
</template>
