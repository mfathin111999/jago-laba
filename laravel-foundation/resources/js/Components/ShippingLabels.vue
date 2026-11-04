<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ApiError, apiRequest } from '../lib/api';
import Modal from './Modal.vue';

type Row = Record<string, any>;

const props = defineProps<{ open: boolean; storeId: string; orderIds: string[]; sellerCenter?: string | null; canFulfill: boolean }>();
const emit = defineEmits<{ (event: 'close'): void }>();

/** Codes after which the marketplace is still preparing the label; the legacy screen re-checks up to 3 times. */
const NOT_READY = ['TIKTOK_API_11034037', 'TIKTOK_API_11034023', 'DOCUMENT_NOT_READY'];
/** Codes that mean the package has no shipping arrangement yet, so the user can arrange it from here. */
const NEEDS_ARRANGEMENT = ['TIKTOK_API_21023035', 'TIKTOK_API_21042104'];

interface OrderState { id: string; status: 'loading' | 'done' | 'error'; message: string; documents: Row[] }

const busy = ref(false);
const orders = ref<OrderState[]>([]);
const arrange = ref<{ orderId: string; packageId: string } | null>(null);
const packages = ref<Row[]>([]);
const arrangeState = ref<{ loading: boolean; error: string | null }>({ loading: false, error: null });
const forms = reactive<Record<string, { method: string; slot: string; consent: boolean; message: string; busy: boolean }>>({});

const href = (url: string): string => (url.startsWith('/') ? url : `/${url}`);
const kb = (bytes: number): number => Math.ceil(Number(bytes) / 1024);
const wib = (stamp: number): string => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(stamp * 1000));
const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
const messageOf = (error: unknown, fallback: string): string => (error instanceof ApiError ? error.message : fallback);

async function fetchDocuments(orderId: string): Promise<Row[]> {
    let result = await apiRequest('/api/marketplace-shipping-document.php', { store_id: props.storeId, order_id: orderId });
    for (let attempt = 0; attempt < 3 && (result.documents ?? []).some((doc: Row) => !doc.success && NOT_READY.includes(doc.code)); attempt += 1) {
        await wait(2000 * (attempt + 1));
        result = await apiRequest('/api/marketplace-shipping-document.php', { store_id: props.storeId, order_id: orderId });
    }

    return result.documents ?? [];
}

async function loadLabel(state: OrderState): Promise<void> {
    state.status = 'loading';
    state.message = 'Mengambil dokumen…';
    try {
        state.documents = await fetchDocuments(state.id);
        state.status = 'done';
        state.message = '';
    } catch (error) {
        state.status = 'error';
        state.message = messageOf(error, 'Dokumen belum dapat diambil.');
    }
}

async function loadAll(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    arrange.value = null;
    orders.value = props.orderIds.slice(0, 20).map((id) => ({ id, status: 'loading', message: 'Mengambil dokumen…', documents: [] }));
    try {
        for (const state of orders.value) await loadLabel(state);
    } finally {
        busy.value = false;
    }
}

watch(() => props.open, (open) => {
    if (open) void loadAll();
}, { immediate: true });

// ----- arranging shipment -----
async function loadOptions(orderId: string, packageId: string): Promise<void> {
    arrange.value = { orderId, packageId };
    arrangeState.value = { loading: true, error: null };
    packages.value = [];
    try {
        const result = await apiRequest('/api/marketplace-fulfillment.php', { action: 'options', store_id: props.storeId, order_id: orderId, package_id: packageId });
        packages.value = result.data?.packages ?? [];
        packages.value.forEach((pack) => {
            forms[pack.package_id] ??= { method: '', slot: '', consent: false, message: '', busy: false };
        });
        arrangeState.value = { loading: false, error: null };
    } catch (error) {
        arrangeState.value = { loading: false, error: messageOf(error, 'Pengiriman belum dapat diproses.') };
    }
}

const slotsFor = (pack: Row): Row[] => (pack.methods ?? []).find((method: Row) => method.id === forms[pack.package_id]?.method)?.slots ?? [];

async function confirm(pack: Row): Promise<void> {
    const form = forms[pack.package_id];
    if (!arrange.value || !form || !form.consent || form.method === '' || (form.method === 'PICKUP' && form.slot === '')) return;
    form.busy = true;
    form.message = 'Mengatur pengiriman di server. Halaman boleh ditutup; periksa status saat dibuka kembali.';
    try {
        const result = await apiRequest('/api/marketplace-fulfillment.php', {
            action: 'confirm', confirmed: '1', store_id: props.storeId, order_id: arrange.value.orderId, package_id: pack.package_id,
            offer_token: pack.offer_token, method: form.method, slot_start: form.slot,
        });
        const outcome = result.data ?? {};
        form.message = outcome.confirmed ? 'Pengiriman dikonfirmasi marketplace. Menyiapkan PDF…' : (outcome.message ?? 'Pengiriman belum dikonfirmasi.');
        if (outcome.confirmed) {
            const target = orders.value.find((item) => item.id === arrange.value?.orderId);
            arrange.value = null;
            if (target) await loadLabel(target);
        }
    } catch (error) {
        form.message = `${messageOf(error, 'Pengiriman belum dapat diproses.')} Jika koneksi terputus, periksa status sebelum mencoba kembali.`;
    } finally {
        form.busy = false;
    }
}

const title = computed(() => (arrange.value ? 'Atur Pengiriman di Jago Laba' : 'Resi + Daftar Pengemasan'));
const allDone = computed(() => !busy.value && orders.value.length > 0);
</script>

