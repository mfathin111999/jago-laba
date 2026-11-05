<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import JlIcon from '../../Components/JlIcon.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import PriceEditor from '../../Components/PriceEditor.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatValue } from '../../lib/format';
import { ROUTES } from '../../lib/routes';

type Row = Record<string, any>;

const props = defineProps<{
    catalog: Row;
    filters: Row;
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    can_edit_hpp: boolean;
    provider?: { live: boolean; can_edit_price: boolean };
}>();

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);

const filter = reactive({
    store_id: props.scope.store_id ?? '',
    search: props.filters.search ?? '',
    date_start: props.filters.date_start ?? '',
    date_end: props.filters.date_end ?? '',
});

function applyFilters(): void {
    const query: Record<string, string> = {};
    Object.entries(filter).forEach(([key, value]) => {
        if (value !== '') query[key] = String(value);
    });
    router.get(ROUTES.catalog, query, { preserveState: true, replace: true });
}

const exportUrl = computed(() => {
    const query = new URLSearchParams({ store_id: filter.store_id, format: 'csv' });
    (['search', 'date_start', 'date_end'] as const).forEach((key) => filter[key] && query.set(key, filter[key]));

    return `/marketplace/catalog?${query.toString()}`;
});

const products = computed<Row[]>(() => props.catalog.products ?? []);
const activeStore = computed(() => props.stores.find((store) => store.id === props.scope.store_id) ?? null);
const money = (value: unknown) => formatValue(value, 'currency');
const count = (value: unknown) => formatValue(value, 'count');
const summary = computed<Array<[string, number]>>(() => [
    ['Total Produk', products.value.length],
    ['Terjual (14 Hari)', Number(props.catalog.sold ?? 0)],
    ['Produk dengan HPP', products.value.length - Number(props.catalog.missing_hpp ?? 0)],
    ['Toko dalam hasil', new Set(products.value.map((product) => product.store_id)).size],
]);
const updatedAt = computed(() => {
    const times = products.value.flatMap((product) => product.variants.map((variant: Row) => variant.source_updated_at).filter(Boolean));

    return times.length ? times.sort().at(-1) : null;
});
const opened = ref(new Set<number>());
const toggle = (index: number) => {
    const next = new Set(opened.value);
    next.has(index) ? next.delete(index) : next.add(index);
    opened.value = next;
};
const range = (variants: Row[], field: string) => {
    const values = variants.map((variant) => variant[field]).filter((value) => value !== null && value !== undefined).map(Number);
    if (!values.length) return 'Belum tersedia';
    const low = Math.min(...values);
    const high = Math.max(...values);

    return low === high ? money(low) : `${money(low)} – ${money(high)}`;
};
const resetFilters = () => {
    Object.assign(filter, { search: '', date_start: '', date_end: '' });
    applyFilters();
};
const hppMissing = (variant: Row): boolean => variant.hpp === null || variant.hpp === undefined || Number(variant.hpp) <= 0;

// ----- price editor (live mode: reads and writes prices at the marketplace) -----
const priceEditor = reactive({ open: false, productId: '' });
const canEditPrice = computed(() => props.provider?.live === true && props.provider?.can_edit_price === true);
const openPrice = (product: Row): void => {
    priceEditor.productId = String(product.variants[0]?.external_product_id ?? '');
    priceEditor.open = priceEditor.productId !== '';
};

// ----- HPP editor -----
const editor = reactive({ open: false, product: null as Row | null, variant: null as Row | null });
const form = reactive({ hpp: '', effective_from: '', recalculate_history: false, apply_all_variants: false });
const formError = ref<string | null>(null);
const today = () => new Date().toISOString().slice(0, 10);

function openEditor(product: Row, variant: Row): void {
    Object.assign(editor, { open: true, product, variant });
    Object.assign(form, { hpp: variant.hpp ? String(variant.hpp) : '', effective_from: today(), recalculate_history: false, apply_all_variants: false });
    formError.value = null;
}

