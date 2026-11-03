<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DataTable, { type Column } from '../../Components/DataTable.vue';
import FlashBanner from '../../Components/FlashBanner.vue';
import MarketplaceShell from '../../Components/MarketplaceShell.vue';
import Modal from '../../Components/Modal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { ApiError, apiRequest } from '../../lib/api';
import { formatDate, formatText } from '../../lib/format';

type Row = Record<string, any>;

const props = defineProps<{
    stores: Row[];
    scope: { company_key: string; store_id: string | null };
    health: Row | null;
    health_error: string | null;
    sync: Row | null;
    platforms: Row[];
    oauth: { status: string; message: string; pending_id: string | null; platform: string | null; shops: Array<{ id: string; name: string }> } | null;
    branding: { has_logo: boolean; logo_size: number; logo_fit: string; updated_at: string | null };
    can_manage: boolean;
    can_sync: boolean;
    can_brand: boolean;
}>();

const flash = reactive<{ message: string | null; kind: 'success' | 'error' }>({
    message: props.oauth && props.oauth.status !== 'choose' ? props.oauth.message || (props.oauth.status === 'success' ? 'Toko berhasil dihubungkan.' : null) : null,
    kind: props.oauth?.status === 'error' ? 'error' : 'success',
});
const busy = ref(false);

function notify(message: string, kind: 'success' | 'error' = 'success'): void {
    flash.message = message;
    flash.kind = kind;
}

/** Runs one action and shows the server's own message; a refusal is never reported as success. */
async function run(action: () => Promise<string>, reload: string[] = ['health', 'sync', 'stores']): Promise<boolean> {
    busy.value = true;
    try {
        notify(await action());
        router.reload({ only: reload });

        return true;
    } catch (error) {
        notify(error instanceof ApiError ? error.message : 'Terjadi kesalahan tak terduga.', 'error');

        return false;
    } finally {
        busy.value = false;
    }
}

function chooseStore(event: Event): void {
    const id = (event.target as HTMLSelectElement).value;
    router.get('/marketplace/integrations', { store_id: id });
}

// ----- connection -----
const storeId = computed(() => props.scope.store_id ?? '');
const platform = computed(() => String(props.health?.platform ?? ''));
const connected = computed(() => props.health?.connection_status === 'verified');
const connectionTone = computed(() => (connected.value ? 'ok' : props.health?.connection_status === 'reauthorize' ? 'warn' : 'muted'));

async function connect(): Promise<void> {
    await run(async () => {
        const result = await apiRequest('/pilot/marketplace/oauth/start', { platform: platform.value, return_path: 'marketplace/integrations' });
        const url = result.data?.authorization_url;
        if (typeof url !== 'string' || url === '') throw new ApiError('Alamat otorisasi tidak tersedia.', 502);
        window.location.href = url;

        return 'Mengalihkan ke halaman otorisasi…';
    }, []);
}

const refreshToken = () => run(async () => (await apiRequest('/pilot/marketplace/oauth/refresh', { store_id: storeId.value })).message ?? 'Token disegarkan.');
const syncNow = () => run(async () => (await apiRequest('/api/marketplace-oauth.php', { action: 'sync', store_id: storeId.value })).message ?? 'Sinkronisasi masuk antrean.');
const retryJob = (jobId: number) => run(async () => (await apiRequest('/api/marketplace-integration-status.php', { action: 'retry', store_id: storeId.value, job_id: jobId })).message ?? 'Pekerjaan dijadwalkan ulang.');

const confirmDisconnect = ref(false);
async function disconnect(): Promise<void> {
    await run(async () => (await apiRequest('/api/marketplace-oauth.php', { action: 'disconnect', store_id: storeId.value })).message ?? 'Koneksi diputus.');
    confirmDisconnect.value = false;
}

async function pickShop(externalId: string): Promise<void> {
    if (!props.oauth?.pending_id) return;
    const ok = await run(async () => (await apiRequest('/api/marketplace-oauth.php', { action: 'select', pending_id: props.oauth!.pending_id, external_store_id: externalId })).message ?? 'Toko dihubungkan.');
    if (ok) router.get('/marketplace/integrations', { store_id: storeId.value }, { replace: true });
}

