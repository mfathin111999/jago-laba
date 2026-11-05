<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import JlIcon from './JlIcon.vue';
import PlatformLogo from './PlatformLogo.vue';
import { MARKETPLACE_NAV, navHref } from '../lib/marketplaceNav';

const props = defineProps<{
    /** Key of the active menu item. */
    active: string;
    title: string;
    eyebrow?: string;
    subtitle?: string;
    storeId?: string | null;
    companyKey?: string | null;
}>();

interface Brand {
    has_logo: boolean;
    logo_size: number;
    logo_fit: string;
    version: string;
}
type StoreOption = { id: string; store_name?: string; platform?: string };

const page = usePage();
const brand = computed(() => (page.props.marketplaceBrand ?? null) as Brand | null);
const logoSrc = computed(() => (brand.value?.has_logo ? `/api/marketplace-branding.php?image=1&v=${encodeURIComponent(brand.value.version)}` : null));
const logoSize = computed(() => Math.max(36, brand.value?.logo_size ?? 36));
const logoFit = computed(() => (brand.value?.logo_fit === 'cover' ? 'cover' : 'contain'));
const userName = computed(() => String(page.props.authUserName ?? '') || 'Pengguna Jago Laba');
const stores = computed(() => (Array.isArray(page.props.stores) ? (page.props.stores as StoreOption[]).filter((store) => store && store.id) : []));
const activeStore = computed(() => stores.value.find((store) => store.id === props.storeId) ?? null);

const open = ref(false);
const byKey = (key: string) => MARKETPLACE_NAV.find((item) => item.key === key)!;
const href = (key: string) => {
    const item = byKey(key);

    return navHref(item, item.needsStore ? props.storeId : (props.storeId ?? null));
};
const storeHref = (id: string) => navHref(byKey(props.active) ?? byKey('dashboard'), id);

interface Leaf { key: string; icon: string; label: string }
interface Item extends Leaf { children?: Leaf[] }

/** Same sections and labels as the legacy store sidebar. */
const sections: Array<{ heading: string; items: Item[] }> = [
    {
        heading: 'OPERASIONAL',
        items: [
            { key: 'dashboard', icon: 'dashboard', label: 'Dashboard Toko' },
            { key: 'orders', icon: 'package', label: 'Pesanan' },
            { key: 'catalog', icon: 'package', label: 'Produk', children: [{ key: 'catalog', icon: 'calculator', label: 'HPP Produk' }, { key: 'stock', icon: 'boxes', label: 'Stok' }] },
        ],
    },
    {
        heading: 'KEUANGAN & IKLAN',
        items: [
            { key: 'income', icon: 'wallet', label: 'Pemasukan & Settlement' },
            { key: 'expenses', icon: 'expense', label: 'Pengeluaran' },
            { key: 'marketing', icon: 'megaphone', label: 'Iklan & Marketing' },
        ],
    },
    {
        heading: 'ANALITIK & SISTEM',
        items: [
            { key: 'analytics', icon: 'chart', label: 'Analitik & Laporan' },
            { key: 'sync', icon: 'history', label: 'Sinkronisasi' },
        ],
    },
    {
        heading: 'PREFERENSI',
        items: [{ key: 'integrations', icon: 'settings', label: 'Pengaturan Toko', children: [{ key: 'integrations', icon: 'plug', label: 'Integrasi' }, { key: 'stores', icon: 'store', label: 'Toko' }] }],
    },
];
const isActive = (item: Item) => item.key === props.active || Boolean(item.children?.some((child) => child.key === props.active));

const bodyClasses = ['jl-marketplace', 'text-gray-800', 'flex', 'min-h-screen'];
onMounted(() => document.body.classList.add(...bodyClasses));
onBeforeUnmount(() => document.body.classList.remove(...bodyClasses));
</script>

