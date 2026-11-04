<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import KpiCard from '../../Components/KpiCard.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatDate, formatText, formatValue } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    stock: Row;
    filters: Row;
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    can_edit_minimum: boolean;
}>();

const STATUS_LABEL: Record<string, string> = {
    safe: 'Aman', low: 'Menipis', out: 'Habis', unknown: 'Belum tersedia', unconfigured: 'Minimum belum diatur',
};
const STATUS_TONE: Record<string, 'ok' | 'warn' | 'danger' | 'muted' | 'info'> = {
    safe: 'ok', low: 'warn', out: 'danger', unknown: 'muted', unconfigured: 'info',
};

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);
const mode = ref<'product' | 'sku'>('product');

const filter = reactive({
    store_id: props.scope.store_id ?? '',
    date_start: props.stock.date_start ?? '',
    date_end: props.stock.date_end ?? '',
    search: props.filters.search ?? '',
    warehouse: props.filters.warehouse ?? '',
    status: props.filters.status ?? '',
    sort: props.filters.sort ?? 'name',
});

function applyFilters(): void {
    const query: Record<string, string> = {};
    Object.entries(filter).forEach(([key, value]) => {
        if (value !== '') query[key] = String(value);
    });
    router.get('/marketplace/stock', query, { preserveState: true, replace: true });
}

const rows = computed<Row[]>(() => props.stock.rows ?? []);
const groups = computed(() => {
    const map = new Map<string, Row[]>();
    rows.value.forEach((row) => map.set(row.external_product_id, [...(map.get(row.external_product_id) ?? []), row]));

    return [...map.entries()].map(([id, variants]) => ({ id, name: variants[0].product_name as string, variants }));
});
const expanded = reactive<Record<string, boolean>>({});

/** A group total is only shown when every variant has the value; otherwise it would be a guess. */
function total(variants: Row[], key: string): string {
    return variants.some((variant) => variant[key] === null || variant[key] === undefined)
        ? 'Belum lengkap'
        : formatValue(variants.reduce((sum, variant) => sum + Number(variant[key]), 0), 'decimal');
}

const summary = computed(() => props.stock.summary ?? {});
const knownStock = computed(() => (summary.value.unknown ? `Belum lengkap · ${summary.value.unknown} SKU belum diketahui` : formatValue(summary.value.known_stock, 'decimal')));

// ----- minimum editor -----
const editor = reactive({ open: false, row: null as Row | null });
const minimum = ref('');
const formError = ref<string | null>(null);

function openEditor(row: Row): void {
    editor.open = true;
    editor.row = row;
    minimum.value = row.minimum === null || row.minimum === undefined ? '' : String(row.minimum);
    formError.value = null;
}

