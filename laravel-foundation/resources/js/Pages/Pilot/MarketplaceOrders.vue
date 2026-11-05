<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import JlIcon from '../../Components/JlIcon.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import ShippingLabels from '../../Components/ShippingLabels.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatValue } from '../../lib/format';

type Store = Record<string, any>;
type Order = Record<string, any>;
type OrdersPayload = { counts: Record<string, number>; rows: Order[]; total: number; page: number; pages: number; per_page: number; period: Record<string, any>; tab: string; return_date?: string; return_coverage?: Record<string, any> | null } | null;

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
    new: 'Pesanan Baru',
    pickup: 'Menunggu Pickup',
    shipped: 'Dikirim',
    cancelled: 'Dibatalkan',
    returns: 'Pengembalian Barang',
};
const PERIODS = [['today', 'Hari ini'], ['yesterday', 'Kemarin'], ['week', '7 hari terakhir'], ['month', 'Bulan ini'], ['last_month', 'Bulan lalu'], ['custom', 'Rentang khusus']];
const SEARCH_BY = [['order', 'Kode Pesanan'], ['sku', 'SKU'], ['product', 'Nama Produk'], ['tracking', 'Nomor Resi'], ['recipient', 'Nama Penerima']];
const CONDITIONS = [['', 'Semua kondisi'], ['unpaid', 'Belum Dibayar'], ['cancel_request', 'Pengajuan Pembatalan'], ['partial', 'Dikirim Sebagian'], ['completed', 'Selesai'], ['return', 'Retur tercatat'], ['refund', 'Refund tercatat']];
const RETURN_TYPES = [['', 'Semua kasus pengembalian'], ['goods', 'Barang & Dana'], ['refund', 'Dana Saja'], ['replacement', 'Penggantian/Penukaran']];
const RETURN_DATES: Record<string, string> = { created: 'Tanggal pengajuan kasus', updated: 'Tanggal pembaruan kasus', order: 'Tanggal pesanan' };
const money = (value: unknown) => formatValue(value, 'currency');
const count = (value: unknown) => formatValue(value, 'count');

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
const returnUnavailable = computed(() => props.orders?.return_coverage?.available === false);
const allSelected = computed(() => Boolean(props.orders?.rows.length) && props.orders!.rows.every((row) => selected.value.includes(String(row.id))));
const toggleAll = (event: Event) => {
    selected.value = (event.target as HTMLInputElement).checked ? (props.orders?.rows ?? []).map((row) => String(row.id)) : [];
};
const copied = ref('');
async function copy(value: unknown): Promise<void> {
    try {
        await navigator.clipboard.writeText(String(value));
        copied.value = 'Disalin ke papan klip.';
    } catch {
        copied.value = 'Gagal menyalin. Salin secara manual.';
    }
}
function setTab(key: string): void {
    form.order_tab = key;
    loadOrders(1);
}
function resetFilters(): void {
    Object.assign(form, { period: 'month', search: '', courier: '', condition: '', payment: '' });
    loadOrders(1);
}
function changePerPage(event: Event): void {
    form.per_page = Number((event.target as HTMLSelectElement).value);
    loadOrders(1);
}
const presentation = computed(() => (detail.value?.presentation ?? null) as Record<string, any> | null);
</script>

