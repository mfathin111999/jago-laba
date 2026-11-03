<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import DataTable, { type Column } from '../../Components/DataTable.vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatDate, formatValue } from '../../lib/format';

interface Connection {
    status: string | null;
    last_successful_sync_at: string | null;
}
interface Store {
    id: string;
    platform: string;
    store_name: string;
    store_identifier: string | null;
    status: string | null;
    authorization_status: string | null;
    sync_mode: string | null;
    default_admin_fee_pct: number | string | null;
    default_free_shipping_pct: number | string | null;
    default_payment_fee_pct: number | string | null;
    last_synced_at: string | null;
    connection: Connection | null;
}
interface ArchivedStore {
    id: string;
    platform: string | null;
    store_name: string | null;
    archived_at: string;
    archived_by: string | null;
}
interface Deletion {
    store_id: string;
    platform: string;
    status: string;
    requested_at: string;
    completed_at: string | null;
    error_code: string | null;
}
interface DeletionPreview {
    store_id: string;
    name: string;
    platform: string;
    environment: string;
    counts: Record<string, number>;
    file_count: number;
}

const props = defineProps<{
    stores: Store[];
    archived: ArchivedStore[];
    scope: { company_key: string; store_id: string | null };
    can_manage: boolean;
    can_delete: boolean;
    deletions: Deletion[];
    deletion_ready: boolean;
}>();

const PLATFORMS = ['Shopee', 'TikTok Shop', 'Tokopedia', 'Lazada'];

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({ message: null, kind: 'success' });
const busy = ref(false);
const formError = ref<string | null>(null);

function notify(message: string, kind: 'success' | 'error' = 'success') {
    flash.message = message;
    flash.kind = kind;
}

function refresh() {
    router.reload({ only: ['stores', 'archived', 'deletions'] });
}

/** Runs one action: shows the server's own message, never reports success for a refusal. */
async function run(action: () => Promise<string>, onDone?: () => void) {
    busy.value = true;
    formError.value = null;
    try {
        notify(await action());
        onDone?.();
        refresh();
    } catch (error) {
        const message = error instanceof ApiError ? error.message : 'Terjadi kesalahan tak terduga.';
        formError.value = message;
        notify(message, 'error');
    } finally {
        busy.value = false;
    }
}

// ----- add / edit -----
const editor = reactive({ open: false, mode: 'create' as 'create' | 'edit', id: '' });
const form = reactive({
    store_name: '',
    platform: 'Shopee',
    store_identifier: '',
    default_admin_fee_pct: '11',
    default_free_shipping_pct: '4',
    default_payment_fee_pct: '1',
});

function openCreate() {
    Object.assign(form, { store_name: '', platform: 'Shopee', store_identifier: '', default_admin_fee_pct: '11', default_free_shipping_pct: '4', default_payment_fee_pct: '1' });
    Object.assign(editor, { open: true, mode: 'create', id: '' });
    formError.value = null;
}

function openEdit(store: Store) {
    Object.assign(form, {
        store_name: store.store_name,
        platform: store.platform,
        store_identifier: store.store_identifier ?? '',
        default_admin_fee_pct: String(store.default_admin_fee_pct ?? '11'),
        default_free_shipping_pct: String(store.default_free_shipping_pct ?? '4'),
        default_payment_fee_pct: String(store.default_payment_fee_pct ?? '1'),
    });
    Object.assign(editor, { open: true, mode: 'edit', id: store.id });
    formError.value = null;
}

function saveStore() {
    const payload = { ...form };
    void run(async () => {
        const result =
            editor.mode === 'create'
                ? await apiRequest('/pilot/marketplace/stores', payload)
                : await apiRequest(`/pilot/marketplace/stores/${encodeURIComponent(editor.id)}`, payload, { method: 'PUT' });

        return result.message ?? 'Toko tersimpan.';
    }, () => (editor.open = false));
}

// ----- simple confirmations (disable, archive, restore) -----
const confirmation = reactive<{ open: boolean; title: string; body: string; danger: boolean; action: (() => Promise<string>) | null }>({
    open: false,
    title: '',
    body: '',
    danger: false,
    action: null,
});