async function saveMinimum(): Promise<void> {
    const row = editor.row;
    if (!row || !props.scope.store_id) return;
    busy.value = true;
    formError.value = null;
    try {
        const result = await apiRequest('/api/marketplace-stock.php', {
            store_id: props.scope.store_id,
            product_id: row.external_product_id,
            variant_id: row.external_variant_id,
            warehouse_id: props.stock.warehouse ?? '',
            minimum: minimum.value,
        });
        flash.message = result.message ?? 'Minimum stok tersimpan.';
        flash.kind = 'success';
        editor.open = false;
        router.reload({ only: ['stock'] });
    } catch (error) {
        formError.value = error instanceof ApiError ? error.message : 'Minimum stok belum tersimpan.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <MarketplaceShell
        active="stock"
        title="Inventori & stok"
        eyebrow="Marketplace / Stok"
        subtitle="Stok marketplace toko ini. Terpisah dari stok fisik gudang pabrik."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <section class="panel notice">
            <p class="muted">
                Stok saat ini adalah kuantitas dari data marketplace terakhir yang tersimpan, bukan stok fisik pabrik. Stok awal memakai pembacaan terakhir pada atau sebelum awal periode.
                Terjual dihitung dari unit pesanan tersimpan, tidak termasuk yang belum dibayar atau dibatalkan. Jumlah retur belum tersedia dari sumber.
                <span v-if="stock.warehouse">Penjualan per gudang belum tersedia dari sumber.</span>
            </p>
        </section>

        <form class="panel filter-bar" @submit.prevent="applyFilters">
            <div class="field filter-store">
                <label for="s-store">Toko</label>
                <select id="s-store" v-model="filter.store_id"><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
            <div class="field"><label for="s-start">Awal periode</label><input id="s-start" v-model="filter.date_start" type="date" required /></div>
            <div class="field"><label for="s-end">Akhir periode</label><input id="s-end" v-model="filter.date_end" type="date" required /></div>
            <div class="field"><label for="s-search">Produk / SKU / varian</label><input id="s-search" v-model="filter.search" type="search" maxlength="200" /></div>
            <div class="field">
                <label for="s-wh">Gudang</label>
                <select id="s-wh" v-model="filter.warehouse"><option value="">Semua gudang · total</option><option v-for="id in Object.values(stock.warehouses ?? {})" :key="String(id)" :value="id">{{ id }}</option></select>
            </div>
            <div class="field">
                <label for="s-status">Status</label>
                <select id="s-status" v-model="filter.status"><option value="">Semua status</option><option v-for="(label, key) in STATUS_LABEL" :key="key" :value="key">{{ label }}</option></select>
            </div>
            <div class="field">
                <label for="s-sort">Urutkan</label>
                <select id="s-sort" v-model="filter.sort"><option value="name">Nama produk</option><option value="stock">Stok terkecil</option><option value="sold">Terjual terbanyak</option></select>
            </div>
            <div class="field">
                <label for="s-mode">Tampilkan</label>
                <select id="s-mode" v-model="mode"><option value="product">Per produk</option><option value="sku">Per SKU</option></select>
            </div>
            <button class="button button-primary" type="submit">Terapkan</button>
        </form>

        <section class="kpi-grid" aria-label="Ringkasan stok">
            <KpiCard label="Varian aktif dalam filter" :value="summary.sku" kind="count" />
            <KpiCard label="Total kuantitas" :value="knownStock" />
            <KpiCard label="SKU menipis" :value="summary.low" kind="count" />
            <KpiCard label="SKU habis" :value="summary.out" kind="count" />
        </section>

        <section class="panel">
            <p v-if="!rows.length" class="muted">Belum ada varian aktif sesuai filter. Sinkronkan katalog toko terlebih dahulu.</p>
            <div v-else class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Detail produk</th><th class="num">Awal</th><th class="num">Min</th><th class="num">Terjual</th><th class="num">Retur</th><th class="num">Stok saat ini</th><th>Status</th><th v-if="can_edit_minimum"><span class="sr-only">Aksi</span></th></tr>
                    </thead>
                    <tbody v-for="group in groups" :key="group.id">
                        <tr v-if="mode === 'product'" class="group-row">
                            <td>
                                <button class="link-button" type="button" :aria-expanded="!!expanded[group.id]" @click="expanded[group.id] = !expanded[group.id]">{{ group.name }}</button>
                                <div class="muted cell-note">{{ group.variants.length }} varian · {{ expanded[group.id] ? 'tutup' : 'buka' }} rincian</div>
                            </td>
                            <td class="num">{{ total(group.variants, 'initial') }}</td>
                            <td class="num muted">Per varian</td>
                            <td class="num">{{ total(group.variants, 'sold') }}</td>
                            <td class="num">{{ total(group.variants, 'returned') }}</td>
                            <td class="num"><strong>{{ total(group.variants, 'current') }}</strong></td>
                            <td class="muted">Grup</td>
                            <td v-if="can_edit_minimum"></td>
                        </tr>
                        <tr v-for="row in group.variants" v-show="mode === 'sku' || expanded[group.id]" :key="row.external_variant_id">
                            <td>
                                <strong>{{ mode === 'sku' ? row.product_name : formatText(row.variant_name) }}</strong>
                                <div class="muted cell-note">{{ formatText(row.variant_name) }} · SKU: {{ formatText(row.sku) }}</div>
                                <div class="muted cell-note">Dibaca: {{ formatDate(row.fetched_at) }}</div>
                            </td>
                            <td class="num">{{ formatValue(row.initial, 'decimal') }}</td>
                            <td class="num">{{ formatValue(row.minimum, 'decimal') }}</td>
                            <td class="num">{{ formatValue(row.sold, 'decimal') }}</td>
                            <td class="num">{{ formatValue(row.returned, 'decimal') }}</td>
                            <td class="num"><strong>{{ formatValue(row.current, 'decimal') }}</strong></td>
                            <td><StatusBadge :label="STATUS_LABEL[row.stock_status] ?? row.stock_status" :tone="STATUS_TONE[row.stock_status] ?? 'muted'" /></td>
                            <td v-if="can_edit_minimum"><button class="button button-secondary button-small" type="button" @click="openEditor(row)">Atur minimum</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Modal :open="editor.open" title="Atur minimum stok" :busy="busy" @close="editor.open = false">
            <form v-if="editor.row" class="form-stack" @submit.prevent="saveMinimum">
                <p><strong>{{ editor.row.product_name }}</strong><br /><span class="muted">{{ editor.row.variant_name }} · SKU {{ editor.row.sku }}</span></p>
                <p>Stok saat ini: <strong>{{ formatValue(editor.row.current, 'decimal') }}</strong></p>
                <div class="field"><label for="m-min">Minimum stok</label><input id="m-min" v-model="minimum" type="number" min="0" max="9999999999" step="0.001" required /></div>
                <p class="muted cell-note">Pengaturan internal Jago Laba. Stok di marketplace tidak diubah.</p>
                <p v-if="formError" class="inline-error" role="alert">{{ formError }}</p>
                <div class="form-actions">
                    <button class="button button-secondary" type="button" @click="editor.open = false">Batal</button>
                    <button class="button button-primary" type="submit" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </Modal>
    </MarketplaceShell>
</template>
