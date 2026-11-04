<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DataTable, { type Column } from '../../Components/DataTable.vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import KpiCard from '../../Components/KpiCard.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatDate, formatText, formatValue } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    income: Row;
    withdrawals: Row;
    filters: Row;
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    can_import: boolean;
}>();

const INCOME_STATE: Record<string, { label: string; tone: 'ok' | 'warn' | 'muted' }> = {
    final: { label: 'Final', tone: 'ok' },
    estimated: { label: 'Estimasi', tone: 'warn' },
    unknown: { label: 'Belum tersedia', tone: 'muted' },
};
const PAYOUT_LABEL: Record<string, string> = {
    succeeded: 'Berhasil di marketplace', processing: 'Diproses', failed: 'Gagal', cancelled: 'Dibatalkan', unknown: 'Status belum dikenal',
};
const PAYOUT_TONE: Record<string, 'ok' | 'warn' | 'danger' | 'muted'> = { succeeded: 'ok', processing: 'warn', failed: 'danger', cancelled: 'muted', unknown: 'muted' };

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);
const tab = ref<'income' | 'payout'>(props.filters.tab === 'payout' ? 'payout' : 'income');

const filter = reactive({
    store_id: props.scope.store_id ?? '',
    platform: props.filters.platform ?? '',
    date_start: props.filters.date_start ?? '',
    date_end: props.filters.date_end ?? '',
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    payout_status: props.filters.payout_status ?? '',
    payout_date_basis: props.filters.payout_date_basis ?? 'requested',
});

function go(extra: Record<string, string | number> = {}): void {
    const query: Record<string, string | number> = { tab: tab.value, ...extra };
    Object.entries(filter).forEach(([key, value]) => {
        if (value !== '') query[key] = value;
    });
    router.get('/marketplace/income', query, { preserveState: true, replace: true });
}

// ----- income -----
const incomeColumns: Column[] = [
    { key: 'order_date', label: 'Tanggal', kind: 'date' },
    { key: 'order_id', label: 'Pesanan' },
    { key: 'store_name', label: 'Toko' },
    { key: 'state', label: 'Status' },
    { key: 'gross_amount', label: 'Nilai', kind: 'currency', align: 'right' },
    { key: 'total_fees', label: 'Biaya', kind: 'currency', align: 'right' },
    { key: 'net_income', label: 'Pemasukan bersih', kind: 'currency', align: 'right' },
];
const incomeSummary = computed(() => props.income.summary ?? {});

// ----- withdrawals -----
const payoutColumns: Column[] = [
    { key: 'external_id', label: 'ID / toko' },
    { key: 'requested_at', label: 'Pengajuan', kind: 'date' },
    { key: 'completed_at', label: 'Selesai', kind: 'date' },
    { key: 'amount', label: 'Nominal', kind: 'currency', align: 'right' },
    { key: 'fee', label: 'Biaya', kind: 'currency', align: 'right' },
    { key: 'net_amount', label: 'Bersih dikirim', kind: 'currency', align: 'right' },
    { key: 'normalized_status', label: 'Status' },
    { key: 'destination', label: 'Tujuan' },
];
const payoutSummary = computed(() => props.withdrawals.summary ?? {});

// ----- import -----
const importer = reactive({ storeId: props.scope.store_id ?? '', file: null as File | null, key: '', count: 0, sample: [] as Row[], meta: {} as Row });
const importError = ref<string | null>(null);

function onFile(event: Event): void {
    importer.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    importer.key = '';
    importError.value = null;
}

async function preview(): Promise<void> {
    if (!importer.file || !importer.storeId) {
        importError.value = 'Pilih toko dan berkas laporan terlebih dahulu.';

        return;
    }
    busy.value = true;
    importError.value = null;
    try {
        const body = new FormData();
        body.set('store_id', importer.storeId);
        body.set('report', importer.file);
        const result = await apiRequest('/pilot/marketplace/withdrawals/preview', body);
        const data = result.data ?? {};
        Object.assign(importer, { key: data.key ?? '', count: data.count ?? 0, sample: data.rows ?? [], meta: data.meta ?? {} });
    } catch (error) {
        importError.value = error instanceof ApiError ? error.message : 'Pratinjau belum berhasil.';
    } finally {
        busy.value = false;
    }
}

