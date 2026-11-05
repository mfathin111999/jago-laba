<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import JlIcon from '../../Components/JlIcon.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatValue } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    marketing: Row;
    filters: Row;
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    can_import: boolean;
}>();

const PILLS: Array<{ key: string; label: string }> = [
    { key: '', label: 'Semua Produk' },
    { key: 'roas_rendah', label: 'ROAS Rendah' },
    { key: 'tanpa_hpp', label: 'Tanpa HPP' },
    { key: 'rugi', label: 'Rugi' },
];
type MetricType = 'money' | 'ratio' | 'count' | 'percent';
const METRICS: Array<[string, string, MetricType, string]> = [
    ['ad_spend', 'Biaya Iklan', 'money', 'Belanja iklan tercatat sebelum PPN tambahan.'],
    ['gross_revenue', 'Pendapatan', 'money', 'Pendapatan yang diatribusikan pada laporan iklan, bukan seluruh penjualan toko.'],
    ['gross_roas', 'Gross ROAS', 'ratio', 'Pendapatan ÷ biaya iklan.'],
    ['net_roas', 'Net ROAS', 'ratio', '(Pendapatan − biaya platform − komisi affiliate) ÷ biaya iklan.'],
    ['actual_roas', 'Actual ROAS', 'ratio', '(Pendapatan − biaya platform − komisi affiliate − total HPP) ÷ biaya iklan.'],
    ['min_roas', 'Min. ROAS', 'ratio', 'Pendapatan ÷ laba sebelum iklan. Titik impas; tidak tersedia bila laba sebelum iklan nol atau negatif.'],
    ['orders_count', 'Pesanan', 'count', 'Pesanan yang diatribusikan pada iklan. Qty adalah jumlah unit terjual.'],
    ['cost_per_order', 'Biaya/Pesanan', 'money', 'Biaya iklan ÷ jumlah pesanan.'],
    ['unit_cogs_hpp', 'HPP/Unit', 'money', 'HPP unit yang tercatat pada laporan produk Marketplace.'],
    ['margin_laba_pct', 'Margin Laba', 'percent', '(Pendapatan − total HPP) ÷ pendapatan × 100%.'],
    ['profit_riil', 'Profit Riil', 'money', 'Pendapatan − total HPP − biaya platform − komisi affiliate; sebelum iklan.'],
    ['platform_fee', 'Biaya Platform', 'money', 'Biaya platform yang tercatat pada laporan. Komisi affiliate ditampilkan terpisah pada detail.'],
    ['net_profit', 'Net Profit', 'money', 'Profit riil − biaya iklan.'],
];
const PCT: Record<string, string> = { profit_riil: 'profit_riil_pct', platform_fee: 'platform_fee_pct', net_profit: 'net_profit_pct' };
const money = (value: unknown) => formatValue(value, 'currency');
function fmt(value: unknown, type: MetricType): string {
    const n = Number(value ?? 0);

    return type === 'money' ? money(n) : type === 'ratio' ? `${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)}x` : type === 'percent' ? `${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(n)}%` : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}
function unavailable(product: Row, key: string): boolean {
    return (['gross_roas', 'net_roas', 'actual_roas'].includes(key) && Number(product.ad_spend) <= 0)
        || (key === 'cost_per_order' && Number(product.orders_count) <= 0)
        || (key === 'min_roas' && Number(product.profit_riil) <= 0);
}
const metricColor = (product: Row, key: string) => (key === 'ad_spend' || (key === 'net_profit' && Number(product[key]) < 0) ? 'text-rose-600' : key === 'gross_revenue' ? 'text-blue-600' : 'text-slate-700');

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);

const filter = reactive({
    store_id: props.scope.store_id ?? '',
    filter: props.filters.filter ?? '',
    platform: props.filters.platform ?? '',
    include_ppn: props.filters.include_ppn ? '1' : '',
    search: props.filters.search ?? '',
    date_start: props.filters.date_start ?? '',
    date_end: props.filters.date_end ?? '',
});

function apply(): void {
    const query: Record<string, string> = {};
    Object.entries(filter).forEach(([key, value]) => {
        if (value !== '') query[key] = String(value);
    });
    router.get('/marketplace/marketing', query, { preserveState: true, replace: true });
}

function pick(key: string): void {
    filter.filter = key;
    apply();
}

const kpi = computed(() => props.marketing.kpi ?? {});
const products = computed<Row[]>(() => props.marketing.products ?? []);

const activeStore = computed(() => props.stores.find((store) => store.id === props.scope.store_id) ?? null);
const kpiCards = computed<Array<[string, string]>>(() => [
    ['Biaya Iklan', fmt(kpi.value.biaya_iklan, 'money')],
    ['Pendapatan', fmt(kpi.value.pendapatan, 'money')],
    ['Gross ROAS', fmt(kpi.value.roas, 'ratio')],
    ['Biaya per Pesanan', fmt(kpi.value.cost_per_order, 'money')],
]);

