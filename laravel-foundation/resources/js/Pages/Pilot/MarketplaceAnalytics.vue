<script setup lang="ts">
import { computed, reactive } from 'vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { router } from '@inertiajs/vue3';

type Store = Record<string, any>;
type Analytics = Record<string, any>;

const props = defineProps<{
    read_only: boolean;
    workspace: string;
    scope: { company_key: string; store_id: string | null };
    stores: Store[];
    active_store?: Store | null;
    filters: Record<string, any>;
    analytics: Analytics | null;
    query: Record<string, any>;
    message?: string;
}>();

const form = reactive({
    store: props.scope.store_id ?? '',
    period: props.filters.period ?? 'month',
    date_start: props.filters.date_start ?? '',
    date_end: props.filters.date_end ?? '',
    date_basis: props.filters.date_basis ?? 'created',
    analytics_mode: props.filters.analytics_mode ?? 'all',
});

const current = computed(() => props.analytics?.current?.all ?? null);
const settled = computed(() => props.analytics?.current?.settled ?? null);
const daily = computed(() => (props.analytics?.current?.daily ?? []) as Array<Record<string, any>>);
const sources = computed(() => Object.entries(props.analytics?.current?.sources ?? {}));
const fees = computed(() => Object.values(props.analytics?.current?.fees?.all ?? {}) as Array<Record<string, any>>);

const coverage = computed(() => [
    { label: 'Order final', value: current.value?.final_orders, hint: 'Finance settled' },
    { label: 'Order estimated', value: current.value?.estimated_orders, hint: 'Belum final' },
    { label: 'Finance unknown', value: current.value?.missing_income, hint: 'Tetap null' },
    { label: 'HPP belum lengkap', value: current.value?.missing_hpp, hint: 'Tidak ditebak' },
    { label: 'Tidak rekonsiliasi', value: current.value?.unreconciled, hint: 'Perlu review' },
]);

const metricGroups = computed(() => [
    {
        title: 'Semua order',
        data: current.value,
        items: [
            ['Omzet', 'revenue'], ['Fee', 'fees'], ['Net income', 'net_income'], ['HPP / COGS', 'cogs'],
            ['Laba kotor', 'gross_profit'], ['Biaya tercatat', 'expenses'], ['ROAS iklan', 'roas'], ['Laba bersih', 'net_profit'],
        ],
    },
    {
        title: 'Order settled',
        data: settled.value,
        items: [
            ['Omzet', 'revenue'], ['Fee', 'fees'], ['Net income', 'net_income'], ['HPP / COGS', 'cogs'],
            ['Laba kotor', 'gross_profit'], ['Biaya tercatat', 'expenses'], ['ROAS iklan', 'roas'], ['Laba bersih', 'net_profit'],
        ],
    },
]);

function loadAnalytics(): void {
    const params: Record<string, string> = {
        store: form.store,
        period: form.period,
        date_basis: form.date_basis,
        analytics_mode: form.analytics_mode,
    };

    if (form.period === 'custom') {
        params.date_start = form.date_start;
        params.date_end = form.date_end;
    }

    Object.keys(params).forEach((key) => { if (params[key] === '') delete params[key]; });
    router.get('/pilot/marketplace/analytics', params, { preserveState: true, preserveScroll: true, replace: true });
}

function displayMoney(value: unknown): string {
    if (value === null || value === undefined || value === '') return 'Belum tersedia';

    return `Rp ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(value))}`;
}

function displayNumber(value: unknown): string {
    if (value === null || value === undefined || value === '') return 'Belum tersedia';

    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(value));
}

function displayRatio(value: unknown): string {
    if (value === null || value === undefined || value === '') return 'Belum tersedia';

    return Number(value).toFixed(2);
}

function displayMetric(value: unknown, key: string): string {
    return key === 'roas' ? displayRatio(value) : ['orders', 'final_orders', 'estimated_orders', 'missing_income', 'missing_hpp', 'unreconciled'].includes(key)
        ? displayNumber(value)
        : displayMoney(value);
}

function dateLabel(value: unknown): string {
    return value ? String(value).slice(0, 10) : 'Belum tersedia';
}
</script>

