<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import KpiCard from '../../Components/KpiCard.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatText, formatValue } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    catalog: Row;
    filters: Row;
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    can_edit_hpp: boolean;
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
    router.get('/marketplace/catalog', query, { preserveState: true, replace: true });
}

const exportUrl = computed(() => {
    const query = new URLSearchParams({ store_id: filter.store_id, format: 'csv' });
    (['search', 'date_start', 'date_end'] as const).forEach((key) => filter[key] && query.set(key, filter[key]));

    return `/marketplace/catalog?${query.toString()}`;
});

const products = computed<Row[]>(() => props.catalog.products ?? []);
const variantCount = computed(() => products.value.reduce((sum, product) => sum + product.variants.length, 0));
const hppMissing = (variant: Row): boolean => variant.hpp === null || variant.hpp === undefined || Number(variant.hpp) <= 0;

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
        const result = await apiRequest('/pilot/marketplace/hpp/global', {
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
    <MarketplaceShell
        active="catalog"
        title="Produk & HPP"
        eyebrow="Marketplace / Produk"
        subtitle="Produk dan varian dari toko terpilih, dengan HPP per SKU."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <template #actions><a class="button button-secondary" :href="exportUrl">Unduh CSV</a></template>

        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <form class="panel filter-bar" @submit.prevent="applyFilters">
            <div class="field filter-store">
                <label for="c-store">Toko</label>
                <select id="c-store" v-model="filter.store_id"><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
            <div class="field"><label for="c-search">Cari produk atau SKU</label><input id="c-search" v-model="filter.search" type="search" maxlength="200" /></div>
            <div class="field"><label for="c-start">Terjual dari</label><input id="c-start" v-model="filter.date_start" type="date" /></div>
            <div class="field"><label for="c-end">Sampai</label><input id="c-end" v-model="filter.date_end" type="date" /></div>
            <button class="button button-primary" type="submit">Terapkan</button>
        </form>

        <section class="kpi-grid" aria-label="Ringkasan produk">
            <KpiCard label="Produk" :value="products.length" kind="count" />
            <KpiCard label="Varian" :value="variantCount" kind="count" />
            <KpiCard label="Terjual pada periode" :value="catalog.sold" kind="count" />
            <KpiCard label="Produk perlu cek HPP" :value="catalog.missing_hpp" kind="count" />
        </section>

        <section v-if="products.length === 0" class="panel empty-state"><h2>Tidak ada produk</h2><p class="muted">Belum ada produk yang cocok dengan filter, atau katalog toko belum tersinkron.</p></section>

        <section v-for="product in products" :key="`${product.store_id}:${product.variants[0]?.external_product_id}:${product.name}`" class="panel">
            <div class="section-heading compact">
                <div><span class="eyebrow">{{ product.store_name }} · {{ product.platform }}</span><h2>{{ product.name }}</h2></div>
                <span class="muted">{{ formatValue(product.sold, 'count') }} terjual</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>SKU / Varian</th><th class="num">Harga</th><th class="num">Stok</th><th class="num">HPP</th><th class="num">Terjual</th><th v-if="can_edit_hpp"><span class="sr-only">Aksi</span></th></tr></thead>
                    <tbody>
                        <tr v-for="variant in product.variants" :key="variant.external_variant_id">
                            <td>{{ formatText(variant.sku) }}<div class="muted cell-note">{{ formatText(variant.variant_name) }}</div></td>
                            <td class="num">{{ formatValue(variant.price) }}</td>
                            <td class="num">{{ formatValue(variant.stock_qty, 'count') }}</td>
                            <td class="num">{{ formatValue(variant.hpp) }}<div v-if="hppMissing(variant)"><StatusBadge label="HPP belum diisi" tone="warn" /></div></td>
                            <td class="num">{{ formatValue(variant.sold, 'count') }}</td>
                            <td v-if="can_edit_hpp"><button class="button button-secondary button-small" type="button" @click="openEditor(product, variant)">Ubah HPP</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Modal :open="editor.open" title="Ubah HPP" :busy="busy" @close="editor.open = false">
            <form v-if="editor.variant" class="form-stack" @submit.prevent="saveHpp">
                <p><strong>{{ editor.product?.name }}</strong><br /><span class="muted">SKU {{ editor.variant.sku }}</span></p>
                <div class="field"><label for="h-hpp">HPP per unit</label><input id="h-hpp" v-model="form.hpp" required inputmode="decimal" placeholder="Rp 45.000" /></div>
                <div class="field"><label for="h-date">Berlaku mulai</label><input id="h-date" v-model="form.effective_from" type="date" /></div>
                <div class="checkbox-row">
                    <label><input v-model="form.recalculate_history" type="checkbox" /> Hitung ulang transaksi sebelum tanggal berlaku</label>
                    <label><input v-model="form.apply_all_variants" type="checkbox" /> Terapkan ke semua varian produk ini</label>
                </div>
                <p class="muted cell-note">Tanpa hitung ulang, transaksi lama tetap memakai HPP yang berlaku saat itu.</p>
                <p v-if="formError" class="inline-error" role="alert">{{ formError }}</p>
                <div class="form-actions">
                    <button class="button button-secondary" type="button" @click="editor.open = false">Batal</button>
                    <button class="button button-primary" type="submit" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan HPP' }}</button>
                </div>
            </form>
        </Modal>
    </MarketplaceShell>
</template>
