<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DataTable, { type Column } from '../../Components/DataTable.vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import KpiCard from '../../Components/KpiCard.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatText, formatValue } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    marketing: Row;
    filters: Row;
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    can_import: boolean;
}>();

const PILLS: Array<{ key: string; label: string }> = [
    { key: '', label: 'Semua' },
    { key: 'bocor', label: 'Produk bocor' },
    { key: 'rugi', label: 'Rugi' },
    { key: 'roas_rendah', label: 'ROAS rendah' },
    { key: 'tanpa_hpp', label: 'Tanpa HPP' },
];

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
const ratio = (value: unknown) => (value === null || value === undefined ? formatValue(null) : `${Number(value).toFixed(2)}x`);

const columns: Column[] = [
    { key: 'product_name', label: 'Produk / kampanye' },
    { key: 'period', label: 'Periode' },
    { key: 'ad_spend', label: 'Biaya iklan', kind: 'currency', align: 'right' },
    { key: 'gross_revenue', label: 'Pendapatan', kind: 'currency', align: 'right' },
    { key: 'roas', label: 'ROAS kotor / aktual', align: 'right' },
    { key: 'orders_count', label: 'Pesanan', kind: 'count', align: 'right' },
    { key: 'net_profit', label: 'Laba setelah iklan', kind: 'currency', align: 'right' },
    { key: 'health', label: 'Status' },
];

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
    <MarketplaceShell
        active="marketing"
        title="Iklan"
        eyebrow="Marketplace / Iklan"
        subtitle="Performa iklan produk dari laporan yang Anda impor."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <section class="panel notice">
            <p class="muted">
                Semua angka di halaman ini berasal dari laporan CSV iklan yang diimpor. Sinkronisasi otomatis dari Shopee Ads atau TikTok Ads belum aktif, jadi tidak ada angka yang diambil dari provider.
                Penanda "bocor" adalah peringatan dari data laporan itu: biaya besar tanpa pesanan, atau ROAS di bawah titik impas.
            </p>
        </section>

        <form class="panel filter-bar" @submit.prevent="apply">
            <div class="field filter-store">
                <label for="m-store">Toko</label>
                <select id="m-store" v-model="filter.store_id"><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
            <div class="field"><label for="m-platform">Platform</label><select id="m-platform" v-model="filter.platform"><option value="">Semua</option><option>Shopee</option><option>TikTok Shop</option></select></div>
            <div class="field"><label for="m-start">Dari</label><input id="m-start" v-model="filter.date_start" type="date" /></div>
            <div class="field"><label for="m-end">Sampai</label><input id="m-end" v-model="filter.date_end" type="date" /></div>
            <div class="field"><label for="m-search">Cari</label><input id="m-search" v-model="filter.search" type="search" maxlength="200" placeholder="Produk, SKU, kampanye" /></div>
            <div class="field checkbox-row"><label><input v-model="filter.include_ppn" type="checkbox" true-value="1" false-value="" /> Tambah PPN 11% pada biaya iklan</label></div>
            <button class="button button-primary" type="submit">Terapkan</button>
        </form>

        <div class="tab-bar" aria-label="Filter cepat">
            <button v-for="pill in PILLS" :key="pill.key" type="button" :class="{ active: filter.filter === pill.key }" @click="pick(pill.key)">{{ pill.label }}</button>
        </div>

        <section class="kpi-grid" aria-label="Ringkasan iklan">
            <KpiCard label="Biaya iklan" :value="kpi.biaya_iklan" />
            <KpiCard label="Pendapatan iklan" :value="kpi.pendapatan" />
            <KpiCard label="ROAS" :value="kpi.roas" :text="Number(kpi.biaya_iklan) > 0 ? ratio(kpi.roas) : undefined" />
            <KpiCard label="Biaya per pesanan" :value="kpi.cost_per_order" />
            <KpiCard label="Pesanan" :value="kpi.total_orders" kind="count" />
            <KpiCard label="Produk bocor" :value="kpi.bocor_count" kind="count" />
        </section>

        <section v-if="can_import" class="panel">
            <h2>Impor laporan iklan produk</h2>
            <p class="muted">Gunakan <a href="/marketplace/marketing/template">template CSV</a>. Angka tanpa Rp atau pemisah ribuan, desimal dengan titik, tanggal YYYY-MM-DD. Mengimpor periode yang sama memperbarui data, tidak menggandakan biaya.</p>
            <div class="form-grid">
                <div class="field field-wide"><label for="m-file">Berkas CSV (maks 2 MB)</label><input id="m-file" type="file" accept=".csv,text/csv" @change="onFile" /></div>
            </div>
            <p v-if="importError" class="inline-error" role="alert">{{ importError }}</p>
            <div class="form-actions"><button class="button button-primary" type="button" :disabled="busy || !file" @click="importReport">{{ busy ? 'Mengimpor…' : 'Impor laporan' }}</button></div>
        </section>

        <section class="panel">
            <DataTable :columns="columns" :rows="products" row-key="id" empty="Belum ada data iklan. Impor laporan CSV untuk mulai.">
                <template #cell-product_name="{ row }">
                    <strong>{{ row.product_name }}</strong>
                    <div class="muted cell-note">{{ formatText(row.sku) }} · {{ formatText(row.platform) }}<span v-if="row.campaign_name"> · {{ row.campaign_name }}</span></div>
                </template>
                <template #cell-period="{ row }">{{ row.period_start }} s/d {{ row.period_end }}</template>
                <template #cell-roas="{ row }">{{ ratio(row.gross_roas) }} / {{ ratio(row.actual_roas) }}<div class="muted cell-note">Titik impas {{ ratio(row.min_roas) }}</div></template>
                <template #cell-health="{ row }">
                    <StatusBadge v-if="row.is_bocor" label="Bocor" tone="danger" />
                    <StatusBadge v-else-if="row.is_aman" label="Aman" tone="ok" />
                    <StatusBadge v-else label="Perlu dicek" tone="warn" />
                    <div v-if="row.is_bocor && row.bocor_reason" class="cell-note warn-text">{{ row.bocor_reason }}</div>
                    <div v-if="!Number(row.unit_cogs_hpp)" class="cell-note muted">HPP belum diisi</div>
                </template>
            </DataTable>
        </section>
    </MarketplaceShell>
</template>
