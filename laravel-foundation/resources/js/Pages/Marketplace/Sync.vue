<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import DataTable, { type Column } from '../../Components/DataTable.vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import KpiCard from '../../Components/KpiCard.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatDate, formatText } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    preview: Row | null;
    status: Row | null;
    auto: Row[];
    scheduler_available: boolean;
    live?: boolean;
    can_sync: boolean;
    can_manage: boolean;
}>();

const AUTO_MODES: Record<string, { title: string; every: string; desc: string; tiktokOnly?: boolean }> = {
    new: { title: 'Transaksi baru', every: 'Setiap 1 menit', desc: 'Menjadwalkan pemindaian pesanan baru.' },
    unpaid: { title: 'Pesanan belum dibayar', every: 'Setiap 1 menit', desc: 'Menjadwalkan pemindaian pesanan berstatus belum dibayar.' },
    status: { title: 'Status transaksi', every: 'Setiap 5 menit', desc: 'Menjadwalkan pembaruan status pesanan.' },
    finance: { title: 'Rincian pendapatan', every: 'Setiap 5 menit', desc: 'Menjadwalkan pembaruan data keuangan pesanan.', tiktokOnly: true },
    returns: { title: 'Pengembalian barang dan dana', every: 'Setiap 15 menit', desc: 'Menjadwalkan pembaruan kasus retur.', tiktokOnly: true },
};
const JOB_TONE: Record<string, 'ok' | 'warn' | 'danger' | 'info' | 'muted'> = { succeeded: 'ok', failed: 'danger', queued: 'info', running: 'warn' };

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);
const live = ref<Row | null>(props.status);
const storeId = computed(() => props.scope.store_id ?? '');
const platform = computed(() => String(props.preview?.platform ?? props.stores.find((store) => store.id === storeId.value)?.platform ?? ''));

function notify(message: string, kind: 'success' | 'error' = 'success'): void {
    flash.message = message;
    flash.kind = kind;
}

function chooseStore(event: Event): void {
    router.get('/marketplace/sync', { store_id: (event.target as HTMLSelectElement).value });
}

// ----- manual run + live status -----
let timer: ReturnType<typeof setInterval> | null = null;

async function refreshStatus(): Promise<void> {
    if (!storeId.value) return;
    try {
        live.value = (await apiRequest(`/api/marketplace-sync.php?action=status&store_id=${encodeURIComponent(storeId.value)}`, undefined, { method: 'GET' })).data ?? live.value;
    } catch {
        // Keep the last known status; the next tick retries.
    }
}