<template>
    <Head :title="`${title} · Marketplace`" />
    <div class="jl-mobile-topbar">
        <Link href="/marketplace/stores" class="font-extrabold">
            <span class="jl-brand-lockup" style="display: inline-flex; align-items: center; gap: 10px">
                <span class="jl-rotating-logo-frame" style="width: 28px; height: 28px; border-radius: 12px; flex-shrink: 0"><span class="jl-rotating-logo-inner" style="border-radius: 10px; padding: 2px"><img v-if="logoSrc" :src="logoSrc" alt="Logo Jago Laba" :style="{ width: '100%', height: '100%', borderRadius: '8px', objectFit: logoFit }" /><span v-else class="jl-brand-default" aria-hidden="true" style="font-weight: 900; color: #fbbf24; background: transparent; font-size: 13px">JL</span></span></span>
                <span class="jl-brand-words"><strong style="font-size: 15px; font-weight: 900; letter-spacing: -0.4px; color: #172744; line-height: 1.2">Jago Laba</strong></span>
            </span>
        </Link>
        <button type="button" aria-controls="jl-navigation" :aria-expanded="open" aria-label="Buka navigasi" @click="open = !open"><JlIcon name="menu" /></button>
    </div>
    <button v-show="open" class="jl-nav-overlay" aria-label="Tutup navigasi" @click="open = false"></button>

    <aside id="jl-navigation" class="jl-sidebar w-64 bg-white border-r flex flex-col fixed inset-y-0 z-30" :class="{ 'jl-nav-open': open }">
        <div class="jl-sidebar-brand">
            <Link href="/marketplace/stores" class="flex items-center gap-3">
                <span class="jl-brand-lockup" style="display: inline-flex; align-items: center; gap: 10px">
                    <span class="jl-rotating-logo-frame" :style="{ width: `${logoSize}px`, height: `${logoSize}px`, borderRadius: '12px', flexShrink: 0 }"><span class="jl-rotating-logo-inner" style="border-radius: 10px; padding: 2px"><img v-if="logoSrc" :src="logoSrc" alt="Logo Jago Laba" :style="{ width: '100%', height: '100%', borderRadius: '8px', objectFit: logoFit }" /><span v-else class="jl-brand-default" aria-hidden="true" style="font-weight: 900; color: #fbbf24; background: transparent; font-size: 13px">JL</span></span></span>
                    <span class="jl-brand-words"><strong style="font-size: 15px; font-weight: 900; letter-spacing: -0.4px; color: #172744; line-height: 1.2">Jago Laba</strong><small style="display: flex; align-items: center; gap: 4px; font-size: 8px; font-weight: 800; letter-spacing: 1px; color: #d97706; margin-top: 2px; white-space: nowrap"><span style="width: 5px; height: 5px; border-radius: 50%; background: #f59e0b; display: inline-block"></span>MARKETPLACE OS</small></span>
                </span>
            </Link>
            <button type="button" class="jl-mobile-close" aria-label="Tutup navigasi" @click="open = false"><JlIcon name="close" /></button>
        </div>
        <Link class="jl-scope-label" href="/marketplace/stores"><JlIcon name="arrow-left" /> Pilih Toko</Link>
        <details v-if="activeStore" class="jl-store-picker">
            <summary>
                <PlatformLogo :platform="activeStore.platform" />
                <span><strong>{{ activeStore.store_name || activeStore.id }}</strong><small>{{ activeStore.platform }}</small></span>
            </summary>
            <div>
                <Link v-for="store in stores" :key="store.id" :href="storeHref(store.id)" :aria-current="store.id === storeId ? 'page' : undefined">
                    <span class="jl-store-option"><PlatformLogo :platform="store.platform" :size="24" /><span>{{ store.store_name || store.id }}<small>{{ store.platform }}</small></span></span>
                </Link>
            </div>
        </details>
        <nav class="jl-navigation" aria-label="Navigasi toko">
            <template v-for="section in sections" :key="section.heading">
                <p class="jl-nav-heading">{{ section.heading }}</p>
                <template v-for="item in section.items" :key="item.label">
                    <details v-if="item.children" :open="isActive(item)">
                        <summary class="jl-nav-link" :class="{ 'jl-nav-selected': isActive(item) }"><JlIcon :name="item.icon" /><span>{{ item.label }}</span></summary>
                        <div class="jl-subnav">
                            <Link v-for="child in item.children" :key="child.label" :href="href(child.key)" :aria-current="child.key === active ? 'page' : undefined"><JlIcon :name="child.icon" />{{ child.label }}</Link>
                        </div>
                    </details>
                    <Link v-else class="jl-nav-link" :href="href(item.key)" :aria-current="item.key === active ? 'page' : undefined"><JlIcon :name="item.icon" /><span>{{ item.label }}</span></Link>
                </template>
            </template>
        </nav>
        <footer class="jl-sidebar-footer">
            <div class="jl-scope-note"><strong>{{ userName }}</strong><p>Ruang kerja toko marketplace</p></div>
            <a href="/workspace.php"><JlIcon name="compare" /> Ganti Ruang Kerja</a>
        </footer>
    </aside>

    <main class="jl-main md:ml-64 p-4 sm:p-6 lg:p-8 flex-1 min-w-0 pb-20">
        <slot />
    </main>
</template>