function ask(title: string, body: string, action: () => Promise<string>, danger = false) {
    Object.assign(confirmation, { open: true, title, body, danger, action });
    formError.value = null;
}

const storeUrl = (id: string, suffix = '') => `/pilot/marketplace/stores/${encodeURIComponent(id)}${suffix}`;

function disable(store: Store) {
    ask('Nonaktifkan toko', `Toko "${store.store_name}" ditandai nonaktif. Data dan riwayat tidak dihapus.`, async () => {
        const result = await apiRequest(storeUrl(store.id, '/status'), { status: 'Nonaktif' }, { method: 'PATCH' });
        return result.message ?? 'Status toko diperbarui.';
    });
}

function archive(store: Store) {
    ask('Arsipkan toko', `Toko "${store.store_name}" dikeluarkan dari daftar aktif dan koneksinya diputus. Riwayat tetap tersimpan dan toko bisa dipulihkan.`, async () => {
        const result = await apiRequest(storeUrl(store.id, '/archive'), {});
        return result.message ?? 'Toko diarsipkan.';
    });
}

function restore(store: ArchivedStore) {
    ask('Pulihkan toko', `Toko "${store.store_name ?? store.id}" kembali ke daftar aktif dalam keadaan terputus. Hubungkan ulang lewat otorisasi resmi.`, async () => {
        const result = await apiRequest(storeUrl(store.id, '/restore'), {});
        return result.message ?? 'Toko dipulihkan.';
    });
}

function confirmNow() {
    const action = confirmation.action;
    if (action) {
        void run(action, () => (confirmation.open = false));
    }
}

// ----- permanent deletion (owner only) -----
const erase = reactive<{ open: boolean; storeId: string; storeName: string; preview: DeletionPreview | null; typed: string; polling: boolean }>({
    open: false,
    storeId: '',
    storeName: '',
    preview: null,
    typed: '',
    polling: false,
});
let pollTimer: ReturnType<typeof setInterval> | null = null;

const deletionCall = (body: Record<string, unknown>) => apiRequest('/api/marketplace-store-deletion.php', body);
const confirmationMatches = computed(() => erase.preview !== null && erase.typed.trim() === erase.preview.name);

async function openErase(id: string, name: string) {
    Object.assign(erase, { open: true, storeId: id, storeName: name, preview: null, typed: '', polling: false });
    formError.value = null;
    busy.value = true;
    try {
        erase.preview = ((await deletionCall({ action: 'preview', store_id: id })).data ?? null) as DeletionPreview | null;
    } catch (error) {
        formError.value = error instanceof ApiError ? error.message : 'Pratinjau gagal dimuat.';
    } finally {
        busy.value = false;
    }
}

function requestErase() {
    void run(async () => {
        const result = await deletionCall({ action: 'request', store_id: erase.storeId, confirmation: erase.typed.trim() });
        startPolling();

        return result.data?.status === 'completed' ? 'Toko dihapus permanen.' : 'Permintaan penghapusan diterima. Pembersihan data berjalan bertahap di server.';
    });
}

function startPolling() {
    stopPolling();
    erase.polling = true;
    pollTimer = setInterval(async () => {
        try {
            const result = await deletionCall({ action: 'status', store_id: erase.storeId });
            refresh();
            if (['completed', 'failed'].includes(String(result.data?.status))) {
                stopPolling();
            }
        } catch {
            stopPolling();
        }
    }, 4000);
}

function stopPolling() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    erase.polling = false;
}

function closeErase() {
    stopPolling();
    erase.open = false;
}

onBeforeUnmount(stopPolling);

// ----- display -----
const storeColumns: Column[] = [
    { key: 'store_name', label: 'Toko' },
    { key: 'platform', label: 'Platform' },
    { key: 'store_identifier', label: 'ID di marketplace' },
    { key: 'connection', label: 'Koneksi' },
    { key: 'last_sync', label: 'Sinkron terakhir' },
    { key: 'fees', label: 'Biaya bawaan' },
];
const archivedColumns: Column[] = [
    { key: 'store_name', label: 'Toko' },
    { key: 'platform', label: 'Platform' },
    { key: 'archived_at', label: 'Diarsipkan', kind: 'date' },
    { key: 'archived_by', label: 'Oleh' },
];
const deletionColumns: Column[] = [
    { key: 'store_id', label: 'Toko' },
    { key: 'platform', label: 'Platform' },
    { key: 'status', label: 'Status' },
    { key: 'requested_at', label: 'Diminta', kind: 'date' },
];

