<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ApiError, apiRequest, newIdempotencyKey } from '../lib/api';
import { formatValue } from '../lib/format';
import Modal from './Modal.vue';

type Row = Record<string, any>;

const props = defineProps<{ open: boolean; storeId: string; productId: string }>();
const emit = defineEmits<{ (event: 'close'): void }>();

const data = ref<Row | null>(null);
const mode = ref<'base' | 'discount'>('base');
const busy = ref(false);
const loading = ref(false);
const message = ref('');
/** After a save the marketplace answer may be pending; the legacy editor locks until the page is reopened. */
const uncertain = ref(false);
const prices = reactive<Record<string, string>>({});
const picked = reactive<Record<string, boolean>>({});
const statuses = reactive<Record<string, string>>({});
const promo = reactive({ title: '', start: '', end: '', bulk: '', replace: false });
let operationKey: string | null = null;

const money = (value: unknown): string => (value === null || value === undefined ? '—' : formatValue(value));
const localInput = (stamp: number): string => new Date(stamp - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);

const rows = computed<Row[]>(() => data.value?.rows ?? []);
const changes = computed(() => rows.value
    .filter((row) => mode.value === 'base' || picked[row.external_variant_id])
    .map((row) => ({ variant_id: String(row.external_variant_id), price: prices[row.external_variant_id] ?? '' }))
    .filter((change) => change.price !== ''));
const hasPromotions = computed(() => rows.value.some((row) => (row.promotions ?? []).length > 0));
const saveDisabled = computed(() => busy.value || uncertain.value || changes.value.length === 0 || (mode.value === 'discount' && !!data.value?.promotion_error));

function promoText(row: Row): string {
    for (const item of row.promotions ?? []) {
        const sku = (item.product?.skus ?? []).find((candidate: Row) => String(candidate.id) === String(row.external_variant_id));
        const amount = sku?.activity_price?.amount ?? sku?.activity_price_amount ?? item.product?.activity_price?.amount ?? item.product?.activity_price_amount;
        if (amount) return `${money(amount)} · ${item.title}`;
    }

    return (row.promotions ?? []).length ? 'Promo terdaftar' : '—';
}

function netOf(row: Row): string {
    const value = Number(prices[row.external_variant_id]);

    return row.fee_rate == null || !value ? '—' : money(value * (1 - row.fee_rate));
}

async function call(action: 'prepare' | 'save', payload?: Record<string, unknown>): Promise<Row> {
    try {
        return (await apiRequest('/api/marketplace-price.php', { action, store_id: props.storeId, product_id: props.productId, payload })).data ?? {};
    } catch (error) {
        throw error instanceof ApiError ? new Error(error.message + (error.code ? ` (${error.code})` : '')) : error;
    }
}

