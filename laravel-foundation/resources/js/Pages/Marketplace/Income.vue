<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatValue } from '../../lib/format';
import { ROUTES } from '../../lib/routes';

type Row = Record<string, any>;

const props = defineProps<{
    income: Row;
    withdrawals: Row;
    filters: Row;
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    can_import: boolean;
}>();

const INCOME_STATE: Record<string, string> = { final: 'Final tercatat', estimated: 'Estimasi', unknown: 'Belum tersedia' };
const PAYOUT_LABEL: Record<string, string> = {
    succeeded: 'Berhasil di marketplace', processing: 'Diproses', failed: 'Gagal / ditolak', cancelled: 'Dibatalkan', unknown: 'Status belum dipetakan',
};
type Tab = 'pending' | 'settled' | 'payout' | 'bank';
const TABS: Array<[Tab, string]> = [['pending', 'Untuk Dibayar'], ['settled', 'Penyelesaian Pembayaran'], ['payout', 'Penarikan Marketplace'], ['bank', 'Rekonsiliasi Bank']];
const money = (value: unknown) => formatValue(value, 'currency');
const count = (value: unknown) => formatValue(value, 'count');

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);
const tab = ref<Tab>(props.filters.tab === 'payout' ? 'payout' : props.filters.status === 'final' ? 'settled' : 'pending');

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
    const query: Record<string, string | number> = { tab: tab.value === 'payout' ? 'payout' : 'income', ...extra };
    Object.entries(filter).forEach(([key, value]) => {
        if (value !== '') query[key] = value;
    });
    router.get(ROUTES.income, query, { preserveState: true, replace: true });
}

function setTab(next: Tab): void {
    tab.value = next;
    if (next === 'pending') filter.status = 'estimated';
    if (next === 'settled') filter.status = 'final';
    if (next === 'bank') return;
    go();
}
function reset(): void {
    Object.assign(filter, { platform: '', date_start: '', date_end: '', search: '', status: tab.value === 'settled' ? 'final' : tab.value === 'pending' ? 'estimated' : '' });
    go();
}

// ----- income -----
const incomeSummary = computed(() => props.income.summary ?? {});