function connection(store: Store): { label: string; tone: 'ok' | 'warn' | 'muted' } {
    if (store.status === 'Nonaktif') return { label: 'Nonaktif', tone: 'muted' };
    if (store.connection?.status === 'verified') return { label: 'Terverifikasi', tone: 'ok' };

    return { label: 'Belum terhubung', tone: 'warn' };
}

const deletionTone = (status: string): 'ok' | 'warn' | 'danger' | 'muted' =>
    status === 'completed' ? 'ok' : status === 'failed' ? 'danger' : 'warn';
const deletionLabel = (status: string): string =>
    ({ pending: 'Menunggu', running: 'Berjalan', completed: 'Selesai', failed: 'Gagal, akan dicoba ulang' })[status] ?? status;
const fees = (store: Store): string =>
    [store.default_admin_fee_pct, store.default_free_shipping_pct, store.default_payment_fee_pct].map((value) => formatValue(value, 'percent')).join(' · ');
</script>

<template>
    <MarketplaceShell active="stores" title="Toko" eyebrow="Marketplace / Pengaturan" subtitle="Kelola toko yang terhubung. Koneksi hanya dibuat lewat otorisasi resmi marketplace." :company-key="scope.company_key">
        <template #actions>
            <button v-if="can_manage" type="button" class="button button-primary" @click="openCreate">Tambah toko</button>
        </template>

        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <div class="section-stack">
            <section class="panel">
                <div class="section-heading compact"><div><span class="eyebrow">Aktif</span><h2>Toko aktif ({{ stores.length }})</h2></div></div>
                <DataTable :columns="storeColumns" :rows="stores" row-key="id" empty="Belum ada toko. Tambahkan toko lalu hubungkan lewat otorisasi marketplace.">
                    <template #cell-store_name="{ row }"><strong>{{ row.store_name }}</strong></template>
                    <template #cell-connection="{ row }"><StatusBadge :label="connection(row).label" :tone="connection(row).tone" /></template>
                    <template #cell-last_sync="{ row }">{{ formatDate(row.connection?.last_successful_sync_at ?? row.last_synced_at) }}</template>
                    <template #cell-fees="{ row }">{{ fees(row) }}</template>
                    <template v-if="can_manage || can_delete" #actions="{ row }">
                        <button v-if="can_manage" type="button" class="button button-secondary button-small" @click="openEdit(row)">Ubah</button>
                        <button v-if="can_manage && row.status !== 'Nonaktif'" type="button" class="button button-secondary button-small" @click="disable(row)">Nonaktifkan</button>
                        <button v-if="can_manage" type="button" class="button button-secondary button-small" @click="archive(row)">Arsipkan</button>
                        <button v-if="can_delete && deletion_ready" type="button" class="button button-danger button-small" @click="openErase(row.id, row.store_name)">Hapus permanen</button>
                    </template>
                </DataTable>
            </section>

            <section class="panel">
                <div class="section-heading compact"><div><span class="eyebrow">Arsip</span><h2>Toko diarsipkan ({{ archived.length }})</h2></div></div>
                <DataTable :columns="archivedColumns" :rows="archived" row-key="id" empty="Tidak ada toko yang diarsipkan.">
                    <template v-if="can_manage || can_delete" #actions="{ row }">
                        <button v-if="can_manage" type="button" class="button button-secondary button-small" @click="restore(row)">Pulihkan</button>
                        <button v-if="can_delete && deletion_ready" type="button" class="button button-danger button-small" @click="openErase(row.id, row.store_name ?? row.id)">Hapus permanen</button>
                    </template>
                </DataTable>
            </section>

            <section v-if="can_delete && deletions.length > 0" class="panel">
                <div class="section-heading compact"><div><span class="eyebrow">Penghapusan permanen</span><h2>Riwayat penghapusan</h2></div></div>
                <DataTable :columns="deletionColumns" :rows="deletions" row-key="store_id">
                    <template #cell-status="{ row }"><StatusBadge :label="deletionLabel(row.status)" :tone="deletionTone(row.status)" /></template>
                </DataTable>
            </section>

            <p v-if="can_delete && !deletion_ready" class="muted">Penghapusan permanen belum aktif: administrator server perlu menjalankan <code>php artisan marketplace:store-deletion --install</code> sekali.</p>
        </div>

        <Modal :open="editor.open" :title="editor.mode === 'create' ? 'Tambah toko' : 'Ubah toko'" :busy="busy" @close="editor.open = false">
            <form class="form-stack" @submit.prevent="saveStore">
                <div class="field"><label for="store-name">Nama toko</label><input id="store-name" v-model="form.store_name" required maxlength="150" /></div>
                <div class="field"><label for="store-platform">Platform</label><select id="store-platform" v-model="form.platform"><option v-for="platform in PLATFORMS" :key="platform" :value="platform">{{ platform }}</option></select></div>
                <div class="field"><label for="store-identifier">ID toko di marketplace (opsional)</label><input id="store-identifier" v-model="form.store_identifier" maxlength="100" /></div>
                <div class="grid grid-3">
                    <div class="field"><label for="fee-admin">Biaya admin %</label><input id="fee-admin" v-model="form.default_admin_fee_pct" type="number" min="0" max="100" step="0.1" /></div>
                    <div class="field"><label for="fee-ship">Gratis ongkir %</label><input id="fee-ship" v-model="form.default_free_shipping_pct" type="number" min="0" max="100" step="0.1" /></div>
                    <div class="field"><label for="fee-pay">Biaya bayar %</label><input id="fee-pay" v-model="form.default_payment_fee_pct" type="number" min="0" max="100" step="0.1" /></div>
                </div>
                <p v-if="editor.mode === 'edit'" class="hint">Menyimpan perubahan menandai toko "Terputus" sampai diverifikasi ulang lewat otorisasi resmi.</p>
                <p v-if="formError" class="inline-error" role="alert">{{ formError }}</p>
                <div class="form-actions">
                    <button type="button" class="button button-secondary" :disabled="busy" @click="editor.open = false">Batal</button>
                    <button type="submit" class="button button-primary" :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </Modal>

        <Modal :open="confirmation.open" :title="confirmation.title" :busy="busy" @close="confirmation.open = false">
            <p>{{ confirmation.body }}</p>
            <p v-if="formError" class="inline-error" role="alert">{{ formError }}</p>
            <div class="form-actions">
                <button type="button" class="button button-secondary" :disabled="busy" @click="confirmation.open = false">Batal</button>
                <button type="button" class="button" :class="confirmation.danger ? 'button-danger' : 'button-primary'" :disabled="busy" @click="confirmNow">{{ busy ? 'Memproses…' : 'Lanjutkan' }}</button>
            </div>
        </Modal>

        <Modal :open="erase.open" title="Hapus toko permanen" :busy="busy" @close="closeErase">
            <div class="form-stack">
                <p><strong>Tindakan ini tidak dapat dibatalkan.</strong> Seluruh data lokal toko "{{ erase.storeName }}" akan dihapus. Marketplace tidak dihubungi.</p>
                <template v-if="erase.preview">
                    <p class="muted">Lingkungan: {{ erase.preview.environment }} · Berkas: {{ erase.preview.file_count }}</p>
                    <ul class="data-list">
                        <li v-for="(count, table) in erase.preview.counts" :key="table"><span>{{ table }}</span> <strong>{{ formatValue(count, 'count') }}</strong></li>
                    </ul>
                    <div class="field"><label for="erase-name">Ketik nama toko untuk konfirmasi</label><input id="erase-name" v-model="erase.typed" autocomplete="off" :placeholder="erase.preview.name" /></div>
                </template>
                <p v-if="erase.polling" class="muted" role="status">Memantau proses penghapusan…</p>
                <p v-if="formError" class="inline-error" role="alert">{{ formError }}</p>
                <div class="form-actions">
                    <button type="button" class="button button-secondary" :disabled="busy" @click="closeErase">Tutup</button>
                    <button type="button" class="button button-danger" :disabled="busy || !confirmationMatches || erase.polling" @click="requestErase">Hapus permanen</button>
                </div>
            </div>
        </Modal>
    </MarketplaceShell>
</template>