function stopPolling(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

watch(
    () => Number(live.value?.pending ?? 0) > 0,
    (pending) => {
        stopPolling();
        if (pending) timer = setInterval(refreshStatus, 5000);
    },
    { immediate: true },
);
onBeforeUnmount(stopPolling);

async function startSync(): Promise<void> {
    busy.value = true;
    try {
        const result = await apiRequest('/api/marketplace-sync.php', { action: 'start', store_id: storeId.value });
        live.value = result.data ?? live.value;
        notify('Sinkronisasi masuk antrean. Halaman boleh ditutup, worker server yang memprosesnya.');
    } catch (error) {
        notify(error instanceof ApiError ? error.message : 'Sinkronisasi belum dapat dimulai.', 'error');
    } finally {
        busy.value = false;
    }
}

const jobColumns: Column[] = [
    { key: 'id', label: 'ID', kind: 'count' },
    { key: 'job_type', label: 'Jenis' },
    { key: 'status', label: 'Status' },
    { key: 'processed', label: 'Diproses', kind: 'count', align: 'right' },
    { key: 'failure_count', label: 'Gagal', kind: 'count', align: 'right' },
    { key: 'requested_at', label: 'Diminta', kind: 'date' },
    { key: 'last_error_code', label: 'Kode galat' },
];
const pending = computed(() => Number(live.value?.pending ?? 0));
const windowText = computed(() => {
    const window = props.preview?.window;
    if (!window) return null;
    const fmt = (stamp: number) => new Date(stamp * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Jakarta' });

    return `${fmt(window.window_start)} – ${fmt(window.window_end)}`;
});

// ----- automatic schedules -----
const autoRows = computed(() => props.auto.filter((state) => !AUTO_MODES[state.mode]?.tiktokOnly || platform.value === 'TikTok Shop'));

async function toggleAuto(state: Row): Promise<void> {
    busy.value = true;
    try {
        await apiRequest('/api/marketplace-auto-sync.php', { store_id: storeId.value, mode: state.mode, enabled: state.enabled ? '0' : '1' });
        notify(`${AUTO_MODES[state.mode]?.title ?? state.mode} ${state.enabled ? 'dinonaktifkan' : 'diaktifkan'}.`);
        router.reload({ only: ['auto'] });
    } catch (error) {
        notify(error instanceof ApiError ? error.message : 'Pengaturan belum tersimpan.', 'error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <MarketplaceShell
        active="sync"
        title="Sinkronisasi"
        eyebrow="Marketplace / Sinkronisasi"
        subtitle="Jalankan sinkronisasi toko, pantau antrean, dan atur jadwal otomatis."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <section v-if="live" class="panel notice">
            <strong>Mode live.</strong>
            <span class="muted"> Sinkronisasi membaca produk, pesanan, transaksi, dan retur dari API resmi marketplace dengan mesin yang sama seperti aplikasi lama. Worker antrean harus berjalan; kemajuan tampil di tabel di bawah.</span>
        </section>
        <section v-else class="panel notice">
            <strong>Mode sandbox.</strong>
            <span class="muted"> Worker saat ini memindai data pesanan yang sudah tersimpan. Belum ada pengambilan data dari marketplace Live, jadi sinkronisasi tidak menambah pesanan baru dari provider.</span>
        </section>

        <div class="panel filter-bar">
            <div class="field filter-store">
                <label for="y-store">Toko</label>
                <select id="y-store" :value="storeId" @change="chooseStore"><option value="" disabled>Pilih toko</option><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
        </div>

        <section v-if="!scope.store_id" class="panel empty-state"><h2>Belum ada toko</h2><p class="muted">Tambahkan dan hubungkan toko terlebih dahulu.</p></section>

        <section v-else-if="!preview" class="panel empty-state">
            <h2>Toko belum terhubung</h2>
            <p class="muted">Sinkronisasi hanya tersedia untuk toko dengan koneksi terverifikasi. <a :href="`/marketplace/integrations?store_id=${encodeURIComponent(storeId)}`">Buka halaman Integrasi</a>.</p>
        </section>

        <template v-else>
            <section class="kpi-grid" aria-label="Ringkasan sinkronisasi">
                <KpiCard label="Pesanan tersimpan" :value="preview.order_count" kind="count" />
                <KpiCard label="Sinkron berhasil terakhir" :value="0" :text="formatDate(preview.last_successful_sync_at)" />
                <KpiCard label="Menunggu / berjalan" :value="pending" kind="count" />
                <KpiCard label="Gagal (10 terakhir)" :value="live?.failed" kind="count" />
            </section>

            <section class="panel">
                <div class="section-heading compact">
                    <div>
                        <span class="eyebrow">{{ preview.platform }}{{ preview.external_store_name ? ` · ${preview.external_store_name}` : '' }}</span>
                        <h2>Sinkronisasi manual</h2>
                        <p class="muted">Cakupan: <span v-if="windowText">{{ windowText }} (bulan berjalan)</span><span v-else>belum diketahui</span>. Antrean diproses worker server, halaman boleh ditutup.</p>
                    </div>
                    <button v-if="can_sync" class="button button-primary" type="button" :disabled="busy || pending > 0" @click="startSync">{{ pending > 0 ? 'Sedang berjalan…' : 'Sinkronkan sekarang' }}</button>
                </div>
                <p v-if="!can_sync" class="muted">Peran Anda tidak dapat menjalankan sinkronisasi.</p>
                <DataTable :columns="jobColumns" :rows="live?.jobs ?? []" row-key="id" empty="Belum ada sinkronisasi untuk toko ini.">
                    <template #cell-status="{ row }"><StatusBadge :label="formatText(row.status)" :tone="JOB_TONE[row.status] ?? 'muted'" /></template>
                    <template #cell-last_error_code="{ row }">{{ formatText(row.last_error_code) }}</template>
                </DataTable>
            </section>

            <section class="panel">
                <h2>Jadwal otomatis</h2>
                <p class="muted">Penjadwal server menambahkan satu pekerjaan per jadwal yang jatuh tempo, tanpa tumpang tindih.<span v-if="!scheduler_available"> Penjadwal belum tersedia di server ini.</span> Hanya administrator yang dapat mengubahnya.</p>
                <ul class="switch-list">
                    <li v-for="state in autoRows" :key="state.mode">
                        <div>
                            <strong>{{ AUTO_MODES[state.mode]?.title ?? state.mode }}</strong>
                            <StatusBadge :label="state.enabled ? 'Aktif' : 'Nonaktif'" :tone="state.enabled ? 'ok' : 'muted'" />
                            <div class="muted cell-note">{{ AUTO_MODES[state.mode]?.every }} · {{ AUTO_MODES[state.mode]?.desc }}</div>
                            <div v-if="state.enabled" class="muted cell-note">Berhasil terakhir: {{ formatDate(state.last_success_at) }} · Berikutnya: {{ formatDate(state.next_run_at) }}</div>
                            <div v-if="state.last_error_code" class="cell-note warn-text">Galat terakhir: {{ state.last_error_code }}</div>
                        </div>
                        <button class="button button-small" :class="state.enabled ? 'button-secondary' : 'button-primary'" type="button" role="switch" :aria-checked="state.enabled" :disabled="busy || !can_manage" @click="toggleAuto(state)">{{ state.enabled ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                    </li>
                </ul>
            </section>

            <section class="panel">
                <h2>Editor harga</h2>
                <p class="muted">Belum aktif. Mengubah harga di marketplace membutuhkan konektor resmi dan persetujuan penulisan ke provider, yang belum tersedia. Tidak ada perubahan harga yang dikirim dari aplikasi ini.</p>
            </section>
        </template>
    </MarketplaceShell>
</template>
