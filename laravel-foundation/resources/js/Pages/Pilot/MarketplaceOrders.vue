<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DataTable, { type Column } from '../../Components/DataTable.vue';
import KpiCard from '../../Components/KpiCard.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import ShippingLabels from '../../Components/ShippingLabels.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatDate, formatText, formatValue } from '../../lib/format';

type Store = Record<string, any>;
type Order = Record<string, any>;
type OrdersPayload = { counts: Record<string, number>; rows: Order[]; total: number; page: number; pages: number; per_page: number; period: Record<string, any>; tab: string } | null;

const props = defineProps<{
    read_only: boolean;
    workspace: string;
    scope: { company_key: string; store_id: string | null };
    stores: Store[];
    active_store?: Store | null;
    filters: Record<string, any>;
    orders: OrdersPayload;
    query: Record<string, any>;
    message?: string;
    provider?: { live: boolean; can_fulfill: boolean; seller_center: string | null };
}>();

const TAB_LABELS: Record<string, string> = {
    all: 'Semua',
    new: 'Pesanan baru',
    pickup: 'Menunggu pickup',
    shipped: 'Dikirim',
    cancelled: 'Dibatalkan',
    returns: 'Pengembalian barang',
};

const form = reactive({
    store: props.scope.store_id ?? '',
    period: props.filters.period ?? 'month',
    date_start: props.filters.date_start ?? '',
    date_end: props.filters.date_end ?? '',
    order_tab: props.filters.order_tab ?? 'all',
    search: props.filters.search ?? '',
    search_by: props.filters.search_by ?? 'order',
    courier: props.filters.courier ?? '',
    payment: props.filters.payment ?? '',
    condition: props.filters.condition ?? '',
    per_page: props.filters.per_page ?? 50,
    return_type: props.filters.return_type ?? '',
    return_date: props.filters.return_date ?? 'created',
});

function params(page = 1): Record<string, any> {
    const result: Record<string, any> = { ...form, page };
    if (result.period !== 'custom') {
        delete result.date_start;
        delete result.date_end;
    }
    Object.keys(result).forEach((key) => {
        if (result[key] === '') delete result[key];
    });

    return result;
}

function loadOrders(page = 1): void {
    router.get('/pilot/marketplace/orders', params(page), { preserveState: true, preserveScroll: true, replace: true });
}

// ----- shipping labels and shipment arrangement (live mode) -----
const live = computed(() => props.provider?.live === true);
const selected = ref<string[]>([]);
const labelIds = ref<string[]>([]);
const labelsOpen = ref(false);
const printLabels = (ids: string[]) => {
    labelIds.value = ids;
    labelsOpen.value = true;
};

const exportUrl = computed(() => {
    const { page: _page, per_page: _perPage, ...rest } = params(1);

    return `/pilot/marketplace/orders/export?${new URLSearchParams(rest as Record<string, string>).toString()}`;
});

// ----- detail -----
const detail = ref<Record<string, any> | null>(null);
const detailOpen = ref(false);
const detailBusy = ref(false);
const detailError = ref<string | null>(null);

async function openDetail(order: Order): Promise<void> {
    detailOpen.value = true;
    detailBusy.value = true;
    detailError.value = null;
    detail.value = null;
    try {
        const url = `/pilot/marketplace/orders/${encodeURIComponent(String(order.id))}/detail?store_id=${encodeURIComponent(String(props.scope.store_id ?? ''))}`;
        detail.value = (await apiRequest(url, undefined, { method: 'GET' })).data ?? null;
    } catch (error) {
        detailError.value = error instanceof ApiError ? error.message : 'Rincian pesanan belum tersedia.';
    } finally {
        detailBusy.value = false;
    }
}

// ----- finance refresh (live mode) -----
const financeBusy = ref(false);
const financeMessage = ref<string | null>(null);
async function refreshFinance(): Promise<void> {
    const id = detail.value?.presentation?.order_id;
    if (!id) return;
    financeBusy.value = true;
    financeMessage.value = null;
    try {
        const result = await apiRequest('/api/marketplace-order-finance.php', { store_id: props.scope.store_id, order_id: id });
        if (result.presentation && detail.value) detail.value = { ...detail.value, presentation: result.presentation };
        financeMessage.value = result.message ?? null;
    } catch (error) {
        financeMessage.value = error instanceof ApiError ? error.message : 'Finance API belum dapat diperbarui.';
    } finally {
        financeBusy.value = false;
    }
}

