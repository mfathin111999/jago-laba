<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import JlIcon from '../../Components/JlIcon.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import { formatValue } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    read_only: boolean;
    workspace: string;
    scope: { company_key: string; store_id: string | null };
    stores: Row[];
    active_store?: Row;
    filters: Record<string, string>;
    dashboard: Row | null;
    order_summary?: { counts: Record<string, number> | null; return_available: boolean } | null;
    query: Record<string, any>;
    message?: string;
}>();

const money = (value: unknown) => formatValue(value, 'currency');
const count = (value: unknown) => formatValue(value, 'count');

const period = computed(() => props.dashboard?.period ?? { preset: 'month', start: '', end: '' });
const groups = computed(() => props.dashboard?.groups ?? {});
const attention = computed(() => props.dashboard?.attention ?? {});
const costs = computed(() => props.dashboard?.costs ?? {});
const daily = computed(() => (props.dashboard?.daily ?? []) as Row[]);
const rows = computed(() => (props.dashboard?.rows ?? []) as Row[]);
const mode = computed(() => (props.filters.finance_mode === 'estimated' ? 'estimated' : 'final'));
const flowGroup = computed(() => groups.value[mode.value] ?? {});
const storeId = computed(() => props.scope.store_id ?? '');

const page = ref(1);
const pageRows = computed(() => rows.value.slice((page.value - 1) * 50, page.value * 50));

const percent = (value: unknown) => {
    const sales = flowGroup.value.sales;

    return value !== null && value !== undefined && sales !== null && sales !== undefined && Number(sales) > 0 ? `${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format((Number(value) / Number(sales)) * 100)}%` : '—';
};

const periodOptions = [
    ['today', 'Hari ini'],
    ['yesterday', 'Kemarin'],
    ['week', '7 hari terakhir'],
    ['month', 'Bulan ini'],
    ['last_month', 'Bulan lalu'],
    ['custom', 'Rentang khusus'],
];
const orderCards: Array<[string, string]> = [['new', 'Pesanan Baru'], ['pickup', 'Menunggu Pickup'], ['shipped', 'Dikirim'], ['cancelled', 'Dibatalkan'], ['returns', 'Pengembalian Barang']];

const link = (path: string, extra: Record<string, string> = {}) => `${path}?${new URLSearchParams({ store: storeId.value, store_id: storeId.value, ...extra }).toString()}`;
const modeLink = (key: string) => link('/pilot/marketplace', { period: period.value.preset, date_start: period.value.start, date_end: period.value.end, finance_mode: key });
const exportLink = computed(() => link('/pilot/marketplace/export', { period: period.value.preset, date_start: period.value.start, date_end: period.value.end, finance_mode: mode.value }));
const orderLink = (tab: string) => link('/pilot/marketplace/orders', { period: 'custom', date_start: period.value.start, date_end: period.value.end, order_tab: tab });

function applyFilters(event: Event) {
    const values = new FormData(event.target as HTMLFormElement);
    router.get('/pilot/marketplace', {
        store: storeId.value,
        period: String(values.get('period') ?? 'month'),
        date_start: String(values.get('date_start') ?? ''),
        date_end: String(values.get('date_end') ?? ''),
        finance_mode: mode.value,
    }, { preserveScroll: true });
}

const formula = ref<HTMLDialogElement | null>(null);
const maxSales = computed(() => Math.max(1, ...daily.value.map((day) => Number(day.sales ?? 0))));
const barHeight = (day: Row) => Math.max(2, Math.round((Number(day.sales ?? 0) / maxSales.value) * 125));
const dayLabel = (date: unknown) => String(date ?? '').slice(8, 10);
const stateLabel: Record<string, string> = { final: 'Final', estimated: 'Estimasi', unknown: 'Belum tersedia' };
const lastSync = computed(() => props.active_store?.last_successful_sync_at ?? 'Belum tersedia');

const comparison = computed(() => [
    { key: 'final', icon: 'receipt', title: 'Keuangan final dari marketplace', hint: 'Final keuangan pesanan; belum berarti dana masuk bank.' },
    { key: 'estimated', icon: 'refresh', title: 'Estimasi pesanan berjalan', hint: 'Nilai dapat berubah mengikuti pembaruan marketplace.' },
].map((item) => ({ ...item, summary: groups.value[item.key] ?? {} })));

