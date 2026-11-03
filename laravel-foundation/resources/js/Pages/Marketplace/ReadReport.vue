<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WorkspaceNavigation from '../../Components/WorkspaceNavigation.vue';
const props = defineProps<{ title: string; data: Record<string, any>; rows: Array<Record<string, any>>; columns: Record<string, string>; filters: Record<string, any>; read_only: boolean }>();
const pathname = () => window.location.pathname;
const search = ref(props.filters.search ?? '');
const href = (format: string) => `${pathname()}?${new URLSearchParams({ ...props.filters, search: search.value, format })}`;
const load = () => router.get(pathname(), { ...props.filters, search: search.value });
const display = (value: unknown) => value === null || value === undefined ? 'Belum tersedia' : String(value);
</script>
<template>
    <Head :title="`${title} · Jago Laba`" />
    <WorkspaceNavigation workspace="marketplace" :store-id="filters.store_id" />
    <main class="pilot-shell">
        <header class="pilot-header"><div><h1>{{ title }}</h1><p>Data dari toko terpilih.</p></div><a class="button" :href="href('csv')">Unduh CSV</a></header>
        <form class="panel" @submit.prevent="load"><label>Cari <input v-model="search" maxlength="200" /></label><button class="button">Terapkan</button></form>
        <section class="panel" style="overflow:auto;margin-top:1rem"><table><thead><tr><th v-for="(label,key) in columns" :key="key">{{ label }}</th></tr></thead><tbody><tr v-for="(row,i) in rows" :key="row.id ?? i"><td v-for="(label,key) in columns" :key="key">{{ display(row[key]) }}</td></tr></tbody></table><p v-if="rows.length === 0">Tidak ada data yang cocok.</p></section>
        <p v-if="data.pages">Halaman {{ data.page }} dari {{ data.pages }} <button v-if="data.page > 1" @click="router.get(pathname(),{...filters,page:data.page-1})">Sebelumnya</button> <button v-if="data.page < data.pages" @click="router.get(pathname(),{...filters,page:data.page+1})">Berikutnya</button></p>
    </main>
</template>