// ----- display -----
const columns: Column[] = [
    { key: 'select', label: '' },
    { key: 'date', label: 'Tanggal', kind: 'date' },
    { key: 'order', label: 'Kode & produk' },
    { key: 'status', label: 'Status' },
    { key: 'amount', label: 'Nilai', kind: 'currency', align: 'right' },
    { key: 'payment', label: 'Pembayaran' },
    { key: 'shipping', label: 'Kurir / resi' },
];

const payment = (order: Order): string => (order.is_cod === true ? 'COD' : order.is_cod === false ? (order.payment_method || 'Non-COD') : formatText(null));
const counts = computed(() => Object.entries(props.orders?.counts ?? {}).map(([key, value]) => ({ key, label: TAB_LABELS[key] ?? key, value })));
const presentation = computed(() => (detail.value?.presentation ?? null) as Record<string, any> | null);
</script>

<template>
    <MarketplaceShell
        active="orders"
        title="Transaksi dan retur"
        eyebrow="Marketplace / Pesanan"
        subtitle="Daftar dan rincian pesanan dari data toko yang tersimpan."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <template #actions>
            <button v-if="live && orders" class="button button-secondary" type="button" :disabled="selected.length === 0 || selected.length > 20" @click="printLabels(selected)">Cetak resi ({{ selected.length }})</button>
            <a v-if="orders" class="button button-secondary" :href="exportUrl">Unduh CSV</a>
        </template>

        <form class="panel filter-bar" @submit.prevent="loadOrders(1)">
            <div class="field filter-store">
                <label for="o-store">Toko</label>
                <select id="o-store" v-model="form.store"><option value="" disabled>Pilih toko</option><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
            <div class="field">
                <label for="o-period">Periode</label>
                <select id="o-period" v-model="form.period">
                    <option value="today">Hari ini</option><option value="yesterday">Kemarin</option><option value="week">7 hari terakhir</option>
                    <option value="month">Bulan ini</option><option value="last_month">Bulan lalu</option><option value="custom">Kustom</option>
                </select>
            </div>
            <div v-if="form.period === 'custom'" class="field"><label for="o-start">Mulai</label><input id="o-start" v-model="form.date_start" type="date" required /></div>
            <div v-if="form.period === 'custom'" class="field"><label for="o-end">Sampai</label><input id="o-end" v-model="form.date_end" type="date" required /></div>
            <div class="field">
                <label for="o-tab">Tab</label>
                <select id="o-tab" v-model="form.order_tab"><option v-for="(label, key) in TAB_LABELS" :key="key" :value="key">{{ label }}</option></select>
            </div>
            <div class="field">
                <label for="o-by">Cari berdasarkan</label>
                <select id="o-by" v-model="form.search_by"><option value="order">Kode pesanan</option><option value="sku">SKU</option><option value="product">Produk</option><option value="tracking">Resi</option><option value="recipient">Penerima</option></select>
            </div>
            <div class="field"><label for="o-search">Pencarian</label><input id="o-search" v-model="form.search" type="search" maxlength="200" placeholder="Kode, SKU, produk, resi…" /></div>
            <div class="field">
                <label for="o-payment">Pembayaran</label>
                <select id="o-payment" v-model="form.payment"><option value="">Semua</option><option value="cod">COD</option><option value="non_cod">Non-COD</option></select>
            </div>
            <div class="field">
                <label for="o-rows">Baris</label>
                <select id="o-rows" v-model="form.per_page"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select>
            </div>
            <button class="button button-primary" type="submit">Terapkan</button>
        </form>

        <section v-if="message" class="panel empty-state"><h2>Toko belum siap</h2><p class="muted">{{ message }}</p></section>

        <template v-if="orders">
            <section class="kpi-grid" aria-label="Jumlah pesanan per tab">
                <KpiCard v-for="count in counts" :key="count.key" :label="count.label" :value="count.value" kind="count" />
            </section>

            <section class="panel">
                <div class="section-heading compact">
                    <div><span class="eyebrow">Periode {{ orders.period.start }} s/d {{ orders.period.end ?? orders.period.exclusive_end }}</span><h2>{{ formatValue(orders.total, 'count') }} pesanan</h2></div>
                    <span class="muted">Halaman {{ orders.page }} dari {{ orders.pages }}</span>
                </div>

                <DataTable :columns="columns" :rows="orders.rows" row-key="id" empty="Tidak ada pesanan pada filter ini.">
                    <template #cell-select="{ row }"><input v-if="live" v-model="selected" type="checkbox" :value="String(row.id)" :aria-label="'Pilih pesanan ' + row.id" /></template>
                    <template #cell-date="{ row }">{{ formatDate(row.date) }}</template>
                    <template #cell-order="{ row }">
                        <button class="link-button" type="button" @click="openDetail(row)">{{ row.id }}</button>
                        <div v-for="item in row.items" :key="`${row.id}-${item.sku}-${item.name}`" class="muted cell-note">{{ item.name || 'Produk belum tersedia' }} · {{ item.sku || 'SKU belum tersedia' }}</div>
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge :label="formatText(row.status)" tone="info" />
                        <div v-if="row.cancel_requested" class="cell-note warn-text">Pengajuan pembatalan</div>
                    </template>
                    <template #cell-payment="{ row }">{{ payment(row) }}</template>
                    <template #cell-shipping="{ row }">{{ formatText(row.courier) }}<div class="muted cell-note">{{ row.tracking || 'Resi belum tersedia' }}</div></template>
                    <template #actions="{ row }">
                        <button class="button button-secondary button-small" type="button" @click="openDetail(row)">Lihat detail</button>
                        <button v-if="live" class="button button-secondary button-small" type="button" @click="printLabels([String(row.id)])">Cetak resi</button>
                    </template>
                </DataTable>

                <div class="form-actions page-controls">
                    <button class="button button-secondary" type="button" :disabled="orders.page <= 1" @click="loadOrders(orders.page - 1)">Sebelumnya</button>
                    <button class="button button-secondary" type="button" :disabled="orders.page >= orders.pages" @click="loadOrders(orders.page + 1)">Berikutnya</button>
                </div>
            </section>
        </template>

        <ShippingLabels :open="labelsOpen" :store-id="String(scope.store_id ?? '')" :order-ids="labelIds" :seller-center="provider?.seller_center ?? null" :can-fulfill="provider?.can_fulfill === true" @close="labelsOpen = false" />

        <Modal :open="detailOpen" title="Rincian pesanan" :busy="detailBusy" @close="detailOpen = false">
            <p v-if="detailBusy" class="muted">Memuat rincian…</p>
            <p v-else-if="detailError" class="inline-error" role="alert">{{ detailError }}</p>
            <div v-else-if="presentation" class="form-stack">
                <div class="grid grid-3">
                    <KpiCard label="Nilai" :value="presentation.gross_amount" />
                    <KpiCard label="Net income" :value="presentation.net_income" />
                    <KpiCard label="HPP" :value="presentation.total_cogs" />
                    <KpiCard label="Laba bersih" :value="presentation.net_profit" />
                </div>
                <p><strong>Status:</strong> {{ formatText(presentation.status) }} · <strong>Kode:</strong> {{ presentation.order_id }}</p>
                <div v-if="live && presentation.platform === 'TikTok Shop'" class="form-actions">
                    <button class="button button-secondary button-small" type="button" :disabled="financeBusy" @click="refreshFinance">{{ financeBusy ? 'Mengambil data resmi…' : 'Perbarui dari Finance API' }}</button>
                    <span v-if="financeMessage" class="muted cell-note" role="status">{{ financeMessage }}</span>
                </div>
                <div>
                    <h3>Produk</h3>
                    <p v-for="item in presentation.items" :key="`${item.sku}-${item.name}`" class="cell-note">
                        {{ item.name || 'Produk belum tersedia' }} · {{ item.variant || 'Varian belum tersedia' }} · {{ item.quantity }} unit · {{ formatValue(item.total_price) }}
                    </p>
                </div>
                <div>
                    <h3>Paket dan retur</h3>
                    <p v-for="pack in presentation.packages || []" :key="pack.id" class="cell-note">Paket {{ pack.id }} · {{ pack.courier || 'Kurir belum tersedia' }} · {{ pack.tracking || 'Resi belum tersedia' }}</p>
                    <p v-for="item in presentation.return_cases || []" :key="item.id" class="cell-note warn-text">{{ item.type_label }} · {{ item.status }} · {{ item.refund_status }}</p>
                    <p v-if="!(presentation.return_cases || []).length" class="muted cell-note">Tidak ada kasus retur.</p>
                </div>
            </div>
        </Modal>
    </MarketplaceShell>
</template>