const metrics = computed(() => [
    { icon: 'megaphone', label: 'Biaya iklan tercatat', value: costs.value.ads, hint: 'Sumber: pengeluaran toko, tanpa menambahkan tabel iklan lama.' },
    { icon: 'expense', label: 'Operasional tercatat', value: costs.value.operational, hint: 'Hanya beban laba; arus kas saja tidak disertakan.' },
    { icon: 'calculator', label: 'Laba setelah biaya tercatat', value: props.dashboard?.known_subtotal, hint: `${props.dashboard?.subtotal_orders ?? 0} dari ${props.dashboard?.eligible_orders ?? 0} pesanan lengkap. Biaya tercatat periode dikurangkan sekali.` },
    { icon: 'wallet', label: 'Laba Bersih Riil', value: null, hint: 'Belum dapat disahkan: cakupan biaya belum terverifikasi.' },
]);

const flow = computed(() => [
    ['Penjualan tercatat', flowGroup.value.sales, 'revenue', 'Nilai penjualan dari rincian keuangan marketplace.'],
    ['Potongan marketplace bersih', flowGroup.value.fees, 'deduction', 'Penjualan − Untuk Dibayar; sudah mencakup kredit/penyesuaian sumber.'],
    ['Untuk Dibayar', flowGroup.value.net, 'net', 'Nilai penyelesaian dari API marketplace.'],
    ['HPP Produk', flowGroup.value.hpp, 'deduction', 'HPP transaksi dari pengaturan produk dan tanggal berlaku.'],
    ['Laba setelah HPP', flowGroup.value.profit, 'profit', 'Untuk Dibayar − HPP Produk.'],
] as Array<[string, unknown, string, string]>);

const attentionItems = computed(() => [
    ['Keuangan belum tersedia', attention.value.missing_finance, '/pilot/marketplace/orders'],
    ['Selisih rekonsiliasi', attention.value.unreconciled, '/pilot/marketplace/orders'],
    ['HPP belum lengkap', attention.value.missing_hpp, '/marketplace/catalog'],
    ['Laba setelah HPP negatif', attention.value.loss, '/pilot/marketplace/orders'],
    ['Pengembalian tercatat', attention.value.returns, '/pilot/marketplace/orders'],
    ['Batal dengan dampak keuangan', attention.value.cancel_finance, '/pilot/marketplace/orders'],
] as Array<[string, unknown, string]>);
</script>