// ----- display -----
const STATE_TONE: Record<string, 'ok' | 'warn' | 'danger' | 'muted' | 'info'> = {
    available: 'ok', unverified: 'muted', permission: 'warn', unsupported: 'muted', temporary: 'warn', reauthorize: 'danger',
};
const resourceColumns: Column[] = [
    { key: 'label', label: 'Sumber data' },
    { key: 'state', label: 'Status' },
    { key: 'last_verified_at', label: 'Terakhir terverifikasi', kind: 'date' },
    { key: 'sync', label: 'Sinkronisasi' },
];
const jobColumns: Column[] = [
    { key: 'id', label: 'ID', kind: 'count' },
    { key: 'job_type', label: 'Jenis' },
    { key: 'status', label: 'Status' },
    { key: 'processed', label: 'Diproses', kind: 'count', align: 'right' },
    { key: 'requested_at', label: 'Diminta', kind: 'date' },
    { key: 'last_error_code', label: 'Kode galat' },
];

// ----- branding -----
const brand = reactive({ logo_size: props.branding.logo_size, logo_fit: props.branding.logo_fit });
const logoFile = ref<File | null>(null);
const logoError = ref<string | null>(null);
const MAX_LOGO = 2 * 1024 * 1024;

function onLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    logoError.value = file && file.size > MAX_LOGO ? 'Pilih gambar maksimal 2 MB.' : null;
    logoFile.value = logoError.value ? null : file;
}

async function saveBranding(reset = false): Promise<void> {
    const body = new FormData();
    body.set('logo_size', String(brand.logo_size));
    body.set('logo_fit', brand.logo_fit);
    if (reset) body.set('reset_logo', '1');
    else if (logoFile.value) body.set('logo', logoFile.value);
    const ok = await run(async () => (await apiRequest('/api/marketplace-branding.php', body)).message ?? 'Identitas tersimpan.', ['branding']);
    if (ok) logoFile.value = null;
}
const logoUrl = computed(() => (props.branding.has_logo ? `/api/marketplace-branding.php?image=1&v=${encodeURIComponent(props.branding.updated_at ?? '')}` : null));
</script>