<template>
    <Modal :open="open" :title="title" :busy="busy" @close="emit('close')">
        <template v-if="arrange">
            <p v-if="arrangeState.loading" class="muted">Membaca status, metode, dan jadwal dari marketplace…</p>
            <div v-else-if="arrangeState.error" class="form-stack">
                <p class="warn-text" role="alert">{{ arrangeState.error }}</p>
                <div class="form-actions"><button class="button button-secondary" type="button" @click="loadOptions(arrange.orderId, arrange.packageId)">Coba lagi</button></div>
            </div>
            <div v-else class="form-stack">
                <section v-for="pack in packages" :key="pack.package_id" class="panel">
                    <strong>Paket {{ pack.package_id }}</strong>
                    <template v-if="pack.ready">
                        <p>Pengiriman sudah diatur. Ambil dokumen tanpa mengatur ulang.</p>
                        <button class="button button-primary" type="button" @click="arrange = null; loadAll()">Ambil resi + daftar pengemasan</button>
                    </template>
                    <template v-else-if="pack.error || pack.pending">
                        <p class="warn-text">{{ pack.message }}</p>
                        <button class="button button-secondary" type="button" @click="loadOptions(arrange.orderId, arrange.packageId)">Periksa status / muat pilihan</button>
                    </template>
                    <form v-else class="form-stack" @submit.prevent="confirm(pack)">
                        <p v-if="pack.carrier || pack.service">Kurir / layanan: {{ [pack.carrier, pack.service].filter(Boolean).join(' - ') }}</p>
                        <p>Alamat pengiriman toko: {{ pack.warehouse_address || 'Tidak diberikan API. Menggunakan alamat paket yang terdaftar di marketplace.' }}</p>
                        <p v-if="(pack.affected_orders ?? []).length > 1" class="warn-text">Paket gabungan. Berlaku untuk pesanan: {{ pack.affected_orders.join(', ') }}</p>
                        <div class="field">
                            <label :for="`m-${pack.package_id}`">Metode pengiriman</label>
                            <select :id="`m-${pack.package_id}`" v-model="forms[pack.package_id].method" required>
                                <option value="">Pilih metode dari marketplace</option>
                                <option v-for="method in pack.methods" :key="method.id" :value="method.id">{{ method.name }}</option>
                            </select>
                        </div>
                        <div v-if="forms[pack.package_id].method === 'PICKUP'" class="field">
                            <label :for="`s-${pack.package_id}`">Jadwal pickup (WIB)</label>
                            <select :id="`s-${pack.package_id}`" v-model="forms[pack.package_id].slot" required>
                                <option value="">Pilih jadwal dari marketplace</option>
                                <option v-for="slot in slotsFor(pack)" :key="slot.start_time" :value="String(slot.start_time)">{{ wib(slot.start_time) }} - {{ wib(slot.end_time) }}</option>
                            </select>
                        </div>
                        <a v-if="pack.drop_off_point_url" class="button button-secondary button-small" :href="pack.drop_off_point_url" target="_blank" rel="noopener noreferrer">Lihat lokasi drop-off dari kurir</a>
                        <label class="checkbox-row"><input v-model="forms[pack.package_id].consent" type="checkbox" required /> Saya menyetujui pengaturan pengiriman paket ini di marketplace menggunakan metode dan jadwal yang dipilih.</label>
                        <div class="form-actions"><button class="button button-primary" type="submit" :disabled="forms[pack.package_id].busy">Konfirmasi pengiriman dan siapkan resi</button></div>
                        <p class="muted cell-note">Konfirmasi mengatur pengiriman di marketplace. Status Dikirim mengikuti penyerahan paket ke kurir.</p>
                        <p v-if="forms[pack.package_id].message" role="status">{{ forms[pack.package_id].message }}</p>
                    </form>
                </section>
                <div class="form-actions"><button class="button button-secondary" type="button" @click="arrange = null">Kembali ke daftar resi</button></div>
            </div>
        </template>

        <template v-else>
            <p class="muted">1. Validasi pesanan › 2. Atur pengiriman › 3. Siapkan dokumen › 4. Unduh PDF. Mengambil PDF resi beserta daftar pengemasan asli dari marketplace.</p>
            <div v-for="state in orders" :key="state.id" class="panel">
                <strong>Pesanan {{ state.id }}</strong>
                <p v-if="state.status === 'loading'" class="muted">{{ state.message }}</p>
                <p v-else-if="state.status === 'error'" class="warn-text" role="alert">{{ state.message }}</p>
                <div v-for="doc in state.documents" :key="doc.package_id" class="cell-note">
                    <p>Paket {{ doc.package_id }}</p>
                    <template v-if="doc.success">
                        <small class="muted">Resi + daftar pengemasan siap · {{ kb(doc.bytes) }} KB · tersedia 15 menit</small>
                        <div class="form-actions">
                            <a class="button button-primary button-small" :href="href(doc.download_url)">Unduh PDF</a>
                            <a class="button button-secondary button-small" :href="href(doc.preview_url)" target="_blank" rel="noopener noreferrer">Pratinjau PDF</a>
                        </div>
                    </template>
                    <template v-else>
                        <p class="warn-text">{{ doc.message }}</p>
                        <button v-if="canFulfill && NEEDS_ARRANGEMENT.includes(doc.code)" class="button button-primary button-small" type="button" @click="loadOptions(state.id, doc.package_id)">Atur pengiriman</button>
                    </template>
                </div>
            </div>
            <div v-if="allDone" class="form-actions">
                <button class="button button-secondary" type="button" @click="loadAll">Ambil ulang / coba lagi</button>
                <a v-if="sellerCenter" class="button button-secondary" :href="sellerCenter" target="_blank" rel="noopener noreferrer">Buka Seller Center</a>
            </div>
        </template>
    </Modal>
</template>