async function confirmImport(): Promise<void> {
    busy.value = true;
    importError.value = null;
    try {
        const result = await apiRequest('/api/marketplace-withdrawals.php', { action: 'confirm', key: importer.key });
        const counts = result.data ?? {};
        flash.message = `Impor selesai: ${counts.inserted ?? 0} baru, ${counts.updated ?? 0} diperbarui, ${counts.unchanged ?? 0} tidak berubah.`;
        flash.kind = 'success';
        Object.assign(importer, { file: null, key: '', count: 0, sample: [] });
        router.reload({ only: ['withdrawals'] });
    } catch (error) {
        importError.value = error instanceof ApiError ? error.message : 'Impor belum berhasil. Tidak ada perubahan yang disimpan.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <MarketplaceShell
        active="income"
        title="Pemasukan dan penarikan"
        eyebrow="Marketplace / Keuangan"
        subtitle="Pemasukan bersih per pesanan dan riwayat penarikan dana dari laporan resmi yang diimpor."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <form class="panel filter-bar" @submit.prevent="go()">
            <div class="field filter-store">
                <label for="n-store">Toko</label>
                <select id="n-store" v-model="filter.store_id"><option value="">Semua toko</option><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
            <div class="field"><label for="n-platform">Platform</label><select id="n-platform" v-model="filter.platform"><option value="">Semua</option><option>Shopee</option><option>TikTok Shop</option></select></div>
            <div class="field"><label for="n-start">Dari</label><input id="n-start" v-model="filter.date_start" type="date" /></div>
            <div class="field"><label for="n-end">Sampai</label><input id="n-end" v-model="filter.date_end" type="date" /></div>
            <div class="field"><label for="n-search">Cari</label><input id="n-search" v-model="filter.search" type="search" maxlength="200" :placeholder="tab === 'income' ? 'Kode pesanan atau batch' : 'ID penarikan'" /></div>
            <button class="button button-primary" type="submit">Terapkan</button>
        </form>

        <div class="tab-bar" role="tablist">
            <button role="tab" type="button" :aria-selected="tab === 'income'" :class="{ active: tab === 'income' }" @click="tab = 'income'">Pemasukan pesanan</button>
            <button role="tab" type="button" :aria-selected="tab === 'payout'" :class="{ active: tab === 'payout' }" @click="tab = 'payout'">Riwayat penarikan</button>
        </div>

        <template v-if="tab === 'income'">
            <section class="panel notice"><p class="muted">Pemasukan final hanya untuk pesanan yang sudah ada data penyelesaian dari marketplace. Selain itu angkanya estimasi, dan pesanan tanpa data keuangan ditandai belum tersedia, bukan dihitung nol.</p></section>
            <section class="kpi-grid" aria-label="Ringkasan pemasukan">
                <KpiCard label="Pemasukan final" :value="incomeSummary.final" />
                <KpiCard label="Pemasukan estimasi" :value="incomeSummary.estimated" />
                <KpiCard label="Pesanan belum ada data" :value="incomeSummary.unknown" kind="count" />
                <KpiCard label="Jumlah pesanan" :value="incomeSummary.count" kind="count" />
            </section>
            <section class="panel">
                <div class="field inline-field"><label for="n-state">Tampilkan</label><select id="n-state" v-model="filter.status" @change="go()"><option value="">Semua status</option><option value="final">Final</option><option value="estimated">Estimasi</option><option value="unknown">Belum tersedia</option></select></div>
                <DataTable :columns="incomeColumns" :rows="income.rows" row-key="order_id" empty="Tidak ada pesanan pada filter ini.">
                    <template #cell-state="{ row }"><StatusBadge :label="INCOME_STATE[row.state]?.label ?? row.state" :tone="INCOME_STATE[row.state]?.tone ?? 'muted'" /></template>
                    <template #cell-store_name="{ row }">{{ formatText(row.store_name) }}<div class="muted cell-note">{{ formatText(row.platform) }}</div></template>
                </DataTable>
                <div class="form-actions page-controls">
                    <span class="muted">Halaman {{ income.page }} dari {{ income.pages }} · {{ formatValue(income.total, 'count') }} pesanan</span>
                    <button class="button button-secondary" type="button" :disabled="income.page <= 1" @click="go({ page: income.page - 1 })">Sebelumnya</button>
                    <button class="button button-secondary" type="button" :disabled="income.page >= income.pages" @click="go({ page: income.page + 1 })">Berikutnya</button>
                </div>
            </section>
        </template>

        <template v-else>
            <section class="panel notice"><p class="muted">Sumber riwayat adalah laporan penarikan resmi yang Anda impor. Status berhasil pada laporan belum membuktikan dana masuk ke bank. Penarikan tidak dicatat sebagai pendapatan atau jurnal baru.</p></section>
            <section class="kpi-grid" aria-label="Ringkasan penarikan">
                <KpiCard label="Penarikan dalam filter" :value="payoutSummary.total" kind="count" />
                <KpiCard label="Sedang diproses" :value="payoutSummary.processing" kind="count" />
                <KpiCard label="Penarikan gagal" :value="payoutSummary.failed" kind="count" />
                <KpiCard label="Berhasil · nominal IDR" :value="payoutSummary.paid_nominal" />
            </section>

            <section v-if="can_import" class="panel">
                <h2>Impor laporan penarikan</h2>
                <p class="muted">XLSX income asli TikTok Shop (sheet dan kolom tidak diubah), atau CSV sesuai <a href="/api/marketplace-withdrawals.php?action=template">template</a>. Pratinjau dulu, baru konfirmasi.</p>
                <div class="form-grid">
                    <div class="field"><label for="i-store">Toko</label><select id="i-store" v-model="importer.storeId"><option value="" disabled>Pilih toko</option><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select></div>
                    <div class="field field-wide"><label for="i-file">Berkas laporan (maks 10 MB)</label><input id="i-file" type="file" accept=".xlsx,.csv" @change="onFile" /></div>
                </div>
                <p v-if="importError" class="inline-error" role="alert">{{ importError }}</p>
                <div class="form-actions"><button class="button button-secondary" type="button" :disabled="busy || !importer.file" @click="preview">Pratinjau</button></div>

                <div v-if="importer.key" class="form-stack">
                    <p><strong>{{ importer.count }} baris</strong> siap diimpor. Contoh {{ importer.sample.length }} baris pertama:</p>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>ID</th><th>Pengajuan</th><th class="num">Nominal</th><th>Mata uang</th><th>Status</th></tr></thead>
                            <tbody><tr v-for="row in importer.sample" :key="row.external_id"><td>{{ row.external_id }}</td><td>{{ formatDate(row.requested_at) }}</td><td class="num">{{ row.amount }}</td><td>{{ row.currency }}</td><td>{{ row.source_status }}</td></tr></tbody>
                        </table>
                    </div>
                    <div class="form-actions"><button class="button button-primary" type="button" :disabled="busy" @click="confirmImport">Konfirmasi impor {{ importer.count }} baris</button></div>
                </div>
            </section>

            <section class="panel">
                <div class="filter-bar">
                    <div class="field"><label for="p-status">Status</label><select id="p-status" v-model="filter.payout_status" @change="go()"><option value="">Semua</option><option v-for="(label, key) in PAYOUT_LABEL" :key="key" :value="key">{{ label }}</option></select></div>
                    <div class="field"><label for="p-basis">Tanggal berdasarkan</label><select id="p-basis" v-model="filter.payout_date_basis" @change="go()"><option value="requested">Pengajuan</option><option value="completed">Selesai</option></select></div>
                </div>
                <DataTable :columns="payoutColumns" :rows="withdrawals.rows" row-key="id" empty="Belum ada penarikan yang diimpor pada filter ini.">
                    <template #cell-external_id="{ row }">{{ row.external_id }}<div class="muted cell-note">{{ formatText(row.store_name) }} · {{ formatText(row.platform) }}</div></template>
                    <template #cell-normalized_status="{ row }"><StatusBadge :label="PAYOUT_LABEL[row.normalized_status] ?? row.source_status" :tone="PAYOUT_TONE[row.normalized_status] ?? 'muted'" /><div v-if="row.failure_reason" class="cell-note warn-text">{{ row.failure_reason }}</div></template>
                    <template #cell-destination="{ row }">{{ formatText(row.bank_name) }}<div class="muted cell-note">{{ formatText(row.account_mask) }}</div></template>
                </DataTable>
                <div class="form-actions page-controls">
                    <span class="muted">Halaman {{ withdrawals.page }} dari {{ withdrawals.pages }}</span>
                    <button class="button button-secondary" type="button" :disabled="withdrawals.page <= 1" @click="go({ payout_page: withdrawals.page - 1 })">Sebelumnya</button>
                    <button class="button button-secondary" type="button" :disabled="withdrawals.page >= withdrawals.pages" @click="go({ payout_page: withdrawals.page + 1 })">Berikutnya</button>
                </div>
            </section>
        </template>
    </MarketplaceShell>
</template>
