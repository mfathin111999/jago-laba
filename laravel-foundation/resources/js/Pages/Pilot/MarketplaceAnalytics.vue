<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import PlatformLogo from '../../Components/PlatformLogo.vue';
import { formatValue } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    read_only: boolean;
    workspace: string;
    scope: { company_key: string; store_id: string | null };
    stores: Row[];
    active_store?: Row | null;
    filters: Record<string, any>;
    analytics: Row | null;
    query: Record<string, any>;
    message?: string;
}>();

const form = reactive({
    period: props.filters.period ?? 'custom',
    date_start: props.analytics?.start ?? props.filters.date_start ?? '',
    date_end: props.analytics?.end ?? props.filters.date_end ?? '',
    date_basis: props.analytics?.date_basis ?? props.filters.date_basis ?? 'created',
});

const report = computed(() => props.analytics as Row);
const current = computed(() => report.value?.current ?? {});
const mode = computed(() => (report.value?.mode === 'settled' ? 'settled' : 'all'));
const chosen = computed(() => current.value[mode.value] ?? {});
const store = computed(() => report.value?.stores?.[0] ?? props.active_store ?? {});
const storeId = computed(() => String(props.scope.store_id ?? ''));

const value = (v: unknown, type: 'money' | 'count' | 'percent' = 'money') => (type === 'money' ? formatValue(v, 'currency') : type === 'count' ? formatValue(v, 'count') : formatValue(v, 'percent'));

const metrics: Array<[string, string, string]> = [
    ['revenue', 'Penjualan setelah diskon', 'Nilai finance_revenue sumber; jika belum tersedia, memakai nilai pesanan dan mengikuti status data.'],
    ['net_income', 'Untuk Dibayar', 'Nilai bersih keuangan marketplace, termasuk penyesuaian sumber. Belum membuktikan pencairan bank.'],
    ['cogs', 'HPP Produk', 'HPP yang tersimpan pada transaksi sesuai varian dan aturan tanggal berlaku.'],
    ['gross_profit', 'Laba setelah HPP', 'Untuk Dibayar − HPP. Tidak mengurangi kembali diskon atau penyesuaian yang sudah termasuk sumber.'],
    ['fees', 'Potongan bersih marketplace', 'Selisih penjualan dengan Untuk Dibayar; rincian sumber tersedia di bawah.'],
    ['ads', 'Iklan tercatat', 'Pengeluaran kategori iklan pada periode ini. Bukan top-up atau laporan atribusi kampanye.'],
    ['operating', 'Operasional tercatat', 'Pengeluaran yang memengaruhi laba selain kategori iklan.'],
    ['net_profit', 'Laba bersih operasional', 'Menunggu bukti kelengkapan biaya iklan dan operasional. Subtotal biaya tersedia ditampilkan terpisah.'],
];
const coverageItems: Array<[string, string]> = [['final_orders', 'Keuangan final'], ['estimated_orders', 'Estimasi / belum final'], ['unknown_orders', 'Menunggu keuangan'], ['missing_hpp', 'HPP belum lengkap'], ['unreconciled', 'Perlu rekonsiliasi']];
const operationItems: Array<[string, string]> = [['ready', 'Pesanan Baru'], ['pickup', 'Menunggu Pickup'], ['shipped', 'Dikirim'], ['completed', 'Selesai'], ['cancelled', 'Dibatalkan'], ['cancel_requested', 'Permintaan pembatalan'], ['returns', 'Retur / komplain tercatat'], ['unpaid', 'Belum dibayar'], ['review', 'Status perlu ditinjau']];
const flowItems: Array<[string, string]> = [['revenue', 'Penjualan'], ['fees', 'Potongan'], ['net_income', 'Untuk Dibayar'], ['cogs', 'HPP Produk'], ['gross_profit', 'Laba setelah HPP'], ['expenses', 'Pengeluaran tercatat'], ['net_profit', 'Laba bersih']];
const dailyKeys = ['revenue', 'fees', 'net_income', 'cogs', 'gross_profit', 'ads', 'operating', 'orders'];

