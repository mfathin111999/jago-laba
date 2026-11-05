<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatDate as fmtDate, formatValue } from '../../lib/format';

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
const JOB_CLASS: Record<string, string> = { succeeded: 'bg-emerald-50 text-emerald-700', failed: 'bg-rose-50 text-rose-700', queued: 'bg-blue-50 text-blue-700', running: 'bg-amber-50 text-amber-800' };
const INFO: Array<[string, string]> = [
    ['Produk & Varian', 'Katalog, SKU, harga, stok per gudang, dan status produk.'],
    ['Order & Transaksi', 'Pesanan, item, status, pembayaran, dan nomor resi yang tersedia.'],
    ['Rincian Pendapatan', 'Transaksi keuangan per order dari Finance API; membutuhkan izin finance.'],
    ['Retur & Refund', 'Arsip data retur/refund dari API; membutuhkan izin return/refund.'],
];
const count = (value: unknown) => formatValue(value, 'count');
const formatDate = (value: unknown) => fmtDate(value);

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
    <MarketplaceShell active="sync" title="Sinkronisasi" :store-id="scope.store_id" :company-key="scope.company_key">
        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />
        <section id="sync-console" class="bg-white border rounded-2xl p-5 space-y-5">
            <div><h2 class="font-extrabold text-xl">Sinkronisasi Data</h2><p class="text-sm text-slate-500 mt-2">Pilih toko marketplace yang sudah diotorisasi. Data dibaca dari API resmi dan disimpan ke ruang kerja Marketplace.</p></div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                <div v-for="[title, text] in INFO" :key="title" class="rounded-xl border bg-slate-50 p-4"><b>{{ title }}</b><p class="mt-2 text-slate-500 leading-5">{{ text }}</p></div>
            </div>
            <p class="rounded-xl border p-3 text-xs" :class="live ? 'border-blue-200 bg-blue-50 text-blue-900' : 'border-amber-200 bg-amber-50 text-amber-900'"><b>{{ live ? 'Mode live.' : 'Mode sandbox.' }}</b> {{ live ? 'Sinkronisasi membaca produk, pesanan, transaksi, dan retur dari API resmi marketplace dengan mesin yang sama seperti aplikasi lama. Worker antrean harus berjalan; kemajuan tampil di tabel di bawah.' : 'Worker saat ini memindai data pesanan yang sudah tersimpan. Belum ada pengambilan data dari marketplace Live, jadi sinkronisasi tidak menambah pesanan baru dari provider.' }}</p>
            <label class="block text-xs font-bold">Toko terhubung
                <select class="block w-full max-w-xl rounded-xl border p-3 mt-2" :value="storeId" @change="chooseStore"><option value="">Pilih toko terhubung</option><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }} · {{ store.authorization_status || store.platform }}</option></select>
            </label>
            <p v-if="!stores.length" class="text-sm text-amber-700">Hubungkan toko melalui Pengaturan &gt; Tambah Toko.</p>
            <p v-else-if="scope.store_id && !preview" class="text-sm text-amber-700">Toko belum terhubung. Sinkronisasi hanya tersedia untuk toko dengan koneksi terverifikasi. <Link class="underline font-bold" :href="`/marketplace/integrations?store_id=${encodeURIComponent(storeId)}`">Buka halaman Integrasi</Link>.</p>
            <template v-if="preview">
                <p class="text-xs text-slate-500">{{ preview.platform }}{{ preview.external_store_name ? ` · ${preview.external_store_name}` : '' }} · Pesanan tersimpan: <b>{{ count(preview.order_count) }}</b> · Sinkron berhasil terakhir: <b>{{ formatDate(preview.last_successful_sync_at) }}</b> · Cakupan: {{ windowText ? `${windowText} (bulan berjalan)` : 'belum diketahui' }}</p>
                <div class="flex flex-wrap gap-2">
                    <button v-if="can_sync" type="button" class="rounded-xl bg-brand-600 text-white px-5 py-3 text-xs font-bold" :disabled="busy || pending > 0" @click="startSync">{{ pending > 0 ? 'Sedang berjalan…' : 'Sinkronisasi Data' }}</button>
                    <button type="button" class="rounded-xl border px-4 py-3 text-xs font-bold" :disabled="!pending" @click="refreshStatus">Lanjutkan Antrean</button>
                    <button type="button" class="rounded-xl border px-4 py-3 text-xs" @click="stopPolling">Jeda Pemantauan</button>
                    <button type="button" class="rounded-xl border px-4 py-3 text-xs" @click="refreshStatus">Perbarui Status</button>
                </div>
                <p v-if="!can_sync" class="text-xs text-slate-600">Peran Anda tidak dapat menjalankan sinkronisasi.</p>
                <p v-else class="text-xs text-slate-600" role="status" aria-live="polite">Pilih toko lalu mulai. Worker berjalan di server; halaman boleh ditutup setelah worker berhasil dimulai.</p>
                <div class="overflow-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th v-for="title in ['ID', 'Jenis Data', 'Status', 'Diproses', 'Gagal', 'Diminta', 'Kode Error']" :key="title" class="p-3 text-left">{{ title }}</th></tr></thead>
                        <tbody>
                            <tr v-for="job in live?.jobs ?? status?.jobs ?? []" :key="job.id" class="border-t"><td class="p-3">{{ job.id }}</td><td class="p-3">{{ job.job_type }}</td><td class="p-3"><span class="px-2 py-1 rounded-full" :class="JOB_CLASS[job.status] ?? 'bg-slate-100 text-slate-600'">{{ job.status }}</span></td><td class="p-3">{{ count(job.processed) }}</td><td class="p-3">{{ count(job.failure_count) }}</td><td class="p-3">{{ formatDate(job.requested_at) }}</td><td class="p-3">{{ job.last_error_code || '—' }}</td></tr>
                            <tr v-if="!(live?.jobs ?? status?.jobs ?? []).length"><td colspan="7" class="p-6 text-center text-slate-500">Belum ada sinkronisasi untuk toko ini.</td></tr>
                        </tbody>
                    </table>
                </div>
            </template>
            <details class="text-xs text-slate-500 leading-6"><summary class="cursor-pointer font-bold">Perilaku sinkronisasi</summary><p>Produk mencakup varian, harga dan stok; jumlah tersimpan dihitung per produk, bukan per SKU. Order mencakup seluruh status. Keuangan dibaca per order yang telah tersimpan. Data kosong tetap nol. API tanpa izin atau gagal tidak dianggap selesai. Pernyataan transaksi bukan bukti uang sudah masuk bank; saldo rekening dan stok Konveksi tidak diubah. HPP internal dipertahankan. Retur/refund disimpan sebagai sumber terpisah untuk pemeriksaan, tanpa menebak status penyelesaian order. Iklan memerlukan konektor iklan terpisah dan tidak termasuk API toko ini. Worker server tetap berjalan setelah halaman ditutup. Tombol Jeda Pemantauan hanya menghentikan pembaruan progres pada halaman ini.</p></details>
        </section>

        <section v-if="preview" class="bg-white border rounded-2xl p-5 mt-5 space-y-4">
            <div><h2 class="font-extrabold text-xl">Jadwal Otomatis</h2><p class="text-xs text-slate-500 mt-2">Penjadwal server menambahkan satu pekerjaan per jadwal yang jatuh tempo, tanpa tumpang tindih.<span v-if="!scheduler_available"> Penjadwal belum tersedia di server ini.</span> Hanya administrator yang dapat mengubahnya.</p></div>
            <ul class="divide-y">
                <li v-for="state in autoRows" :key="state.mode" class="py-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="text-xs">
                        <strong class="text-sm">{{ AUTO_MODES[state.mode]?.title ?? state.mode }}</strong>
                        <span class="ml-2 px-2 py-1 rounded-full" :class="state.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ state.enabled ? 'Aktif' : 'Nonaktif' }}</span>
                        <p class="text-slate-500 mt-1">{{ AUTO_MODES[state.mode]?.every }} · {{ AUTO_MODES[state.mode]?.desc }}</p>
                        <p v-if="state.enabled" class="text-slate-400 mt-1">Berhasil terakhir: {{ formatDate(state.last_success_at) }} · Berikutnya: {{ formatDate(state.next_run_at) }}</p>
                        <p v-if="state.last_error_code" class="text-amber-700 mt-1">Galat terakhir: {{ state.last_error_code }}</p>
                    </div>
                    <button class="rounded-xl border px-4 py-2 text-xs font-bold" :class="state.enabled ? 'bg-white' : 'bg-brand-600 text-white'" type="button" role="switch" :aria-checked="state.enabled" :disabled="busy || !can_manage" @click="toggleAuto(state)">{{ state.enabled ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                </li>
            </ul>
            <p class="text-xs text-slate-500"><b>Editor harga:</b> belum aktif. Mengubah harga di marketplace membutuhkan konektor resmi dan persetujuan penulisan ke provider, yang belum tersedia.</p>
        </section>
    </MarketplaceShell>
</template>
