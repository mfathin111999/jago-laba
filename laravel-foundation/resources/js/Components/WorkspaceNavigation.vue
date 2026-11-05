<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ROUTES } from '../lib/routes';
const props = defineProps<{ workspace: 'marketplace' | 'konveksi'; storeId?: string | null }>();
const href = (path: string) => props.storeId ? `${path}?store=${encodeURIComponent(props.storeId)}&store_id=${encodeURIComponent(props.storeId)}` : path;
</script>
<template>
    <nav class="workspace-navigation" aria-label="Navigasi ruang kerja">
        <Link href="/">JAGO LABA · Ruang kerja</Link>
        <template v-if="workspace === 'marketplace'">
            <Link :href="href(ROUTES.dashboard)">Dashboard</Link>
            <Link :href="href(ROUTES.orders)">Pesanan</Link>
            <Link :href="href(ROUTES.analytics)">Analitik</Link>
            <Link v-if="storeId" :href="href(ROUTES.catalog)">Katalog &amp; HPP</Link>
            <Link v-if="storeId" :href="href(ROUTES.expenses)">Pengeluaran</Link>
            <Link :href="ROUTES.stores">Toko</Link>
        </template>
        <a v-else :href="ROUTES.konveksi">Konveksi</a>
    </nav>
</template>
<style scoped>
.workspace-navigation{display:flex;align-items:center;flex-wrap:wrap;gap:1rem;padding:1rem 1.5rem;background:#fff;border-bottom:1px solid #dbe3ef;color:#173369;font-size:.875rem}.workspace-navigation a{color:inherit;text-decoration:none;padding:.3rem .1rem}.workspace-navigation a:first-child{font-weight:800;margin-right:auto}.workspace-navigation a:focus-visible{outline:2px solid #2563eb;outline-offset:4px}
</style>