<template>
    <MarketplaceShell active="dashboard" title="Dashboard toko" :store-id="scope.store_id" :company-key="scope.company_key">
        <section v-if="!dashboard || !active_store" class="sd-dashboard" aria-label="Dashboard Toko">
            <header class="sd-header"><div><p class="sd-eyebrow">PERFORMA TOKO</p><h1>Dashboard Toko</h1><p>Keuangan pesanan yang dapat ditelusuri hingga sumbernya.</p></div></header>
            <div class="sd-card"><h2>Hubungkan toko Marketplace terlebih dahulu</h2><p>{{ message }}</p><p><Link class="sd-button sd-primary" href="/marketplace/stores">Pilih Toko</Link></p></div>
        </section>

        <section v-else id="store-dashboard" class="sd-dashboard" :data-store="storeId" aria-label="Dashboard Toko">
            <header class="sd-header">
                <div><p class="sd-eyebrow">PERFORMA TOKO · {{ active_store.platform }}</p><h1>{{ active_store.store_name }}</h1><p>Keuangan pesanan yang dapat ditelusuri hingga sumbernya.</p></div>
                <div class="sd-actions">
                    <Link class="sd-button sd-primary" :href="link('/marketplace/sync')"><JlIcon name="refresh" /> Sinkronisasi Data</Link>
                    <a class="sd-button" :href="exportLink"><JlIcon name="download" /> Ekspor rincian</a>
                </div>
            </header>
            <div class="sd-source">
                <span class="sd-badge" :class="active_store.is_connection_verified ? 'sd-positive' : 'sd-warning'">{{ active_store.authorization_label || 'Periksa otorisasi' }}</span>
                <span>Sinkronisasi berhasil terakhir: <strong>{{ lastSync }}</strong> WIB</span>
                <Link :href="link('/marketplace/integrations')">Detail koneksi <JlIcon name="arrow-right" /></Link>
            </div>
            <form class="sd-filters" @submit.prevent="applyFilters">
                <label>Periode<select name="period" :value="period.preset"><option v-for="[value, label] in periodOptions" :key="value" :value="value">{{ label }}</option></select></label>
                <label>Mulai<input type="date" name="date_start" :value="period.start" /></label>
                <label>Sampai<input type="date" name="date_end" :value="period.end" /></label>
                <button class="sd-button sd-primary" type="submit"><JlIcon name="filter" /> Terapkan</button>
                <span>Tanggal pesanan · WIB · IDR<br />{{ period.start }} — {{ period.end }}</span>
            </form>
            <div class="sd-notice"><JlIcon name="info" /><p><strong>Angka final dan estimasi dipisahkan.</strong> Biaya iklan dan operasional yang belum terkonfirmasi tidak dianggap nol. Laba bersih riil belum dapat disahkan sampai seluruh biaya lengkap.</p></div>

            <div v-if="order_summary?.counts" class="sd-order-summary">
                <Link v-for="[key, label] in orderCards" :key="key" :href="orderLink(key)"><span>{{ label }}</span><strong>{{ key === 'returns' && !order_summary.return_available ? 'Cakupan belum lengkap' : count(order_summary.counts[key] ?? 0) }}</strong></Link>
            </div>

            <div class="sd-comparison">
                <article v-for="item in comparison" :key="item.key" class="sd-card">
                    <div class="sd-card-heading"><span class="sd-icon"><JlIcon :name="item.icon" /></span><div><h2>{{ item.title }}</h2><p>{{ item.hint }}</p></div><span class="sd-badge">{{ count(item.summary.count ?? 0) }} pesanan</span></div>
                    <dl class="sd-summary">
                        <div><dt>Penjualan tercatat</dt><dd>{{ money(item.summary.sales) }}</dd></div>
                        <div><dt>Untuk Dibayar</dt><dd>{{ money(item.summary.net) }}</dd></div>
                        <div><dt>HPP Produk</dt><dd>{{ money(item.summary.hpp) }}</dd></div>
                        <div><dt>Laba setelah HPP</dt><dd>{{ money(item.summary.profit) }}</dd></div>
                    </dl>
                    <p class="sd-muted">HPP lengkap {{ (item.summary.count ?? 0) - (item.summary.missing_hpp ?? 0) }}/{{ item.summary.count ?? 0 }} pesanan · {{ item.summary.unreconciled ?? 0 }} perlu rekonsiliasi<template v-if="item.summary.profit !== null && item.summary.profit !== undefined && item.summary.sales > 0"> · Margin setelah HPP {{ new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format((item.summary.profit / item.summary.sales) * 100) }}%</template></p>
                </article>
            </div>

            <div class="sd-metrics">
                <article v-for="metric in metrics" :key="metric.label" class="sd-card sd-metric">
                    <span class="sd-icon"><JlIcon :name="metric.icon" /></span><h2>{{ metric.label }}</h2><strong>{{ money(metric.value) }}</strong><p>{{ metric.hint }}</p>
                    <span v-if="metric.label === 'Laba setelah biaya tercatat'" class="sd-badge sd-warning">Subtotal parsial · bukan laba final</span>
                </article>
            </div>

            <article class="sd-card">
                <div class="sd-section-heading"><div><p class="sd-eyebrow">DARI PENJUALAN KE KEUNTUNGAN</p><h2>Alur Keuntungan</h2><p>Nominal mengikuti kelompok keuangan dan periode yang sama.</p></div><button type="button" class="sd-button" @click="formula?.showModal()"><JlIcon name="info" /> Cara Perhitungan</button></div>
                <nav class="sd-tabs" aria-label="Kelompok keuangan">
                    <Link v-for="[key, label] in [['final', 'Keuangan Final'], ['estimated', 'Estimasi']]" :key="key" :class="{ 'is-active': mode === key }" :href="modeLink(key)">{{ label }} <span>{{ groups[key]?.count ?? 0 }}</span></Link>
                </nav>
                <div class="sd-flow">
                    <div v-for="([label, value, tone, hint], index) in flow" :key="label" class="sd-flow-step" :class="tone"><span class="sd-step-number">{{ index + 1 }}</span><h3>{{ label }}</h3><strong>{{ money(value) }}</strong><span>{{ percent(value) }} dari penjualan</span><p>{{ hint }}</p></div>
                </div>
                <div class="sd-flow-footer"><p><strong>Berikutnya:</strong> kurangi biaya iklan dan operasional yang tercatat. Penyesuaian yang sudah termasuk Untuk Dibayar tidak ditambah lagi.</p><span class="sd-badge sd-warning">Laba bersih menunggu kelengkapan biaya</span></div>
            </article>

            <div class="sd-bottom">
                <article class="sd-card">
                    <div class="sd-section-heading"><div><h2>Penjualan harian</h2><p>Data API pada periode terpilih · tinggi batang mengikuti penjualan yang diketahui.</p></div><JlIcon name="chart" /></div>
                    <div class="sd-chart" role="img" aria-label="Grafik penjualan harian">
                        <div v-for="day in daily" :key="day.date" class="sd-bar-column" :title="`${day.date} · ${money(day.sales)} · ${day.complete ? 'HPP/keuangan lengkap' : 'Data parsial'}`"><span class="sd-bar" :class="{ 'is-partial': !day.complete }" :style="{ height: `${barHeight(day)}px` }"></span><small>{{ dayLabel(day.date) }}</small></div>
                        <p v-if="!daily.length" class="sd-empty">Belum ada pesanan API pada periode ini.</p>
                    </div>
                    <p class="sd-muted">Batang oranye: data keuangan/HPP belum lengkap. Hari tanpa pesanan tidak ditambahkan sebagai data penjualan.</p>
                </article>
                <article class="sd-card">
                    <div class="sd-section-heading"><div><h2>Perlu ditindaklanjuti</h2><p>Kelengkapan data dan kondisi pesanan.</p></div><JlIcon name="receipt" /></div>
                    <div class="sd-attention"><Link v-for="[label, value, path] in attentionItems" :key="label" :href="link(path)"><span>{{ label }}</span><strong>{{ value ?? 0 }}</strong></Link></div>
                    <p class="sd-muted">{{ attention.cancelled ?? 0 }} pesanan batal dan {{ attention.unpaid ?? 0 }} belum dibayar dipisahkan dari alur keuntungan.</p>
                </article>
            </div>

            <details class="sd-card sd-detail">
                <summary>Rincian sumber angka · {{ rows.length }} pesanan</summary>
                <p class="sd-muted">Rincian memakai periode yang sama. Klik nomor pesanan untuk memeriksa snapshot transaksi.</p>
                <div class="sd-table-wrap">
                    <table>
                        <thead><tr><th>Pesanan</th><th>Keuangan</th><th>Penjualan</th><th>Untuk Dibayar</th><th>HPP Produk</th><th>Laba setelah HPP</th></tr></thead>
                        <tbody><tr v-for="row in pageRows" :key="row.id"><td><Link :href="link('/pilot/marketplace/orders', { search: row.id, search_by: 'all' })">{{ row.id }}</Link></td><td>{{ stateLabel[row.state] ?? row.state }}</td><td>{{ money(row.sales) }}</td><td>{{ money(row.net) }}</td><td>{{ money(row.hpp) }}</td><td>{{ money(row.profit) }}</td></tr></tbody>
                    </table>
                </div>
                <div class="sd-pagination"><a v-if="page > 1" href="#" @click.prevent="page--">Sebelumnya</a><span>Halaman {{ page }} · 50 per halaman</span><a v-if="page * 50 < rows.length" href="#" @click.prevent="page++">Berikutnya</a></div>
            </details>

            <div class="sd-card sd-payout">
                <div><h2>Laba dan pencairan memiliki arti berbeda</h2><p>Untuk Dibayar adalah nilai keuangan pesanan. Penarikan dan dana masuk bank diperiksa pada Pemasukan.</p></div>
                <Link class="sd-button" :href="link('/marketplace/income')"><JlIcon name="bank" /> Lihat pencairan</Link>
            </div>

            <dialog ref="formula" class="sd-dialog">
                <header><h2>Cara Perhitungan</h2><button class="sd-button" type="button" aria-label="Tutup" @click="formula?.close()">×</button></header>
                <p><strong>Potongan bersih = Penjualan tercatat − Untuk Dibayar.</strong> Dasar penjualan memakai pendapatan dari rincian keuangan marketplace; jika belum tersedia, subtotal sumber ditampilkan sebagai penjualan tercatat. Diskon/refund/subsidi yang telah tercakup tidak dikurangkan ulang.</p>
                <p><strong>Laba setelah HPP = Untuk Dibayar − HPP transaksi.</strong> Retur yang jumlah pengembaliannya belum diketahui menghalangi pengesahan HPP.</p>
                <p><strong>Subtotal setelah biaya tercatat = laba setelah HPP dari pesanan lengkap − biaya iklan tercatat − operasional tercatat.</strong> Ini subtotal parsial, belum laba bersih riil. Tidak ada alokasi biaya yang dipaksakan antara final dan estimasi.</p>
                <p>Data kosong bukan nol. Tidak ada penjumlahan ulang settlement, penarikan, atau tabel iklan lama. Pengeluaran mengikuti tanggal beban, sedangkan pesanan mengikuti tanggal dibuat; subtotal ini bukan laporan akuntansi berbasis akrual yang telah direkonsiliasi.</p>
            </dialog>
            <footer class="sd-muted">Dihitung dari database hasil sinkronisasi · {{ dashboard.generated_at }} WIB · {{ groups.unknown?.count ?? 0 }} pesanan menunggu keuangan.</footer>
        </section>
    </MarketplaceShell>
</template>