// ----- import -----
const file = ref<File | null>(null);
const importError = ref<string | null>(null);

function onFile(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    importError.value = null;
}

async function importReport(): Promise<void> {
    if (!file.value || !props.scope.store_id) {
        importError.value = 'Pilih berkas CSV terlebih dahulu.';

        return;
    }
    busy.value = true;
    importError.value = null;
    try {
        const body = new FormData();
        body.set('store_id', props.scope.store_id);
        body.set('report', file.value);
        const result = await apiRequest('/api/marketplace-ajax.php?action=import_product_ad_report', body);
        flash.message = result.message ?? 'Laporan iklan diimpor.';
        flash.kind = 'success';
        file.value = null;
        router.reload({ only: ['marketing'] });
    } catch (error) {
        importError.value = error instanceof ApiError ? error.message : 'Impor belum berhasil. Tidak ada data yang diubah.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <MarketplaceShell active="marketing" title="Iklan & Marketing" :store-id="scope.store_id" :company-key="scope.company_key">
        <section id="global-marketing" class="space-y-4">
            <header>
                <p class="text-xs text-slate-500 mb-1">{{ activeStore?.platform }} · {{ activeStore?.store_name }}</p>
                <h1 class="text-2xl font-extrabold text-slate-900">Iklan &amp; Marketing</h1>
                <p class="text-xs text-slate-500 mt-2">Performa iklan produk dari laporan yang Anda impor.</p>
            </header>
            <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

            <form class="flex flex-wrap gap-3 bg-white rounded-2xl border p-4 items-end" @submit.prevent="apply">
                <label class="text-xs font-semibold">Platform<select v-model="filter.platform" class="block mt-2 border rounded-xl px-3 py-2 font-normal"><option value="">Semua</option><option>Shopee</option><option>TikTok Shop</option></select></label>
                <label class="text-xs font-semibold">Dari<input v-model="filter.date_start" type="date" class="block mt-2 border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="text-xs font-semibold">Sampai<input v-model="filter.date_end" type="date" class="block mt-2 border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="flex-1 min-w-48 text-xs font-semibold">Cari<input v-model="filter.search" type="search" maxlength="200" placeholder="Produk, SKU, kampanye" class="mt-2 w-full border rounded-xl px-3 py-2 font-normal" /></label>
                <label class="text-xs font-semibold flex items-center gap-2 pb-2"><input v-model="filter.include_ppn" type="checkbox" true-value="1" false-value="" /> Tambah PPN 11% pada biaya iklan</label>
                <button class="rounded-xl bg-brand-600 text-white text-xs font-bold px-4 py-2" type="submit">Terapkan</button>
            </form>

            <details v-if="can_import" class="rounded-2xl border border-slate-200 bg-white p-4 text-xs">
                <summary class="cursor-pointer font-bold text-brand-700">Impor rincian iklan produk Shopee / TikTok</summary>
                <p class="mt-3 text-slate-500 leading-6">Siapkan laporan produk marketplace dengan <a href="/marketplace/marketing/template" download class="text-blue-600 underline">template CSV</a>. Isi angka tanpa Rp/pemisah ribuan, desimal memakai titik, tanggal YYYY-MM-DD. Gunakan biaya iklan sebelum PPN tambahan dan komisi affiliate terpisah dari biaya platform. Impor ulang produk/campaign pada periode yang sama mengganti nilai sebelumnya; periode tumpang tindih ditolak.</p>
                <form class="flex flex-wrap items-end gap-3 mt-3" @submit.prevent="importReport">
                    <label class="grid gap-1">Laporan CSV (maks. 2 MB)<input type="file" accept=".csv,text/csv" required class="border rounded-lg p-2" @change="onFile" /></label>
                    <button class="rounded-lg bg-brand-600 text-white px-4 py-2 font-bold" type="submit" :disabled="busy || !file">{{ busy ? 'Mengimpor…' : 'Impor laporan' }}</button>
                </form>
                <p v-if="importError" class="mt-3 text-rose-600" role="alert">{{ importError }}</p>
            </details>

            <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
                <div v-for="[label, value] in kpiCards" :key="label" class="bg-white border border-slate-200 rounded-2xl p-5"><p class="text-xs text-slate-500">{{ label }}</p><p class="mt-2 text-xl font-extrabold">{{ value }}</p></div>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <a v-for="pill in PILLS" :key="pill.key" href="#" :aria-current="filter.filter === pill.key ? 'true' : undefined" class="rounded-xl border px-4 py-2 font-bold" :class="filter.filter === pill.key ? 'bg-brand-50 text-brand-700 border-brand-500' : 'bg-white border-slate-200'" @click.prevent="pick(pill.key)">{{ pill.label }}</a>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-900">Laporan produk yang tersimpan di database; konektor API iklan Shopee/TikTok belum aktif. KPI mengikuti produk dan filter pada tabel ini. Iklan grup/toko tidak dialokasikan ke produk. Filter tanggal hanya menyertakan laporan yang seluruh periodenya berada dalam rentang pilihan; angka tidak dibagi rata per hari. Laba dengan HPP kosong masih bersifat sementara.</div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500"><span>Menampilkan {{ products.length }} laporan produk · Klik Detail untuk melihat sumber perhitungan</span><a href="#" class="rounded-lg border px-3 py-2 bg-white font-bold" @click.prevent="apply">Perbarui data tersimpan</a></div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="overflow-auto max-h-[720px]" tabindex="0" aria-label="Tabel performa iklan produk, dapat digulir horizontal">
                    <table class="w-full text-xs whitespace-nowrap jl-table">
                        <thead class="bg-slate-50 text-slate-500 text-[10px] uppercase"><tr><th class="p-3 text-left">Detail Produk</th><th v-for="[, label, , formula] in METRICS" :key="label" class="p-3 text-right" :title="formula">{{ label }} <span aria-hidden="true">ⓘ</span></th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="product in products" :key="product.id" class="hover:bg-slate-50 align-top">
                                <td class="p-3 min-w-[280px] max-w-[340px] whitespace-normal">
                                    <div class="flex items-start gap-3">
                                        <img v-if="/^https?:\/\//i.test(product.image_url ?? '')" :src="product.image_url" alt="" loading="lazy" class="w-10 h-10 object-cover rounded-lg bg-slate-100" />
                                        <span v-else class="w-10 h-10 shrink-0 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400"><JlIcon name="package" /></span>
                                        <div>
                                            <p class="font-bold">{{ product.product_name }}</p>
                                            <p class="text-[10px] text-slate-400 mt-1">ID: {{ product.marketplace_item_id }} · SKU: {{ product.sku }}</p>
                                            <p class="text-[10px] text-blue-600 mt-1">{{ product.platform }} · {{ product.store_id }}</p>
                                            <details class="mt-2"><summary class="cursor-pointer text-brand-700 font-bold">Detail laporan</summary><dl class="text-[11px] space-y-2 mt-2 text-slate-500"><div><dt>Periode</dt><dd>{{ product.period_start }} — {{ product.period_end }}</dd></div><div><dt>Waktu pada catatan sumber</dt><dd>{{ product.last_synced_at || 'Belum tersedia' }}</dd></div><div><dt>Total HPP (HPP × qty)</dt><dd>{{ money(product.total_cogs_hpp) }}</dd></div><div><dt>Komisi affiliate</dt><dd>{{ money(product.affiliate_fee) }}</dd></div><div><dt>Campaign</dt><dd>{{ product.campaign_name || '—' }}</dd></div></dl></details>
                                        </div>
                                    </div>
                                </td>
                                <td v-for="[key, label, type, formula] in METRICS" :key="key" class="p-3 text-right" :class="metricColor(product, key)" :title="formula">
                                    <span class="font-bold">{{ unavailable(product, key) ? '—' : fmt(product[key], type) }}</span>
                                    <span v-if="key === 'orders_count'" class="block text-[10px] text-slate-400 mt-1">Qty: {{ Number(product.qty_sold ?? 0) }}</span>
                                    <span v-if="key === 'unit_cogs_hpp' && Number(product[key]) <= 0" class="block text-[10px] text-amber-600 mt-1">HPP belum diisi</span>
                                    <span v-if="key === 'min_roas' && !unavailable(product, key)" class="block text-[10px] mt-1" :class="product.is_aman ? 'text-emerald-600' : 'text-rose-600'">{{ product.is_aman ? '✓ impas / untung' : 'Di bawah impas' }}</span>
                                    <span v-if="PCT[key]" class="block text-[10px] mt-1">{{ fmt(product[PCT[key]], 'percent') }}</span>
                                    <span v-if="key === 'ad_spend' && product.is_bocor" class="block text-[10px] text-rose-600 mt-1">Bocor{{ product.bocor_reason ? ` · ${product.bocor_reason}` : '' }}</span>
                                </td>
                            </tr>
                            <tr v-if="!products.length"><td colspan="14" class="p-12 text-center whitespace-normal text-slate-500">Belum ada laporan iklan produk untuk toko dan periode ini. Data membutuhkan laporan produk tersimpan atau konektor iklan resmi.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <details class="rounded-xl border border-slate-200 bg-white p-4 text-xs"><summary class="cursor-pointer font-bold">Panduan perhitungan</summary><dl class="grid sm:grid-cols-2 gap-4 mt-4"><div v-for="[key, label, , formula] in METRICS" :key="key"><dt class="font-bold">{{ label }}</dt><dd class="text-slate-500 mt-1 leading-5">{{ formula }}</dd></div></dl></details>
        </section>
    </MarketplaceShell>
</template>