async function load(): Promise<void> {
    loading.value = true;
    message.value = 'Membaca harga dan promo terbaru dari marketplace…';
    try {
        data.value = await call('prepare');
        message.value = data.value.promotion_error
            ? `Akses promo belum berhasil (${data.value.promotion_error}). Tab Diskon memerlukan izin Promotion Information dan Promotion Modify pada token toko. Setelah mengaktifkan izin di Partner Center, hubungkan ulang toko dari menu Integrasi, lalu klik Coba lagi. ${data.value.promotion_error_message ?? ''}`.trim()
            : '';
    } catch (error) {
        data.value = null;
        message.value = error instanceof Error ? error.message : 'Harga belum dapat dibaca.';
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (open) => {
    if (!open) return;
    mode.value = 'base';
    data.value = null;
    uncertain.value = false;
    operationKey = null;
    Object.keys(prices).forEach((key) => delete prices[key]);
    Object.keys(picked).forEach((key) => delete picked[key]);
    Object.keys(statuses).forEach((key) => delete statuses[key]);
    promo.title = `Promo ${new Date().toLocaleString('id-ID')} ${newIdempotencyKey().slice(0, 4)}`;
    promo.start = localInput(Date.now() + 10 * 60000);
    promo.end = localInput(Date.now() + 7 * 86400000);
    promo.replace = false;
    void load();
}, { immediate: true });

function applyBulk(): void {
    rows.value.filter((row) => picked[row.external_variant_id]).forEach((row) => (prices[row.external_variant_id] = promo.bulk));
}

function switchMode(next: 'base' | 'discount'): void {
    if (busy.value || uncertain.value || !data.value) return;
    mode.value = next;
    operationKey = null;
}

async function save(): Promise<void> {
    if (saveDisabled.value) return;
    const selected = changes.value;
    if (mode.value === 'discount' && selected.some((change) => Number(change.price) >= Number(rows.value.find((row) => String(row.external_variant_id) === change.variant_id)?.base_price))) {
        message.value = 'Harga promo harus lebih rendah dari harga dasar.';

        return;
    }
    operationKey ??= newIdempotencyKey();
    busy.value = true;
    message.value = 'Mengirim perubahan dan memverifikasi respons marketplace…';
    try {
        const result = await call('save', {
            mode: mode.value, operation_key: operationKey, changes: selected, title: promo.title,
            begin_time: Math.floor(new Date(promo.start).getTime() / 1000), end_time: Math.floor(new Date(promo.end).getTime() / 1000), replace_existing: promo.replace,
        });
        message.value = (result.message || `Status: ${result.status}`) + (result.error_code ? ` (${result.error_code})` : '');
        for (const row of result.rows ?? []) {
            statuses[row.variant_id] = ({ verified: 'Tersinkron', scheduled: 'Promo terjadwal', pending_verification: 'Menunggu verifikasi' } as Record<string, string>)[row.status] ?? row.status;
        }
        uncertain.value = true;
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'Perubahan belum dapat dikirim.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal :open="open" :title="data ? `${data.product_name} · ${data.store_name}` : 'Ubah harga'" :busy="busy" @close="emit('close')">
        <div class="tab-bar" role="tablist">
            <button type="button" role="tab" :class="{ active: mode === 'base' }" :aria-selected="mode === 'base'" @click="switchMode('base')">Harga dasar</button>
            <button type="button" role="tab" :class="{ active: mode === 'discount' }" :aria-selected="mode === 'discount'" @click="switchMode('discount')">Diskon</button>
        </div>
        <p class="muted cell-note">{{ mode === 'base' ? 'Isi harga baru hanya pada baris yang mau diubah; baris kosong tidak dikirim. Harga promo dan diskon tidak ikut berubah. Harga dasar yang terkunci promo harus dikelola terlebih dahulu.' : 'Pilih varian dan harga promo. Promo yang sudah ada akan diganti setelah Anda memberikan konfirmasi.' }}</p>

        <div v-if="mode === 'discount'" class="form-grid">
            <div class="field field-wide"><label for="pe-title">Nama promo</label><input id="pe-title" v-model="promo.title" maxlength="100" /></div>
            <div class="field"><label for="pe-start">Mulai</label><input id="pe-start" v-model="promo.start" type="datetime-local" /></div>
            <div class="field"><label for="pe-end">Selesai</label><input id="pe-end" v-model="promo.end" type="datetime-local" /></div>
            <div class="field"><label for="pe-bulk">Harga promo untuk varian terpilih</label><input id="pe-bulk" v-model="promo.bulk" type="number" min="1" step="0.01" /></div>
            <div class="form-actions"><button class="button button-secondary button-small" type="button" @click="applyBulk">Terapkan ke varian terpilih</button></div>
        </div>

        <p v-if="loading" class="muted">{{ message }}</p>
        <template v-else>
            <div v-if="rows.length" class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th v-if="mode === 'discount'">Pilih</th><th>Toko</th><th>Varian</th><th class="num">Harga dasar</th><th v-if="mode === 'discount'">Promo sekarang</th>
                            <th class="num">{{ mode === 'base' ? 'Harga baru (Rp)' : 'Harga promo (Rp)' }}</th><th class="num">{{ mode === 'base' ? 'Bersih baru' : 'Bersih promo' }}</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.external_variant_id">
                            <td v-if="mode === 'discount'"><input v-model="picked[row.external_variant_id]" type="checkbox" :disabled="!row.editable" :aria-label="`Pilih varian ${row.variant_name}`" /></td>
                            <td>{{ data?.store_name }}</td>
                            <td>{{ row.variant_name || row.sku }}<div class="muted cell-note">SKU: {{ row.sku }}</div></td>
                            <td class="num">{{ money(row.base_price) }}<div v-if="mode === 'base' && row.promotion_caption" class="muted cell-note">{{ row.promotion_caption }}</div></td>
                            <td v-if="mode === 'discount'">{{ promoText(row) }}</td>
                            <td class="num"><input v-model="prices[row.external_variant_id]" type="number" min="1" step="0.01" :placeholder="mode === 'base' ? 'Tidak diubah' : 'Harga promo'" :disabled="!row.editable || uncertain" :aria-label="`Harga ${row.variant_name}`" /></td>
                            <td class="num">{{ netOf(row) }}<div v-if="row.fee_rate != null && Number(prices[row.external_variant_id])" class="muted cell-note">Estimasi biaya MP {{ (row.fee_rate * 100).toFixed(1) }}%</div></td>
                            <td>{{ statuses[row.external_variant_id] ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <label v-if="hasPromotions && mode === 'discount'" class="checkbox-row"><input v-model="promo.replace" type="checkbox" /> Saya mengerti promo yang sudah ada pada varian ini akan diganti.</label>
            <p v-if="message" role="status" :class="{ 'warn-text': !busy }">{{ message }}</p>
            <p class="muted cell-note">{{ changes.length }} varian akan dikirim ke marketplace.<span v-if="uncertain"> Tutup dan muat ulang jendela ini untuk melihat status terbaru sebelum mengubah lagi.</span></p>
            <div class="form-actions">
                <button v-if="data?.promotion_error || !rows.length" class="button button-secondary" type="button" @click="load">Coba lagi</button>
                <button class="button button-primary" type="button" :disabled="saveDisabled" @click="save">{{ mode === 'base' ? 'Simpan & sinkronkan' : `Buat promo (${changes.length} varian)` }}</button>
            </div>
        </template>
    </Modal>
</template>