<template>
    <MarketplaceShell active="orders" title="Pesanan" :store-id="scope.store_id" :company-key="scope.company_key">
        <section v-if="!orders || !active_store" id="marketplace-orders" class="mp-orders">
            <header class="mp-order-header"><div><p class="mp-order-eyebrow">Marketplace</p><h1>Pesanan</h1><p>Pantau pesanan, paket, dan kondisi pengiriman dari toko aktif.</p></div></header>
            <div class="mp-order-notice"><JlIcon name="info" /><p>{{ message || 'Pilih toko terlebih dahulu.' }} <Link href="/marketplace/stores">Pilih Toko</Link></p></div>
        </section>

        <section v-else id="marketplace-orders" class="mp-orders" :data-store="scope.store_id ?? ''">
            <header class="mp-order-header">
                <div><p class="mp-order-eyebrow">{{ active_store.platform }} · {{ active_store.store_name }}</p><h1>Pesanan</h1><p>Pantau pesanan, paket, dan kondisi pengiriman dari toko aktif.</p></div>
                <div class="mp-order-actions">
                    <Link class="mp-order-button primary" :href="`/marketplace/sync?store=${scope.store_id}&store_id=${scope.store_id}`"><JlIcon name="refresh" /> Sinkronisasi Data</Link>
                    <a class="mp-order-button" :href="exportUrl"><JlIcon name="download" /> Ekspor Pesanan</a>
                </div>
            </header>
            <div class="mp-order-source"><span class="mp-order-badge">{{ active_store.authorization_label || 'Periksa koneksi' }}</span><span>Sinkronisasi berhasil terakhir: {{ active_store.last_successful_sync_at || 'Belum tersedia' }} WIB</span></div>

            <form class="mp-order-filter" @submit.prevent="loadOrders(1)">
                <label>Periode<select v-model="form.period"><option v-for="[value, label] in PERIODS" :key="value" :value="value">{{ label }}</option></select></label>
                <label>Mulai<input v-model="form.date_start" type="date" /></label>
                <label>Sampai<input v-model="form.date_end" type="date" /></label>
                <label>Cari berdasarkan<select v-model="form.search_by"><option v-for="[value, label] in SEARCH_BY" :key="value" :value="value">{{ label }}</option></select></label>
                <label class="mp-search">Pencarian<input v-model="form.search" maxlength="200" placeholder="Cari pesanan, SKU, produk…" /></label>
                <label>Kurir<input v-model="form.courier" placeholder="Semua kurir" /></label>
                <label>Pembayaran<select v-model="form.payment"><option value="">Semua</option><option value="cod">COD</option><option value="non_cod">Non-COD terkonfirmasi</option></select></label>
                <label>Kondisi tambahan<select v-model="form.condition"><option v-for="[value, label] in CONDITIONS" :key="value" :value="value">{{ label }}</option></select></label>
                <div class="mp-filter-actions"><button type="submit" class="mp-order-button primary"><JlIcon name="filter" /> Terapkan</button><a href="#" @click.prevent="resetFilters">Reset</a></div>
            </form>

            <nav class="mp-order-tabs" aria-label="Status pesanan">
                <a v-for="[key, label] in Object.entries(TAB_LABELS)" :key="key" href="#" :aria-current="form.order_tab === key ? 'page' : undefined" @click.prevent="setTab(key)"><span>{{ label }}</span><strong>{{ key === 'returns' && returnUnavailable ? `${orders.counts[key] ?? 0} tercatat · cakupan belum lengkap` : count(orders.counts[key] ?? 0) }}</strong></a>
            </nav>
            <div class="mp-order-notice"><JlIcon name="info" /><p>Permintaan pembatalan belum berarti pesanan dibatalkan. Resi dibuat belum berarti paket dikirim. Status pengembalian dan refund ditampilkan terpisah dari status pengiriman. Jumlah kartu adalah pesanan unik, bukan jumlah kasus.</p></div>

            <template v-if="orders.tab === 'returns'">
                <form class="mp-order-filter" @submit.prevent="loadOrders(1)">
                    <label>Jenis kasus<select v-model="form.return_type"><option v-for="[value, label] in RETURN_TYPES" :key="value" :value="value">{{ label }}</option></select></label>
                    <label>Basis tanggal<select v-model="form.return_date"><option value="created">Tanggal pengajuan kasus</option><option value="updated">Tanggal pembaruan kasus</option><option value="order">Tanggal pesanan</option></select></label>
                    <button class="mp-order-button primary">Terapkan</button>
                </form>
                <p v-if="orders.return_coverage" class="mp-order-warning">Pengembalian: {{ orders.return_coverage.status }} · Terakhir berhasil: {{ orders.return_coverage.last_success ?? 'Belum tersedia' }}<template v-if="orders.return_coverage.error"> · {{ orders.return_coverage.error }}</template>. Barang kembali belum otomatis menambah stok atau membalik HPP.</p>
            </template>

            <article class="mp-order-list">
                <div class="mp-order-toolbar">
                    <div><strong>{{ count(orders.total) }} pesanan</strong><p>{{ orders.tab === 'returns' ? RETURN_DATES[orders.return_date ?? 'created'] : 'Tanggal dibuat' }} · {{ orders.period.start }} — {{ orders.period.end }} WIB</p></div>
                    <div class="mp-order-actions">
                        <button type="button" class="mp-order-button" :disabled="!live || selected.length === 0 || selected.length > 20" @click="printLabels(selected)"><JlIcon name="download" /> Cetak Resi Terpilih <span>{{ selected.length }}</span></button>
                        <label>Baris <select :value="form.per_page" @change="changePerPage"><option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ n }}</option></select></label>
                    </div>
                </div>
                <div class="mp-order-table-wrap">
                    <table>
                        <thead><tr><th><input type="checkbox" aria-label="Pilih pesanan pada halaman ini" :disabled="!live" :checked="allSelected" @change="toggleAll" /></th><th>Tanggal</th><th>Kode &amp; Detail Produk</th><th>Penerima</th><th>Nilai Pesanan</th><th>Jumlah</th><th>Pembayaran</th><th>Kurir &amp; Resi</th><th>Status</th><th>Dokumen &amp; Aksi</th></tr></thead>
                        <tbody>
                            <tr v-for="row in orders.rows" :key="row.id">
                                <td><input v-model="selected" type="checkbox" :value="String(row.id)" :disabled="!live" :aria-label="`Pilih pesanan ${row.id}`" /></td>
                                <td class="mp-date">{{ row.date }}</td>
                                <td>
                                    <button type="button" class="mp-order-id" @click="openDetail(row)">{{ row.id }}</button><button type="button" class="mp-copy" aria-label="Salin nomor pesanan" @click="copy(row.id)"><JlIcon name="compare" /></button>
                                    <div v-for="item in (row.items ?? []).slice(0, 2)" :key="`${row.id}-${item.sku}-${item.name}`" class="mp-order-product">
                                        <img v-if="/^https:\/\//i.test(item.image_url ?? '')" :src="item.image_url" alt="" loading="lazy" referrerpolicy="no-referrer" />
                                        <div><strong>{{ item.name }}</strong><small>{{ item.variant }} · SKU {{ item.sku }}</small></div>
                                    </div>
                                    <small v-if="(row.items ?? []).length > 2">+{{ row.items.length - 2 }} produk lainnya</small>
                                </td>
                                <td>{{ row.recipient || 'Belum tersedia' }}</td>
                                <td class="mp-money">{{ money(row.amount) }}<small>Nilai dari sumber pesanan</small></td>
                                <td>{{ row.quantity === null || row.quantity === undefined ? 'Belum tersedia' : `${row.quantity} unit` }}<small>{{ (row.packages ?? []).length ? `${row.packages.length} paket tercatat` : 'Paket belum tersedia' }}</small></td>
                                <td><template v-if="row.is_cod !== true">{{ row.payment_method || 'Belum tersedia' }}</template><span v-else class="mp-order-badge">COD</span></td>
                                <td>{{ row.courier || 'Kurir belum tersedia' }}<small>{{ row.tracking || 'Resi belum tersedia' }}</small><button v-if="row.tracking" type="button" class="mp-copy" aria-label="Salin resi" @click="copy(row.tracking)"><JlIcon name="compare" /></button></td>
                                <td>
                                    <span class="mp-order-badge" :class="`status-${row.group}`">{{ row.status || 'Belum Dipetakan' }}</span>
                                    <small v-if="row.cancel_requested" class="mp-order-warning-text">Pengajuan Pembatalan</small>
                                    <details v-for="returnCase in row.return_cases ?? []" :key="returnCase.id" class="mp-detail-item" style="min-width: 220px; max-width: 460px; white-space: normal; margin-top: 8px">
                                        <summary><strong>{{ returnCase.type_label }}</strong> · {{ returnCase.status }}</summary>
                                        <p>ID kasus: {{ returnCase.id }}</p><p>Status sumber: {{ returnCase.source_status }}</p>
                                        <p>{{ returnCase.refund_status }} · Nominal sumber: {{ returnCase.currency ?? 'Mata uang belum tersedia' }} {{ returnCase.refund_amount ?? 'Belum tersedia' }}</p>
                                        <p>Alasan: {{ returnCase.reason || 'Belum tersedia' }}</p>
                                        <p>Pengiriman balik: {{ returnCase.courier || 'Belum tersedia' }} · {{ returnCase.tracking || 'Resi belum tersedia' }}</p>
                                        <p>Arbitrase: {{ returnCase.arbitration || 'Belum tersedia' }}</p>
                                        <p>Sumber: API marketplace · Disinkronkan {{ returnCase.synced_at }}</p>
                                        <small>Nominal kasus tidak dikurangi lagi dari Untuk Dibayar yang sudah mencakup refund. Stok dan HPP tidak dibalik otomatis.</small>
                                    </details>
                                    <small v-if="row.complaint_active">Komplain aktif terkonfirmasi</small>
                                </td>
                                <td>
                                    <small class="mp-order-warning-text">{{ active_store.platform === 'TikTok Shop' ? 'Resi + daftar pengemasan' : 'Label API belum tersedia' }}</small>
                                    <button type="button" class="mp-small-button" :disabled="!live" @click="printLabels([String(row.id)])">Cetak Resi</button>
                                    <button type="button" class="mp-small-button" @click="openDetail(row)">Lihat Detail</button>
                                    <Link class="mp-small-button" :href="`/pilot/marketplace/orders?store=${scope.store_id}&store_id=${scope.store_id}&search=${encodeURIComponent(String(row.id))}&search_by=all`">Buka Transaksi</Link>
                                </td>
                            </tr>
                            <tr v-if="!orders.rows.length"><td colspan="10" class="mp-order-empty">{{ orders.tab === 'returns' ? 'Tidak ada kasus pada filter ini. Periksa status akses dan periode sinkronisasi.' : 'Tidak ada pesanan sesuai filter. Ubah periode atau sinkronkan toko.' }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <footer class="mp-order-pagination">
                    <span>Halaman {{ orders.page }} / {{ orders.pages }} · {{ orders.rows.length }} pesanan ditampilkan</span>
                    <div><a v-if="orders.page > 1" class="mp-order-button" href="#" @click.prevent="loadOrders(orders.page - 1)">Sebelumnya</a><a v-if="orders.page < orders.pages" class="mp-order-button" href="#" @click.prevent="loadOrders(orders.page + 1)">Berikutnya</a></div>
                </footer>
            </article>

            <div class="mp-order-notice"><p><strong>Dokumen pengiriman:</strong> Untuk TikTok Shipping, gunakan Atur Pengiriman di dialog resi saat paket belum diatur. Pilihan pickup/drop-off dan jadwal mengikuti API. Resi dan daftar pengemasan dapat diunduh sesuai kesiapan paket. Konektor Shopee dan Seller Shipping belum mendukung alur ini.</p><a :href="provider?.seller_center ?? '#'" target="_blank" rel="noopener noreferrer" class="mp-order-button">Buka Seller Center</a></div>
            <p v-if="copied" class="mp-order-live-status" role="status" aria-live="polite">{{ copied }}</p>
        </section>

        <ShippingLabels :open="labelsOpen" :store-id="String(scope.store_id ?? '')" :order-ids="labelIds" :seller-center="provider?.seller_center ?? null" :can-fulfill="provider?.can_fulfill === true" @close="labelsOpen = false" />

        <Modal :open="detailOpen" title="Detail Pesanan" :busy="detailBusy" @close="detailOpen = false">
            <p v-if="detailBusy" class="p-4 text-xs text-gray-500">Memuat rincian…</p>
            <p v-else-if="detailError" class="p-4 text-xs" style="color: #b42318" role="alert">{{ detailError }}</p>
            <div v-else-if="presentation" class="p-4 bg-gray-50 text-xs space-y-4">
                <p class="font-bold">No. Pesanan {{ presentation.order_id }} · {{ presentation.status }}</p>
                <p class="text-gray-500">Resi: {{ presentation.tracking_number || 'Belum tersedia' }} · Pembayaran: {{ presentation.is_cod === true ? 'COD' : presentation.payment_method || 'Belum tersedia' }}<template v-if="presentation.is_estimated === true"> · Estimasi Penghasilan</template></p>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div v-for="[label, value] in [['Nilai', presentation.gross_amount], ['Net income', presentation.net_income], ['HPP', presentation.total_cogs], ['Laba bersih', presentation.net_profit]]" :key="label" class="bg-white rounded-lg border border-gray-100 p-3 min-w-0"><div class="text-gray-500 text-[11px]">{{ label }}</div><div class="font-semibold mt-1 break-words">{{ money(value) }}</div></div>
                </div>
                <div v-if="live && presentation.platform === 'TikTok Shop'" class="flex items-center gap-3">
                    <button class="mp-small-button" type="button" :disabled="financeBusy" @click="refreshFinance">{{ financeBusy ? 'Mengambil data resmi…' : 'Perbarui dari Finance API' }}</button>
                    <span v-if="financeMessage" class="text-gray-500" role="status">{{ financeMessage }}</span>
                </div>
                <h3 class="font-bold">Detail Produk</h3>
                <div v-for="item in presentation.items" :key="`${item.sku}-${item.name}`" class="bg-white rounded-xl border border-gray-200 p-3">
                    <div class="flex gap-3">
                        <img v-if="/^https?:\/\//i.test(item.image_url ?? '')" :src="item.image_url" alt="" class="w-12 h-12 object-cover rounded" />
                        <div><p class="font-semibold">{{ item.name || 'Produk belum tersedia' }}</p><p class="text-[11px] text-gray-400 mt-1">Varian: {{ item.variant || '—' }} · SKU: {{ item.sku || '—' }}</p></div>
                    </div>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                        <div v-for="[label, value] in [['Jumlah', item.quantity], ['Harga Satuan', money(item.unit_price)], ['Total Harga', money(item.total_price)], ['Total HPP', money(item.total_cogs)]]" :key="label" class="bg-white rounded-lg border border-gray-100 p-3 min-w-0"><div class="text-gray-500 text-[11px]">{{ label }}</div><div class="font-semibold mt-1 break-words">{{ value }}</div></div>
                    </div>
                </div>
                <p v-if="!(presentation.items ?? []).length" class="text-gray-400">Rincian produk belum tersedia.</p>
                <template v-if="(presentation.packages ?? []).length || (presentation.return_cases ?? []).length">
                    <h3 class="font-bold">Paket dan retur</h3>
                    <p v-for="pack in presentation.packages || []" :key="pack.id">Paket {{ pack.id }} · {{ pack.courier || 'Kurir belum tersedia' }} · {{ pack.tracking || 'Resi belum tersedia' }}</p>
                    <p v-for="item in presentation.return_cases || []" :key="item.id" class="mp-order-warning-text">{{ item.type_label }} · {{ item.status }} · {{ item.refund_status }}</p>
                </template>
            </div>
        </Modal>
    </MarketplaceShell>
</template>