<template>
    <MarketplaceShell active="analytics" title="Analitik & laporan" eyebrow="Marketplace / Analitik & HPP" subtitle="Keuangan dan rekonsiliasi dengan kontrak angka dari runtime legacy." :store-id="scope.store_id" :company-key="scope.company_key">
        <template #actions>
            <StatusBadge label="Read-only" tone="ok" />
            <StatusBadge label="Provider tidak dipanggil" tone="muted" />
        </template>


        <section class="panel analytics-filter-panel">
            <form class="analytics-filter-grid" @submit.prevent="loadAnalytics">
                <label class="field">Toko<select v-model="form.store"><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select></label>
                <label class="field">Periode<select v-model="form.period"><option value="today">Hari ini</option><option value="yesterday">Kemarin</option><option value="week">7 hari terakhir</option><option value="month">Bulan ini</option><option value="last_month">Bulan lalu</option><option value="custom">Rentang tanggal</option></select></label>
                <label v-if="form.period === 'custom'" class="field">Mulai<input v-model="form.date_start" type="date" required /></label>
                <label v-if="form.period === 'custom'" class="field">Sampai<input v-model="form.date_end" type="date" required /></label>
                <label class="field">Dasar tanggal<select v-model="form.date_basis"><option value="created">Dibuat</option><option value="released">Dicairkan</option></select></label>
                <label class="field">Mode<select v-model="form.analytics_mode"><option value="all">Semua</option><option value="settled">Settled</option></select></label>
                <button class="button button-primary" type="submit">Terapkan</button>
            </form>
        </section>

        <section v-if="message" class="panel analytics-empty">
            <span class="eyebrow">Store belum siap</span>
            <h2>Analitik belum tersedia</h2>
            <p class="muted">{{ message }}</p>
        </section>

        <template v-if="analytics && active_store">
            <div class="panel" style="display:flex;gap:1rem;margin-bottom:1rem">
                <a v-for="format in ['csv','xlsx']" :key="format" :href="`/marketplace/analytics/export?store=${encodeURIComponent(scope.store_id ?? '')}&period=${filters.period ?? 'month'}&date_start=${filters.date_start ?? ''}&date_end=${filters.date_end ?? ''}&date_basis=${filters.date_basis ?? 'created'}&analytics_mode=${filters.analytics_mode ?? 'all'}&format=${format}`">Unduh {{ format.toUpperCase() }}</a>
            </div>
            <section class="analytics-hero panel">
                <div>
                    <span class="eyebrow">{{ active_store.platform }} · {{ active_store.authorization_label }}</span>
                    <h2>{{ active_store.store_name || active_store.id }}</h2>
                    <p class="muted">{{ analytics.start }} sampai {{ analytics.end }} · dasar {{ analytics.date_basis }} · {{ analytics.currency }}</p>
                </div>
                <div class="analytics-meta"><strong>{{ analytics.generated_at }}</strong><span>Snapshot read-only</span></div>
            </section>

            <section class="analytics-coverage" aria-label="Coverage finance">
                <article v-for="item in coverage" :key="item.label" class="metric-card"><span class="metric-label">{{ item.label }}</span><strong>{{ displayNumber(item.value) }}</strong><small>{{ item.hint }}</small></article>
            </section>

            <section v-for="group in metricGroups" :key="group.title" class="panel analytics-section">
                <div class="section-heading"><div><span class="eyebrow">Finance</span><h2>{{ group.title }}</h2></div><span class="coverage-badge is-partial">Null tidak disamakan dengan Rp 0</span></div>
                <div class="analytics-metric-grid"><article v-for="item in group.items" :key="item[1]" class="analytics-metric"><span>{{ item[0] }}</span><strong>{{ displayMetric(group.data?.[item[1]], item[1]) }}</strong></article></div>
            </section>

            <section class="analytics-columns">
                <article class="panel analytics-section"><div class="section-heading"><div><span class="eyebrow">Coverage</span><h2>Metadata sumber</h2></div></div><dl class="data-list"><div><dt>Order tercatat</dt><dd>{{ displayNumber(current?.orders) }}</dd></div><div><dt>Revenue pending</dt><dd>{{ displayMoney(current?.pending_revenue) }}</dd></div><div><dt>HPP lengkap</dt><dd>{{ current?.missing_hpp === 0 ? 'Ya' : 'Tidak' }}</dd></div><div><dt>Biaya lengkap</dt><dd>{{ current?.cost_coverage_complete ? 'Ya' : 'Belum' }}</dd></div><div v-for="source in sources" :key="source[0]"><dt>Source {{ source[0] }}</dt><dd>{{ displayNumber(source[1]) }}</dd></div></dl></article>
                <article class="panel analytics-section"><div class="section-heading"><div><span class="eyebrow">Fee detail</span><h2>Komponen biaya marketplace</h2></div></div><div v-if="fees.length" class="data-list"><div v-for="fee in fees" :key="fee.key"><dt>{{ fee.label }}</dt><dd>{{ displayMoney(fee.amount) }}</dd></div></div><p v-else class="empty-inline">Belum ada komponen fee yang tersedia.</p></article>
            </section>

            <section class="panel analytics-section"><div class="section-heading"><div><span class="eyebrow">Performa harian</span><h2>Omzet, HPP, dan laba</h2></div><span class="muted">{{ daily.length }} hari</span></div><div v-if="daily.length" class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Omzet</th><th>Net income</th><th>HPP</th><th>Laba kotor</th><th>Order</th></tr></thead><tbody><tr v-for="day in daily" :key="day.date"><td>{{ dateLabel(day.date) }}</td><td>{{ displayMoney(day.all?.revenue) }}</td><td>{{ displayMoney(day.all?.net_income) }}</td><td>{{ displayMoney(day.all?.cogs) }}</td><td>{{ displayMoney(day.all?.gross_profit) }}</td><td>{{ displayNumber(day.all?.orders) }}</td></tr></tbody></table></div><p v-else class="empty-inline">Belum ada data pada periode ini.</p></section>

            <section class="panel analytics-proof"><span class="eyebrow">Bukti boundary</span><h2>Query dan operasi</h2><p class="muted">Company/store scope dipasang server-side. Data berasal dari order API/import aktif; tidak ada write, refresh provider, atau token yang diteruskan ke halaman.</p><div class="analytics-proof-grid"><span>Scope: {{ query.where?.company_key }} / {{ query.where?.store_id }}</span><span>Provider call: {{ query.provider_call ? 'ya' : 'tidak' }}</span><span>Write: {{ query.write ? 'ya' : 'tidak' }}</span><span>Basis: {{ query.where?.date_basis }}</span></div></section>
        </template>
    </MarketplaceShell>
</template>