<template>
    <MarketplaceShell
        active="integrations"
        title="Integrasi"
        eyebrow="Marketplace / Integrasi"
        subtitle="Koneksi toko ke marketplace, kesehatan sumber data, dan identitas perusahaan."
        :store-id="scope.store_id"
        :company-key="scope.company_key"
    >
        <FlashBanner :message="flash.message" :kind="flash.kind" @dismiss="flash.message = null" />

        <section class="panel notice">
            <strong>Mode sandbox.</strong> <span class="muted">Koneksi saat ini memakai provider uji. Server tidak memanggil marketplace Live, dan data pesanan belum diambil dari provider.</span>
        </section>

        <section v-if="oauth?.status === 'choose' && oauth.shops.length" class="panel">
            <h2>Pilih toko yang akan dihubungkan{{ oauth.platform ? ` (${oauth.platform})` : '' }}</h2>
            <div class="form-actions">
                <button v-for="shop in oauth.shops" :key="shop.id" class="button button-secondary" type="button" :disabled="busy" @click="pickShop(shop.id)">{{ shop.name }}</button>
            </div>
        </section>

        <div class="panel filter-bar">
            <div class="field filter-store">
                <label for="i-store">Toko</label>
                <select id="i-store" :value="storeId" @change="chooseStore"><option value="" disabled>Pilih toko</option><option v-for="store in stores" :key="store.id" :value="store.id">{{ store.store_name || store.id }}</option></select>
            </div>
        </div>

        <section v-if="!scope.store_id" class="panel empty-state"><h2>Belum ada toko</h2><p class="muted">Tambahkan toko di menu Toko, lalu hubungkan di sini.</p></section>
        <section v-else-if="health_error" class="panel empty-state"><h2>Toko tidak dapat dimuat</h2><p class="muted">{{ health_error }}</p></section>

        <template v-else-if="health">
            <section class="panel">
                <div class="section-heading compact">
                    <div>
                        <span class="eyebrow">{{ health.platform }} · {{ health.environment_label }}</span>
                        <h2>{{ health.store_name }} <StatusBadge :label="health.connection_label" :tone="connectionTone" /></h2>
                        <p class="muted">Sinkron berhasil terakhir: {{ formatDate(health.last_successful_sync_at) }}<span v-if="health.token_expired"> · <span class="warn-text">Token kedaluwarsa</span></span></p>
                    </div>
                    <div class="form-actions">
                        <button v-if="can_manage && !connected" class="button button-primary" type="button" :disabled="busy" @click="connect">Hubungkan {{ platform }}</button>
                        <button v-if="can_manage && connected" class="button button-secondary" type="button" :disabled="busy" @click="refreshToken">Segarkan token</button>
                        <button v-if="can_sync && connected" class="button button-secondary" type="button" :disabled="busy" @click="syncNow">Sinkronkan sekarang</button>
                        <button v-if="can_manage && connected" class="button button-danger" type="button" :disabled="busy" @click="confirmDisconnect = true">Putuskan</button>
                    </div>
                </div>
                <p v-if="!can_manage" class="muted">Hanya administrator yang dapat menghubungkan atau memutus toko.</p>
            </section>

            <section class="panel">
                <h2>Kesehatan sumber data</h2>
                <DataTable :columns="resourceColumns" :rows="health.resources" row-key="key" empty="Belum ada sumber data.">
                    <template #cell-state="{ row }">
                        <StatusBadge :label="row.state_label" :tone="STATE_TONE[row.state] ?? 'muted'" />
                        <div v-if="row.error_code" class="muted cell-note">{{ row.error_code }}</div>
                    </template>
                    <template #cell-sync="{ row }">{{ row.sync_label }}<div v-if="row.processed" class="muted cell-note">{{ row.processed }} diproses</div></template>
                    <template #actions="{ row }"><button v-if="can_manage && row.retry_job_id" class="button button-secondary button-small" type="button" :disabled="busy" @click="retryJob(row.retry_job_id)">Coba ulang</button></template>
                </DataTable>
            </section>

            <section class="panel">
                <h2>Antrean sinkronisasi</h2>
                <p class="muted">{{ sync?.pending ?? 0 }} menunggu · {{ sync?.failed ?? 0 }} gagal</p>
                <DataTable :columns="jobColumns" :rows="sync?.jobs ?? []" row-key="id" empty="Belum ada sinkronisasi.">
                    <template #cell-status="{ row }"><StatusBadge :label="formatText(row.status)" :tone="row.status === 'failed' ? 'danger' : row.status === 'succeeded' ? 'ok' : 'info'" /></template>
                    <template #cell-last_error_code="{ row }">{{ formatText(row.last_error_code) }}</template>
                </DataTable>
            </section>
        </template>

        <section class="panel">
            <h2>Identitas dan logo</h2>
            <p class="muted">Logo tampil di sidebar semua pengguna Marketplace perusahaan ini.</p>
            <img v-if="logoUrl" :src="logoUrl" alt="Logo perusahaan" class="brand-preview" />
            <p v-else class="muted">Logo belum diunggah.</p>
            <form v-if="can_brand" class="form-grid" @submit.prevent="saveBranding(false)">
                <div class="field field-wide"><label for="b-file">Logo (PNG, JPG, atau WebP, maks 2 MB)</label><input id="b-file" type="file" accept="image/png,image/jpeg,image/webp" @change="onLogo" /></div>
                <div class="field"><label for="b-size">Ukuran</label><select id="b-size" v-model.number="brand.logo_size"><option v-for="size in [28, 32, 36, 44, 52]" :key="size" :value="size">{{ size }} px</option></select></div>
                <div class="field"><label for="b-fit">Penyesuaian</label><select id="b-fit" v-model="brand.logo_fit"><option value="contain">Utuh (contain)</option><option value="cover">Penuh (cover)</option></select></div>
                <p v-if="logoError" class="inline-error field-wide" role="alert">{{ logoError }}</p>
                <div class="form-actions field-wide">
                    <button v-if="branding.has_logo" class="button button-secondary" type="button" :disabled="busy" @click="saveBranding(true)">Hapus logo</button>
                    <button class="button button-primary" type="submit" :disabled="busy">Simpan</button>
                </div>
            </form>
            <p v-else class="muted">Hanya Owner atau Admin yang dapat mengubah logo.</p>
        </section>

        <Modal :open="confirmDisconnect" title="Putuskan koneksi toko?" :busy="busy" @close="confirmDisconnect = false">
            <p>Token toko akan dihapus. Data pesanan yang sudah tersimpan tidak ikut terhapus. Untuk menyambung lagi, toko perlu diotorisasi ulang.</p>
            <div class="form-actions">
                <button class="button button-secondary" type="button" @click="confirmDisconnect = false">Batal</button>
                <button class="button button-danger" type="button" :disabled="busy" @click="disconnect">Putuskan</button>
            </div>
        </Modal>
    </MarketplaceShell>
</template>