async function saveHpp(): Promise<void> {
    if (!editor.variant || !props.scope.store_id) return;
    busy.value = true;
    formError.value = null;
    try {
        const result = await apiRequest(ROUTES.hppGlobal, {
            store_id: props.scope.store_id,
            hpp: form.hpp,
            sku: editor.variant.sku,
            product_id: editor.variant.external_product_id,
            effective_from: form.effective_from || null,
            recalculate_history: form.recalculate_history,
            apply_all_variants: form.apply_all_variants,
        });
        flash.message = result.message ?? 'HPP tersimpan.';
        flash.kind = 'success';
        editor.open = false;
        router.reload({ only: ['catalog'] });
    } catch (error) {
        formError.value = error instanceof ApiError ? error.message : 'HPP belum berhasil disimpan.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <MarketplaceShell active="catalog" title="Produk Toko" :store-id="scope.store_id" :company-key="scope.company_key">
        <section class="space-y-5" aria-labelledby="store-products-title">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs text-slate-500 mb-1">{{ activeStore?.platform }} · {{ activeStore?.store_name }}</p>
                    <h1 id="store-products-title" class="text-2xl font-extrabold text-slate-900">Produk Toko</h1>
                    <p class="text-xs text-slate-500 mt-2">Kelola katalog produk dan varian toko ini.</p>
                </div>
                <div class="flex gap-2">
                    <a class="border rounded-xl bg-white px-4 py-2 text-xs font-bold" :href="exportUrl">↓ Unduh CSV</a>
                    <a href="#" class="border rounded-xl bg-white px-4 py-2 text-xs font-bold" @click.prevent="applyFilters">↻ Muat ulang</a>
                </div>
            </header>
            <div class="rounded-xl border border-blue-100 bg-blue-50 p-4 text-xs text-blue-900">Sumber: API {{ activeStore?.platform }} · {{ updatedAt ? `Pembaruan data sumber: ${updatedAt} WIB` : 'Katalog API belum tersedia.' }} <span class="block mt-1">Halaman menampilkan katalog terakhir yang tersimpan dari toko ini.</span><p v-if="activeStore && !activeStore.is_connection_verified" class="mt-2 text-amber-800">Koneksi API toko belum terverifikasi. <Link class="font-bold underline" :href="ROUTES.integrations">Buka Integrasi API</Link> untuk menghubungkan toko {{ activeStore?.platform }}.</p></div>
            <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

            <form class="flex flex-wrap gap-3 bg-white rounded-2xl border p-4 items-end" @submit.prevent="applyFilters">
                <label class="flex-1 min-w-48 text-xs font-semibold">Cari produk / SKU / varian<input v-model="filter.search" type="search" maxlength="200" placeholder="Nama produk, SKU, atau varian…" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="text-xs font-semibold">Terjual dari<input v-model="filter.date_start" type="date" class="block mt-2 border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="text-xs font-semibold">Sampai<input v-model="filter.date_end" type="date" class="block mt-2 border rounded-xl px-3 py-2 font-normal" /></label>
                <button class="rounded-xl bg-brand-600 text-white text-xs font-bold px-4 py-2" type="submit">Terapkan</button>
                <a class="border rounded-xl text-xs px-4 py-2" href="#" @click.prevent="resetFilters">Reset</a>
            </form>

            <section class="space-y-4">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div v-for="[label, value] in summary" :key="label" class="rounded-2xl border bg-white p-5"><p class="font-extrabold text-xl">{{ count(value) }}</p><p class="text-xs text-slate-500 mt-2">{{ label }}</p></div>
                </div>
                <div v-if="catalog.missing_hpp" class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-xs">{{ catalog.missing_hpp }} produk belum memiliki HPP lengkap. Klik pensil pada HPP produk atau buka varian untuk mengatur HPP.</div>
                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
                    <nav class="flex flex-wrap gap-1 p-2 bg-slate-50 border-b" aria-label="Menu produk">
                        <span class="px-4 py-2 rounded-lg text-xs bg-white shadow-sm font-bold text-slate-900" aria-current="page">Harga Pokok Produk</span>
                        <Link class="px-4 py-2 rounded-lg text-xs text-slate-500" :href="`/marketplace/stock?store=${scope.store_id}&store_id=${scope.store_id}`">Inventori &amp; Stok</Link>
                    </nav>
                    <p class="p-4 text-[11px] text-slate-500 border-b">Produk, varian, harga, stok, dan status berasal dari katalog API toko yang dipilih. HPP yang disimpan memperbarui transaksi terkait. Gunakan ikon tanggal jika HPP hanya berlaku sejak tanggal tertentu.</p>
                    <div class="overflow-auto max-h-[750px]">
                        <table class="w-full text-xs whitespace-nowrap jl-table">
                            <thead class="bg-slate-50 text-slate-500 uppercase text-[10px]"><tr><th v-for="title in ['Produk', 'SKU', 'Platform', 'Terjual (periode)', 'Harga', 'HPP Produk', 'Varian', 'Status']" :key="title" class="p-3 text-left">{{ title }}</th></tr></thead>
                            <tbody>
                                <template v-for="(product, index) in products" :key="`${product.store_id}:${product.variants[0]?.external_product_id}:${product.name}`">
                                    <tr class="border-t hover:bg-slate-50">
                                        <td class="p-3">
                                            <button type="button" class="text-left flex items-center gap-3" :aria-expanded="opened.has(index)" @click="toggle(index)"><span aria-hidden="true">{{ opened.has(index) ? '⌃' : '⌄' }}</span><img v-if="/^https?:\/\//i.test(product.variants[0]?.image_url ?? '')" :src="product.variants[0].image_url" alt="" loading="lazy" class="w-10 h-10 rounded-lg object-cover" /><span class="max-w-[420px] whitespace-normal"><b>{{ product.name }}</b><span class="block text-[10px] text-slate-400 mt-1">{{ product.store_name }}</span></span></button>
                                        </td>
                                        <td class="p-3">{{ product.sku || (product.variants.length === 1 ? product.variants[0].sku : 'Beberapa SKU') }}</td>
                                        <td class="p-3">{{ product.platform }}</td>
                                        <td class="p-3">{{ product.sold }}</td>
                                        <td class="p-3">{{ range(product.variants, 'price') }}<button v-if="canEditPrice && product.variants[0]?.external_product_id" type="button" class="p-2 text-slate-500 hover:text-orange-600" aria-label="Edit harga produk" @click="openPrice(product)"><JlIcon name="pencil" /></button></td>
                                        <td class="p-3">{{ range(product.variants, 'hpp') }} <button v-if="can_edit_hpp" type="button" class="p-2 text-slate-500 hover:text-orange-600" :aria-label="`Edit HPP produk ${product.name}`" @click="openEditor(product, product.variants[0])"><JlIcon name="pencil" /></button></td>
                                        <td class="p-3">{{ product.variants.length }} varian</td>
                                        <td class="p-3">{{ [...new Set(product.variants.map((v: Row) => v.source_status || 'Belum tersedia'))].join(', ') }}</td>
                                    </tr>
                                    <template v-if="opened.has(index)">
                                        <tr v-for="variant in product.variants" :key="variant.external_variant_id" class="bg-slate-50 border-t text-[11px]">
                                            <td class="p-3 pl-10 text-slate-600">└ {{ variant.variant_name || variant.sku }}</td>
                                            <td class="p-3 font-mono">{{ variant.sku }}</td>
                                            <td class="p-3 text-slate-400">{{ product.platform }}</td>
                                            <td class="p-3">{{ variant.sold }}</td>
                                            <td class="p-3">{{ variant.price === null || variant.price === undefined ? 'Belum tersedia' : money(variant.price) }}<template v-if="variant.original_price !== null && variant.original_price > variant.price"><del class="block text-slate-400">{{ money(variant.original_price) }}</del><span class="text-orange-600">{{ Math.round((1 - variant.price / variant.original_price) * 1000) / 10 }}% diskon</span></template></td>
                                            <td class="p-3">{{ hppMissing(variant) ? 'Belum diisi' : money(variant.hpp) }} <button v-if="can_edit_hpp" type="button" class="p-2 text-slate-500 hover:text-brand-600" :aria-label="`Edit HPP SKU ${variant.sku}`" @click="openEditor(product, variant)"><JlIcon name="pencil" /></button></td>
                                            <td class="p-3">{{ variant.variant_name || '—' }}</td>
                                            <td class="p-3">{{ variant.source_status || 'Belum tersedia' }}</td>
                                        </tr>
                                    </template>
                                </template>
                                <tr v-if="!products.length"><td colspan="8" class="p-12 text-center text-slate-500">Belum ada produk katalog API yang sesuai filter. Pastikan toko terhubung dan katalog sudah disinkronkan.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </section>

        <PriceEditor :open="priceEditor.open" :store-id="String(scope.store_id ?? '')" :product-id="priceEditor.productId" @close="priceEditor.open = false" />

        <Modal :open="editor.open" title="Input HPP Produk" :busy="busy" @close="editor.open = false">
            <form v-if="editor.variant" class="p-4 space-y-4 text-xs" @submit.prevent="saveHpp">
                <p class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3"><strong>Tip:</strong> HPP yang disimpan berlaku untuk <strong>semua transaksi SKU terkait</strong>. Gunakan tanggal berlaku untuk menetapkan HPP sejak tanggal tertentu.</p>
                <p><strong>{{ editor.product?.name }}</strong><br /><span class="text-slate-500">SKU {{ editor.variant.sku }}</span></p>
                <label class="block font-semibold">HPP per unit<input v-model="form.hpp" required inputmode="decimal" placeholder="Rp 45.000" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="block font-semibold">Berlaku mulai<input v-model="form.effective_from" type="date" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <div class="space-y-2">
                    <label class="flex items-center gap-2"><input v-model="form.recalculate_history" type="checkbox" /> Hitung ulang transaksi sebelum tanggal berlaku</label>
                    <label class="flex items-center gap-2"><input v-model="form.apply_all_variants" type="checkbox" /> Terapkan ke semua varian produk ini</label>
                </div>
                <p class="text-slate-500">Tanpa hitung ulang, transaksi lama tetap memakai HPP yang berlaku saat itu.</p>
                <p v-if="formError" style="color: #b42318" role="alert">{{ formError }}</p>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button class="border rounded-xl bg-white px-4 py-2 font-bold" type="button" @click="editor.open = false">Batal</button>
                    <button class="rounded-xl bg-brand-600 text-white px-4 py-2 font-bold" type="submit" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan HPP' }}</button>
                </div>
            </form>
        </Modal>
    </MarketplaceShell>
</template>
