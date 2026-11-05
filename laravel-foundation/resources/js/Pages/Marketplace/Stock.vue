<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import JlIcon from '../../Components/JlIcon.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatValue } from '../../lib/format';

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
const activeStore = computed(() => props.stores.find((store) => store.id === props.scope.store_id) ?? null);
const qty = (value: unknown) => formatValue(value, 'decimal');
const sections: Array<[string, string, string]> = [['hpp', 'calculator', 'HPP Produk'], ['stok', 'boxes', 'Inventori & Stok']];
const cards = computed<Array<[string, string | number]>>(() => [
    ['Varian aktif dalam filter', summary.value.sku ?? 0],
    ['Total kuantitas API', summary.value.unknown ? `Belum lengkap · ${summary.value.unknown} SKU belum diketahui` : qty(summary.value.known_stock)],
    ['SKU menipis', summary.value.low ?? 0],
    ['SKU habis', summary.value.out ?? 0],
]);

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
    <MarketplaceShell active="stock" title="Inventori & Stok" :store-id="scope.store_id" :company-key="scope.company_key">
        <section class="space-y-5" aria-labelledby="stock-title">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs text-slate-500 mb-2">{{ activeStore?.platform }} · {{ activeStore?.store_name }}</p>
                    <h1 id="stock-title" class="text-2xl font-extrabold"><JlIcon name="boxes" /> Inventori &amp; Stok</h1>
                    <p class="text-xs text-slate-500 mt-2">Inventory marketplace toko ini. Terpisah dari stok fisik gudang pabrik.</p>
                </div>
                <div class="flex gap-2"><a href="#" class="rounded-xl border bg-white px-4 py-2 text-xs font-bold" @click.prevent="applyFilters">Muat ulang</a></div>
            </header>
            <nav class="flex flex-wrap gap-2" aria-label="Menu produk toko">
                <Link v-for="[key, icon, label] in sections" :key="key" :href="key === 'stok' ? '#' : `/marketplace/catalog?store=${scope.store_id}&store_id=${scope.store_id}`" :aria-current="key === 'stok' ? 'page' : 'false'" class="rounded-xl border px-4 py-2 text-xs font-bold" :class="key === 'stok' ? 'bg-emerald-600 text-white' : 'bg-white text-slate-500'"><JlIcon :name="icon" /> {{ label }}</Link>
            </nav>
            <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-900 leading-6">Stok Saat Ini adalah kuantitas inventory API terbaru yang tersimpan, bukan stok fisik pabrik. Awal memakai snapshot terakhir pada/sebelum awal periode (WIB); histori sebelum snapshot pertama tidak dibuat. Terjual mengikuti unit pesanan tersimpan berstatus perlu dikirim sampai selesai, mengecualikan belum dibayar/batal. Retur hanya akan tampil jika jumlah barang kembali terkonfirmasi; sumber saat ini belum menyediakan pencocokan unit retur. <template v-if="stock.warehouse">Penjualan per gudang belum tersedia dari sumber.</template></div>

            <form class="bg-white border rounded-2xl p-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                <label class="text-xs text-slate-500">Awal periode<input v-model="filter.date_start" type="date" class="block mt-1 border rounded-lg p-2" required /></label>
                <label class="text-xs text-slate-500">Akhir periode<input v-model="filter.date_end" type="date" class="block mt-1 border rounded-lg p-2" required /></label>
                <label class="text-xs text-slate-500 flex-1 min-w-40">Produk / SKU / varian<input v-model="filter.search" maxlength="200" class="block w-full mt-1 border rounded-lg p-2" /></label>
                <label class="text-xs text-slate-500">Gudang<select v-model="filter.warehouse" class="block mt-1 border rounded-lg p-2"><option value="">Semua gudang · total API</option><option v-for="id in Object.values(stock.warehouses ?? {})" :key="String(id)" :value="id">{{ id }}</option></select></label>
                <label class="text-xs text-slate-500">Status<select v-model="filter.status" class="block mt-1 border rounded-lg p-2"><option value="">Semua status</option><option v-for="(label, key) in STATUS_LABEL" :key="key" :value="key">{{ label }}</option></select></label>
                <label class="text-xs text-slate-500">Tampilkan<select v-model="mode" class="block mt-1 border rounded-lg p-2"><option value="product">Per Produk</option><option value="sku">Per SKU</option></select></label>
                <label class="text-xs text-slate-500">Urutkan<select v-model="filter.sort" class="block mt-1 border rounded-lg p-2"><option value="name">Nama produk</option><option value="stock">Stok terkecil</option><option value="sold">Terjual terbanyak</option></select></label>
                <button class="bg-emerald-600 text-white rounded-lg px-4 py-2 text-xs font-bold">Terapkan</button>
            </form>

            <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <article v-for="[label, value] in cards" :key="label" class="bg-white border rounded-2xl p-5"><p class="text-xs text-slate-500">{{ label }}</p><p class="text-xl font-extrabold mt-2">{{ value }}</p></article>
            </div>

            <div class="bg-white border rounded-2xl overflow-hidden">
                <div class="overflow-auto max-h-[720px]">
                    <table class="w-full text-xs whitespace-nowrap">
                        <thead class="bg-slate-50 sticky top-0 z-10"><tr><th v-for="(title, i) in ['Detail Produk', 'Awal', 'Min', 'Terjual', 'Retur', 'Stok Saat Ini', 'Status', 'Edit']" :key="title" class="p-3" :class="i === 0 ? 'text-left' : 'text-right'">{{ title }}</th></tr></thead>
                        <tbody v-for="group in groups" :key="group.id" class="divide-y">
                            <tr v-if="mode === 'product'" class="bg-slate-50">
                                <td class="p-3"><button type="button" :aria-expanded="!!expanded[group.id]" class="flex gap-3 items-center text-left" @click="expanded[group.id] = !expanded[group.id]"><JlIcon name="package" /><span class="whitespace-normal max-w-md font-bold">{{ group.name }}<small class="block text-slate-400 font-normal">{{ group.variants.length }} varian · {{ expanded[group.id] ? 'tutup' : 'buka' }} rincian</small></span></button></td>
                                <td class="p-3 text-right">{{ total(group.variants, 'initial') }}</td><td class="p-3 text-right">Per varian</td><td class="p-3 text-right">{{ total(group.variants, 'sold') }}</td><td class="p-3 text-right">{{ total(group.variants, 'returned') }}</td><td class="p-3 text-right font-bold">{{ total(group.variants, 'current') }}</td><td class="p-3 text-right text-slate-500">Grup</td><td></td>
                            </tr>
                            <tr v-for="row in group.variants" v-show="mode === 'sku' || expanded[group.id]" :key="row.external_variant_id">
                                <td class="p-3">
                                    <div class="flex gap-3 items-center">
                                        <img v-if="/^https?:\/\//i.test(row.image_url ?? '')" :src="row.image_url" class="w-9 h-10 rounded-lg object-contain" alt="" loading="lazy" />
                                        <div class="whitespace-normal max-w-sm"><b>{{ mode === 'sku' ? row.product_name : row.variant_name }}</b><p class="text-slate-500 mt-1">{{ row.variant_name }} · SKU: {{ row.sku }}</p><small class="text-slate-400">API: {{ row.fetched_at ?? 'Waktu pembacaan belum tersedia' }}{{ row.fetched_at ? ' WIB' : '' }}</small></div>
                                    </div>
                                </td>
                                <td v-for="key in ['initial', 'minimum', 'sold', 'returned', 'current']" :key="key" class="p-3 text-right" :class="{ 'font-bold': key === 'current' }">{{ qty(row[key]) }}</td>
                                <td class="p-3 text-right"><span class="px-2 py-1 rounded-full" :class="['out', 'low'].includes(row.stock_status) ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'">{{ STATUS_LABEL[row.stock_status] ?? row.stock_status }}</span></td>
                                <td class="p-3 text-right"><button v-if="can_edit_minimum" type="button" title="Atur minimum stok" :aria-label="`Atur minimum stok ${row.sku}`" class="text-blue-700 p-2" @click="openEditor(row)"><JlIcon name="pencil" /></button><template v-else>—</template></td>
                            </tr>
                        </tbody>
                        <tbody v-if="!rows.length"><tr><td colspan="8" class="p-10 text-center text-slate-500">Belum ada varian aktif sesuai filter. Sinkronkan katalog toko terlebih dahulu.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>

        <Modal :open="editor.open" title="Atur minimum stok" :busy="busy" @close="editor.open = false">
            <form v-if="editor.row" class="p-6 text-xs" @submit.prevent="saveMinimum">
                <p class="text-slate-500">{{ editor.row.product_name }} · {{ editor.row.variant_name }} · {{ editor.row.sku }}</p>
                <p class="mt-3">Stok saat ini: <strong>{{ qty(editor.row.current) }}</strong></p>
                <label class="block mt-4 font-bold">Minimum stok<input v-model="minimum" type="number" min="0" max="9999999999" step="0.001" required class="block w-full mt-2 border rounded-lg p-3" /></label>
                <p class="text-slate-500 mt-3">Pengaturan internal Jago Laba. Konektor penulisan stok marketplace belum tersedia, sehingga stok toko tidak diubah.</p>
                <p v-if="formError" class="text-amber-800 mt-3" role="alert">{{ formError }}</p>
                <footer class="flex gap-3 justify-end mt-5">
                    <button class="border rounded-lg px-4 py-2" type="button" @click="editor.open = false">Batal</button>
                    <button class="bg-emerald-600 text-white rounded-lg px-4 py-2 font-bold" type="submit" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan Minimum Stok' }}</button>
                </footer>
            </form>
        </Modal>
    </MarketplaceShell>
</template>