const buckets: Array<[string, string, string]> = [
    ['all', 'Keuangan Final + Estimasi', 'Nilai estimasi dapat berubah ketika marketplace memperbarui keuangan.'],
    ['settled', 'Keuangan Final', 'Menggunakan bukti keuangan final; tidak otomatis berarti dana telah masuk bank.'],
];
const delta = (bucket: string, key: string) => {
    const now = current.value[bucket]?.[key];
    const old = report.value?.previous?.[bucket]?.[key];

    return now !== null && now !== undefined && old !== null && old !== undefined && Number(old) !== 0 ? ((Number(now) - Number(old)) / Math.abs(Number(old))) * 100 : null;
};
const deltaText = (bucket: string, key: string) => {
    const d = delta(bucket, key);

    return d === null ? 'Pembanding belum tersedia' : `${d >= 0 ? '+' : ''}${value(d, 'percent')} vs periode sebelumnya`;
};

const sourcesText = computed(() => Object.entries(current.value.sources ?? {}).map(([key, count]) => `${key}: ${count}`).join(' · ') || 'Belum ada pesanan');
const environment = computed(() => ({ live: 'Live', sandbox: 'Sandbox' }[String(store.value.environment ?? '')] ?? 'Lingkungan belum terverifikasi'));
const dailyEntries = computed(() => Object.entries((current.value.daily ?? {}) as Record<string, Row>));
const fees = computed(() => (current.value.fees?.[mode.value] ?? []) as Row[]);
const products = computed(() => (current.value.products ?? []) as Row[]);

const params = computed(() => ({ store: storeId.value, period: 'custom', date_start: report.value?.start, date_end: report.value?.end, date_basis: report.value?.date_basis, analytics_mode: mode.value }));
const pageLink = (changes: Record<string, string> = {}) => `/pilot/marketplace/analytics?${new URLSearchParams({ ...params.value, ...changes } as Record<string, string>).toString()}`;
const exportLink = computed(() => `/marketplace/analytics/export?${new URLSearchParams({ ...params.value, format: 'xlsx' } as Record<string, string>).toString()}`);
const link = (path: string) => `${path}?store=${encodeURIComponent(storeId.value)}&store_id=${encodeURIComponent(storeId.value)}`;

function apply(): void {
    router.get('/pilot/marketplace/analytics', { store: storeId.value, period: form.period, date_start: form.date_start, date_end: form.date_end, date_basis: form.date_basis, analytics_mode: mode.value }, { preserveScroll: true });
}
const refresh = () => router.reload();
let timer: number | undefined;
onMounted(() => { timer = window.setInterval(() => { if (!document.hidden && props.analytics) router.reload(); }, 30000); });
onBeforeUnmount(() => window.clearInterval(timer));

// ----- charts (same geometry as the legacy SVG) -----
const days = computed(() => dailyEntries.value.map(([date]) => date));
const chart = computed(() => {
    const count = days.value.length;
    const width = Math.max(800, count * 32 + 75);
    const baseX = 65;
    const plotWidth = width - 80;
    const plotHeight = 195;
    const raw: number[] = [];
    dailyEntries.value.forEach(([, day]) => {
        [day[mode.value]?.revenue, day[mode.value]?.gross_profit].forEach((v) => { if (v !== null && v !== undefined) raw.push(Number(v)); });
    });
    const list = raw.length ? raw : [0];
    const max = Math.max(1, ...list);
    const min = Math.min(0, ...list);
    const range = max - min || 1;
    const y = (v: number) => 15 + ((max - v) / range) * plotHeight;
    const step = plotWidth / Math.max(1, count);
    const ticks = [0, 1, 2, 3, 4].map((i) => {
        const tick = min + (range * i) / 4;

        return { y: y(tick), label: Math.abs(tick) >= 1000000 ? `${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(tick / 1000000)} jt` : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(tick) };
    });
    const bars: Array<Record<string, any>> = [];
    dailyEntries.value.forEach(([date, day], i) => {
        const x = baseX + i * step;
        ([['revenue', '#20b486', 'Omzet'], ['gross_profit', '#7295f3', 'Laba setelah HPP']] as const).forEach(([key, color, label]) => {
            const v = day[mode.value]?.[key];
            if (v === null || v === undefined) return;
            const barY = y(Number(v));
            bars.push({ key: `${date}-${key}`, x: x + (key === 'gross_profit' ? step * 0.42 : 0), y: Math.min(y(0), barY), w: step * 0.34, h: Math.max(0.5, Math.abs(y(0) - barY)), color, title: `${date} · ${label}: ${value(v)}` });
        });
    });
    const maxOrders = Math.max(1, ...dailyEntries.value.map(([, day]) => Number(day[mode.value]?.orders ?? 0)));
    const points = dailyEntries.value.map(([date, day], i) => ({ date, x: baseX + i * step + step * 0.35, y: 125 - (Number(day[mode.value]?.orders ?? 0) / maxOrders) * 100, orders: day[mode.value]?.orders ?? 0, returns: Number(day.all?.returns ?? 0) }));

    return { width, baseX, ticks, bars, zero: y(0), step, maxOrders, points, line: points.map((p) => `${p.x},${Math.round(p.y * 100) / 100}`).join(' ') };
});
const dayLabel = (date: string) => `${date.slice(8, 10)}/${date.slice(5, 7)}`;
</script>