// ----- withdrawals -----
const payoutSummary = computed(() => props.withdrawals.summary ?? {});
const cards = computed<Array<[string, unknown, string]>>(() => [
    ['Estimasi pendapatan belum cair', incomeSummary.value.estimated, 'Dari keuangan estimasi API, belum final.'],
    ['Pendapatan final tercatat', incomeSummary.value.final, 'Penyelesaian pesanan; belum membuktikan pencairan.'],
    ['Nominal penarikan berhasil · laporan', payoutSummary.value.paid_nominal, 'Total penarikan IDR; belum verifikasi masuk bank.'],
    ['Terverifikasi masuk bank', null, 'Menunggu pencocokan mutasi rekening.'],
]);
const payoutCards = computed<Array<[string, string]>>(() => [
    ['Penarikan dalam filter', count(payoutSummary.value.total ?? 0)],
    ['Sedang diproses', count(payoutSummary.value.processing ?? 0)],
    ['Penarikan gagal', count(payoutSummary.value.failed ?? 0)],
    ['Berhasil · nominal IDR', money(payoutSummary.value.paid_nominal)],
]);

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
        const result = await apiRequest(ROUTES.withdrawalsPreview, body);
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
        const result = await apiRequest(ROUTES.api.withdrawals, { action: 'confirm', key: importer.key });
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
    <MarketplaceShell active="income" title="Pemasukan" :store-id="scope.store_id" :company-key="scope.company_key">
        <section class="space-y-5" aria-labelledby="income-title">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs text-emerald-700 font-bold mb-2">KEUANGAN MARKETPLACE</p><h1 id="income-title" class="text-2xl font-extrabold text-slate-900">Pemasukan</h1><p class="text-sm text-slate-500 mt-2">Pantau pendapatan pesanan, pencairan marketplace, dan kecocokan dana masuk bank.</p></div>
                <a href="#" class="rounded-xl border bg-white px-4 py-2 text-xs font-bold" @click.prevent="go()">↻ Muat ulang</a>
            </header>
            <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

            <form data-income-filters class="rounded-2xl border border-slate-200 bg-white p-4 flex flex-wrap gap-3 items-end" @submit.prevent="go()">
                <label class="text-xs text-slate-500">Platform<select v-model="filter.platform" class="block border rounded-lg p-2 mt-1"><option value="">Semua platform</option><option>TikTok Shop</option><option>Shopee</option></select></label>
                <label class="text-xs text-slate-500">{{ tab === 'payout' ? 'Tanggal penarikan dari' : 'Tanggal pesanan dari' }}<input v-model="filter.date_start" type="date" class="block border rounded-lg p-2 mt-1" /></label>
                <label class="text-xs text-slate-500">Sampai<input v-model="filter.date_end" type="date" class="block border rounded-lg p-2 mt-1" /></label>
                <label v-if="tab === 'pending' || tab === 'settled'" class="text-xs text-slate-500">Data keuangan<select v-model="filter.status" class="block border rounded-lg p-2 mt-1"><option value="">Semua</option><option value="estimated">Estimasi</option><option value="final">Final tercatat</option><option value="unknown">Belum tersedia</option></select></label>
                <label class="text-xs text-slate-500 flex-1">Pesanan / ID pencairan<input v-model="filter.search" maxlength="200" class="block w-full border rounded-lg p-2 mt-1" placeholder="Cari nomor referensi…" /></label>
                <div data-income-filter-actions class="flex items-center gap-3"><button class="rounded-lg bg-emerald-600 text-white px-4 py-2 text-xs font-bold">Terapkan</button><a class="text-xs p-2" href="#" @click.prevent="reset">Reset</a></div>
            </form>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <article v-for="[label, value, hint] in cards" :key="label" class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">{{ label }}</p><p class="text-xl font-extrabold text-emerald-700 mt-3">{{ money(value) }}</p><p class="text-xs text-slate-400 mt-2">{{ hint }}</p></article>
            </div>

            <nav aria-label="Tahapan pemasukan" class="flex flex-wrap gap-2">
                <a v-for="[key, label] in TABS" :key="key" href="#" :aria-current="tab === key ? 'page' : 'false'" class="px-4 py-3 text-xs font-bold rounded-xl border" :class="tab === key ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600'" @click.prevent="setTab(key)">{{ label }}</a>
            </nav>

            <template v-if="tab === 'pending' || tab === 'settled'">
                <div class="rounded-xl border p-4 text-xs" :class="tab === 'settled' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-blue-200 bg-blue-50 text-blue-900'">{{ tab === 'settled' ? 'Nominal penyelesaian pembayaran final yang dicatat marketplace. Telah selesai dan memiliki bukti pernyataan resmi (statement).' : `Data dari pesanan API toko. Estimasi dapat berubah. Pendapatan final tidak otomatis berarti dana masuk bank. HPP mengurangi laba, bukan nominal pencairan. ${incomeSummary.unknown ?? 0} pesanan masih menunggu data keuangan.` }}</div>
                <div class="bg-white border rounded-2xl overflow-hidden">
                    <div class="p-4 border-b"><h2 class="font-bold">{{ tab === 'settled' ? 'Penyelesaian Pembayaran Final' : 'Pendapatan Pesanan (Untuk Dibayar)' }}</h2><p class="text-xs text-slate-500 mt-1">{{ count(income.total) }} pesanan · maksimum 50 per halaman · tanggal menggunakan tanggal pesanan</p></div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left whitespace-nowrap">
                            <thead class="bg-slate-50 text-slate-500"><tr><th v-for="h in ['Pesanan / toko', 'Tanggal', 'Data keuangan', 'Penjualan', 'Potongan', 'Untuk Dibayar', 'Referensi payout']" :key="h" class="p-3">{{ h }}</th></tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="row in income.rows" :key="row.order_id">
                                    <td class="p-3"><span class="font-bold text-blue-700">{{ row.order_id }}</span><small class="block text-slate-500 mt-1">{{ row.store_name }} · {{ row.platform }}</small></td>
                                    <td class="p-3">{{ String(row.order_date ?? '').slice(0, 10) }}</td>
                                    <td class="p-3"><span class="rounded-full bg-slate-100 px-2 py-1">{{ INCOME_STATE[row.state] ?? row.state }}</span></td>
                                    <td class="p-3">{{ money(row.gross_amount) }}</td>
                                    <td class="p-3">{{ money(row.total_fees) }}</td>
                                    <td class="p-3">{{ money(row.net_income) }}</td>
                                    <td class="p-3 text-slate-500">{{ row.payout_batch_id || 'Belum tersedia' }}</td>
                                </tr>
                                <tr v-if="!income.rows.length"><td colspan="7" class="p-10 text-center text-slate-500">Belum ada pesanan API yang sesuai filter. Sinkronkan toko atau ubah periode.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <footer class="p-4 border-t flex justify-between text-xs"><span>Halaman {{ income.page }} / {{ income.pages }}</span><div class="flex gap-3"><a v-if="income.page > 1" href="#" @click.prevent="go({ page: income.page - 1 })">Sebelumnya</a><a v-if="income.page < income.pages" href="#" @click.prevent="go({ page: income.page + 1 })">Berikutnya</a></div></footer>
                </div>
            </template>

            <template v-else-if="tab === 'payout'">
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-900 leading-6">Sumber riwayat: laporan penarikan resmi yang Anda impor. API Payments TikTok belum tersedia untuk pasar SEA. Status berhasil pada laporan belum membuktikan dana masuk bank. Penarikan tidak dicatat sebagai pendapatan atau jurnal baru.</div>
                <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    <div v-for="[label, value] in payoutCards" :key="label" class="bg-white border rounded-2xl p-5"><p class="text-xs text-slate-500">{{ label }}</p><p class="font-extrabold text-xl mt-2">{{ value }}</p></div>
                </div>
                <form class="bg-white border rounded-xl p-4 flex flex-wrap gap-3 items-end" @submit.prevent="go()">
                    <label class="text-xs">Status penarikan<select v-model="filter.payout_status" class="block border rounded-lg p-2 mt-1"><option value="">Semua status</option><option v-for="(label, key) in PAYOUT_LABEL" :key="key" :value="key">{{ label }}</option></select></label>
                    <label class="text-xs">Dasar tanggal<select v-model="filter.payout_date_basis" class="block border rounded-lg p-2 mt-1"><option value="requested">Tanggal pengajuan</option><option value="completed">Tanggal selesai</option></select></label>
                    <button class="bg-emerald-600 text-white text-xs font-bold p-2 rounded-lg">Terapkan</button>
                </form>

                <details v-if="can_import" class="bg-white border rounded-2xl p-5">
                    <summary class="font-bold cursor-pointer">Impor laporan penarikan (XLSX toko)</summary>
                    <p class="text-xs text-slate-500 mt-3 leading-6">Unggah file income XLSX asli TikTok Shop tanpa mengubah sheet atau kolom. Sistem membaca hanya baris Withdrawal di sheet Riwayat penarikan; Earnings dan GMV Pay Deduction dikecualikan. Alternatif CSV tetap tersedia dengan <a class="text-blue-700 underline" href="/api/marketplace-withdrawals.php?action=template">template CSV</a>. Rekening disimpan tersamarkan.</p>
                    <form class="flex flex-wrap gap-3 items-end mt-4" @submit.prevent="preview">
                        <label class="text-xs">Toko sumber<select v-model="importer.storeId" required class="block border rounded-lg p-2 mt-1"><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name }} · {{ store.platform }}</option></select></label>
                        <input type="file" accept=".xlsx,.csv" required class="text-xs" @change="onFile" />
                        <button class="rounded-lg bg-emerald-600 text-white px-4 py-2 text-xs font-bold" :disabled="busy || !importer.file">Pratinjau impor</button>
                    </form>
                    <p v-if="importError" class="text-xs text-amber-800 mt-3" role="alert">{{ importError }}</p>
                    <div v-if="importer.key" class="mt-4 text-xs space-y-3">
                        <p><strong>{{ importer.count }} baris</strong> siap diimpor. Contoh {{ importer.sample.length }} baris pertama:</p>
                        <div class="overflow-auto"><table class="w-full text-xs text-left"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-2">ID</th><th class="p-2">Pengajuan</th><th class="p-2">Nominal</th><th class="p-2">Mata uang</th><th class="p-2">Status</th></tr></thead><tbody class="divide-y"><tr v-for="row in importer.sample" :key="row.external_id"><td class="p-2">{{ row.external_id }}</td><td class="p-2">{{ row.requested_at }}</td><td class="p-2">{{ row.amount }}</td><td class="p-2">{{ row.currency }}</td><td class="p-2">{{ row.source_status }}</td></tr></tbody></table></div>
                        <button class="bg-emerald-600 text-white px-4 py-2 rounded-lg font-bold" type="button" :disabled="busy" @click="confirmImport">Konfirmasi impor {{ importer.count }} baris</button>
                    </div>
                </details>

                <div class="bg-white border rounded-2xl overflow-hidden">
                    <div class="p-5 border-b"><h2 class="font-bold">Riwayat penarikan dana</h2><p class="text-xs text-slate-500 mt-1">Tidak menghubungkan pesanan secara perkiraan.</p></div>
                    <div class="overflow-auto">
                        <table class="w-full text-xs text-left whitespace-nowrap">
                            <thead class="bg-slate-50 text-slate-500"><tr><th v-for="h in ['ID / toko', 'Pengajuan', 'Selesai', 'Nominal', 'Biaya', 'Bersih dikirim', 'Status', 'Tujuan', 'Sumber']" :key="h" class="p-3">{{ h }}</th></tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="w in withdrawals.rows" :key="w.id ?? w.external_id">
                                    <td class="p-3 font-bold">{{ w.external_id }}<small class="block font-normal text-slate-500 mt-1">{{ w.store_name }} · {{ w.platform }}</small></td>
                                    <td class="p-3">{{ w.requested_at }} WIB</td>
                                    <td class="p-3">{{ w.completed_at ? `${w.completed_at} WIB` : 'Belum tersedia' }}</td>
                                    <td v-for="k in ['amount', 'fee', 'net_amount']" :key="k" class="p-3">{{ w[k] === null || w[k] === undefined ? 'Belum tersedia' : `${w.currency} ${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(w[k]))}` }}</td>
                                    <td class="p-3"><span class="rounded-full px-2 py-1" :class="w.normalized_status === 'succeeded' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">{{ PAYOUT_LABEL[w.normalized_status] ?? w.source_status }}</span></td>
                                    <td class="p-3">{{ w.bank_name ?? '' }} {{ w.account_mask ?? 'Belum tersedia' }}</td>
                                    <td class="p-3">Impor Laporan</td>
                                </tr>
                                <tr v-if="!withdrawals.rows.length"><td colspan="9" class="p-10 text-center text-slate-500">Belum ada penarikan yang diimpor pada filter ini.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <footer class="p-4 border-t flex justify-between text-xs"><span>Halaman {{ withdrawals.page }} / {{ withdrawals.pages }}</span><div class="flex gap-3"><a v-if="withdrawals.page > 1" href="#" @click.prevent="go({ payout_page: withdrawals.page - 1 })">Sebelumnya</a><a v-if="withdrawals.page < withdrawals.pages" href="#" @click.prevent="go({ payout_page: withdrawals.page + 1 })">Berikutnya</a></div></footer>
                </div>
            </template>

            <article v-else class="rounded-2xl border bg-white p-6">
                <h2 class="font-bold text-lg">Rekonsiliasi Bank Marketplace</h2>
                <p class="text-sm text-slate-500 mt-3 leading-6">Pencocokan memerlukan data penarikan resmi dan mutasi bank yang memiliki nominal, tanggal, dan nomor referensi unik. Status penarikan berhasil dari marketplace belum otomatis membuktikan dana telah masuk dan terverifikasi di rekening bank.</p>
                <div class="mt-4 p-4 rounded-xl bg-slate-50 border text-xs text-slate-600 space-y-2"><p><b>Prinsip Pencocokan Rekonsiliasi:</b></p><ul class="list-disc list-inside space-y-1"><li>Penarikan dana tidak dicatat ulang sebagai pendapatan penjualan.</li><li>HPP mengurangi laba perusahaan, bukan mengurangi nilai transfer marketplace ke bank.</li><li>Setiap selisih biaya kliring atau selisih nominal harus dicatat sebagai beban administrasi bank.</li></ul></div>
                <p class="text-xs text-amber-700 mt-4">Status: menunggu sumber data mutasi bank. Tidak ada pencatatan pemasukan atau jurnal baru dari halaman ini.</p>
            </article>
        </section>
    </MarketplaceShell>
</template>