<template>
    <MarketplaceShell active="analytics" title="Analitik & Laporan" :store-id="scope.store_id" :company-key="scope.company_key">
        <section v-if="!analytics || !active_store" id="global-analytics" class="ma-page">
            <header class="ma-header"><div><h1>Analitik &amp; Laporan</h1><p>Penjualan, keuangan, dan keuntungan toko dalam satu sumber perhitungan.</p></div></header>
            <div class="ma-notice" role="status"><b>Toko belum siap</b><p>{{ message || 'Pilih toko terlebih dahulu.' }} <Link href="/marketplace/stores">Pilih Toko</Link></p></div>
        </section>

        <section v-else id="global-analytics" class="ma-page">
            <header class="ma-header">
                <div>
                    <div class="ma-store-identity"><PlatformLogo :platform="store.platform ?? 'Marketplace'" :size="32" /><span class="ma-eyebrow">{{ store.platform ?? 'Marketplace' }} · {{ store.store_name }} · {{ environment }}</span></div>
                    <h1>Analitik &amp; Laporan</h1>
                    <p>Penjualan, keuangan, dan keuntungan toko dalam satu sumber perhitungan.</p>
                </div>
                <div class="ma-actions"><button type="button" @click="refresh">↻ Perbarui tampilan</button><a :href="exportLink">↓ Ekspor XLSX</a></div>
            </header>
            <form class="ma-card ma-filters" @submit.prevent="apply">
                <label>Periode<select v-model="form.period"><option value="custom">Rentang dipilih</option><option value="today">Hari ini</option><option value="yesterday">Kemarin</option><option value="week">7 hari terakhir</option><option value="month">Bulan ini</option><option value="last_month">Bulan lalu</option></select></label>
                <label>Dari<input v-model="form.date_start" type="date" required /></label>
                <label>Sampai<input v-model="form.date_end" type="date" required /></label>
                <label>Dasar tanggal<select v-model="form.date_basis"><option value="created">Pesanan dibuat</option><option value="released">Settlement sumber</option></select></label>
                <button class="ma-primary">Terapkan</button>
                <Link :href="pageLink({ period: 'month' })">Reset</Link>
            </form>
            <nav class="ma-tabs" aria-label="Bagian laporan">
                <a v-for="[anchor, label] in [['ringkasan', 'Ringkasan'], ['keuangan', 'Keuangan'], ['pesanan', 'Pesanan'], ['produk', 'Produk'], ['iklan', 'Iklan'], ['laporan', 'Laporan']]" :key="anchor" :href="`#ma-${anchor}`">{{ label }}</a>
            </nav>
            <div class="ma-notice" role="status">
                <b>Dihitung dari database: {{ report.generated_at }} WIB</b>
                <p>Sinkronisasi API berhasil terakhir: {{ store.last_successful_sync_at ?? 'Belum tersedia' }}. Pembaruan tampilan setiap 30 detik membaca database; pengambilan API mengikuti proses sinkronisasi toko.</p>
                <p>Sumber periode: {{ sourcesText }} · IDR · Asia/Jakarta</p>
            </div>

            <section id="ma-ringkasan">
                <div class="ma-section-title"><h2>Kelengkapan data</h2><Link :href="link('/pilot/marketplace/orders')">Lihat transaksi →</Link></div>
                <div class="ma-coverage"><article v-for="[key, label] in coverageItems" :key="key"><strong>{{ value(current.all?.[key], 'count') }}</strong><span>{{ label }}</span></article></div>
                <p v-if="current.missing_release_dates" class="ma-warning">{{ current.missing_release_dates }} catatan tanpa tanggal settlement sumber tidak dimasukkan pada dasar tanggal ini.</p>
            </section>

            <section v-for="[bucket, heading, hint] in buckets" :id="bucket === 'all' ? 'ma-keuangan' : 'ma-final'" :key="bucket">
                <div class="ma-section-title"><div><h2>{{ heading }}</h2><p>{{ hint }}</p></div></div>
                <div class="ma-grid">
                    <article v-for="[key, label, description] in metrics" :key="key" class="ma-card ma-metric">
                        <span>{{ label }}</span>
                        <strong :class="{ 'ma-negative': current[bucket]?.[key] !== null && current[bucket]?.[key] < 0 }">{{ value(current[bucket]?.[key]) }}</strong>
                        <small>{{ deltaText(bucket, key) }}</small>
                        <details><summary>Rumus &amp; sumber</summary><p>{{ description }}</p><p>{{ current[bucket]?.orders }} pesanan · {{ current[bucket]?.profit_complete_orders }} laba setelah HPP lengkap.</p></details>
                    </article>
                </div>
                <div class="ma-notice"><b>Subtotal data tersedia: {{ value(current[bucket]?.known_operating_subtotal) }}</b><p>Laba setelah HPP dari {{ current[bucket]?.profit_complete_orders }} pesanan dengan data cocok, dikurangi seluruh pengeluaran tercatat pada periode ini. Cakupan biaya belum terbukti lengkap; nilai ini bukan laba bersih final.</p></div>
            </section>

            <section class="ma-card">
                <div class="ma-section-title"><h2>Alur keuntungan</h2><div class="ma-actions"><Link :href="pageLink({ analytics_mode: 'all' })" :aria-current="mode === 'all' ? 'true' : undefined">Final + estimasi</Link><Link :href="pageLink({ analytics_mode: 'settled' })" :aria-current="mode === 'settled' ? 'true' : undefined">Final</Link></div></div>
                <div class="ma-flow"><div v-for="[key, label] in flowItems" :key="key"><span>{{ label }}</span><strong>{{ value(chosen[key]) }}</strong></div></div>
            </section>

            <section class="ma-card">
                <h2>Performa harian</h2>
                <p>Hijau: penjualan · Biru: laba setelah HPP. Data yang belum lengkap tidak digambar sebagai nol.</p>
                <div class="overflow-x-auto">
                    <svg :viewBox="`0 0 ${chart.width} 250`" :style="{ minWidth: `${chart.width}px`, width: '100%', height: '280px' }" role="img" aria-label="Grafik batang omzet dan laba harian">
                        <template v-for="tick in chart.ticks" :key="tick.y"><line x1="65" :x2="chart.width - 15" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" /><text x="60" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="9">{{ tick.label }}</text></template>
                        <rect v-for="bar in chart.bars" :key="bar.key" :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h" rx="2" :fill="bar.color"><title>{{ bar.title }}</title></rect>
                        <text v-for="(date, i) in days" :key="date" :x="chart.baseX + i * chart.step + chart.step * 0.35" y="230" text-anchor="middle" fill="#94a3b8" font-size="8">{{ dayLabel(date) }}</text>
                    </svg>
                </div>
                <div class="overflow-x-auto">
                    <svg :viewBox="`0 0 ${chart.width} 160`" :style="{ minWidth: `${chart.width}px`, width: '100%', height: '170px' }" role="img" aria-label="Grafik pesanan dan retur harian">
                        <line x1="65" :x2="chart.width - 15" y1="125" y2="125" stroke="#e2e8f0" />
                        <polyline :points="chart.line" stroke="#6d4cce" stroke-width="2" fill="none" />
                        <template v-for="point in chart.points" :key="point.date"><circle :cx="point.x" :cy="point.y" r="4" fill="#6d4cce"><title>{{ point.date }} · Pesanan: {{ point.orders }} · Retur: {{ point.returns }}</title></circle><rect :x="point.x - 4" :y="125 - (point.returns / chart.maxOrders) * 100" width="8" :height="Math.max(0.5, (point.returns / chart.maxOrders) * 100)" fill="#fbbf24" /><text :x="point.x" y="150" text-anchor="middle" fill="#94a3b8" font-size="8">{{ dayLabel(point.date) }}</text></template>
                        <text x="5" y="25" fill="#94a3b8" font-size="9">{{ chart.maxOrders }} pesanan</text>
                    </svg>
                </div>
                <div class="ma-table">
                    <table>
                        <thead><tr><th v-for="label in ['Tanggal', 'Penjualan', 'Potongan', 'Untuk Dibayar', 'HPP Produk', 'Laba setelah HPP', 'Iklan', 'Operasional', 'Pesanan']" :key="label">{{ label }}</th></tr></thead>
                        <tbody><tr v-for="[date, day] in dailyEntries" :key="date"><td>{{ date }}</td><td v-for="key in dailyKeys" :key="key">{{ value(day[mode]?.[key], key === 'orders' ? 'count' : 'money') }}</td></tr></tbody>
                    </table>
                </div>
            </section>

            <section class="ma-card">
                <h2>Rincian biaya marketplace</h2>
                <p>Potongan dan kredit mengikuti komponen sumber. Rincian dapat mencakup diskon yang sudah diperhitungkan dalam penjualan; jangan menjumlahkannya ulang sebagai pengeluaran.</p>
                <div class="ma-table"><table><thead><tr><th>Komponen sumber</th><th>Jenis</th><th>Nominal</th></tr></thead><tbody><tr v-for="fee in fees" :key="fee.label"><td>{{ fee.label }}</td><td>{{ fee.effect < 0 ? 'Kredit' : 'Potongan' }}</td><td>{{ value(fee.amount) }}</td></tr><tr v-if="!fees.length"><td colspan="3">Rincian biaya belum tersedia pada periode ini.</td></tr></tbody></table></div>
            </section>

            <section id="ma-pesanan">
                <h2>Operasional pesanan</h2>
                <div class="ma-coverage"><article v-for="[key, label] in operationItems" :key="key"><strong>{{ value(current.all?.[key], 'count') }}</strong><span>{{ label }}</span></article></div>
            </section>

            <section id="ma-produk" class="ma-card">
                <h2>Produk &amp; varian terjual</h2>
                <p>Unit berdasarkan detail pesanan, terpisah dari pengakuan pendapatan. Biaya tingkat pesanan belum dialokasikan ke produk.</p>
                <div class="ma-table"><table><thead><tr><th>Produk</th><th>Varian</th><th>SKU</th><th>Unit dipesan</th><th>Retur tercatat</th></tr></thead><tbody><tr v-for="(product, index) in products" :key="index"><td>{{ product.name }}</td><td>{{ product.variant }}</td><td>{{ product.sku }}</td><td>{{ value(product.quantity, 'count') }}</td><td>{{ product.returns_unknown ? 'Belum lengkap' : value(product.returns, 'count') }}</td></tr><tr v-if="!products.length"><td colspan="5">Belum ada detail produk pada sumber periode ini.</td></tr></tbody></table></div>
            </section>

            <section id="ma-iklan" class="ma-card">
                <h2>Iklan &amp; atribusi</h2>
                <p>Pengeluaran iklan tercatat: <b>{{ value(chosen.ads) }}</b>. Laporan advertiser, pendapatan teratribusi, dan ROAS kampanye belum terhubung pada modul ini.</p>
                <p>Rasio penjualan toko ÷ iklan tercatat: {{ chosen.roas === null || chosen.roas === undefined ? 'Belum tersedia' : `${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(chosen.roas)}x` }}. Ini bukan ROAS atribusi kampanye.</p>
                <Link href="/marketplace/integrations">Periksa Integrasi API →</Link>
            </section>

            <section id="ma-laporan" class="ma-card">
                <h2>Laporan &amp; penelusuran</h2>
                <p>Ekspor XLSX memuat metadata, KPI, data harian, komponen biaya, dan produk. Perbandingan: {{ report.previous_start }} — {{ report.previous_end }}. Maksimal 45 hari per laporan.</p>
                <div class="ma-actions"><a :href="exportLink">↓ Unduh laporan XLSX</a><Link :href="link('/marketplace/income')">Settlement, penarikan &amp; bank →</Link><Link :href="link('/marketplace/sync')">Riwayat sinkronisasi →</Link></div>
            </section>
        </section>
    </MarketplaceShell>
</template>
