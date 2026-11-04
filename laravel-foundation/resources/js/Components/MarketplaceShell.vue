<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { MARKETPLACE_NAV, navHref } from '../lib/marketplaceNav';

defineProps<{
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
const page = usePage();
const brand = computed(() => (page.props.marketplaceBrand ?? null) as Brand | null);
const logoSrc = computed(() => (brand.value?.has_logo ? `/api/marketplace-branding.php?image=1&v=${encodeURIComponent(brand.value.version)}` : null));
</script>

<template>
    <Head :title="`${title} · Marketplace`" />
    <main class="marketplace-app">
        <aside class="marketplace-sidebar" aria-label="Navigasi Marketplace">
            <div class="brand-lockup"><span v-if="logoSrc" class="brand-logo" :style="{ width: `${brand?.logo_size}px`, height: `${brand?.logo_size}px` }"><img :src="logoSrc" alt="Logo perusahaan" :style="{ objectFit: brand?.logo_fit === 'cover' ? 'cover' : 'contain' }" /></span><span v-else class="brand-mark">JL</span><span><strong>JagoLaba</strong><small>MARKETPLACE</small></span></div>
            <nav class="sidebar-nav">
                <Link
                    v-for="item in MARKETPLACE_NAV"
                    :key="item.key"
                    :href="navHref(item, item.needsStore ? storeId : (storeId ?? null))"
                    :class="{ active: item.key === active }"
                    :aria-current="item.key === active ? 'page' : undefined"
                >
                    {{ item.label }}
                </Link>
            </nav>
            <div class="sidebar-footer">
                <span v-if="companyKey" class="muted">Company: {{ companyKey }}</span>
                <a class="muted" href="/workspace.php">Ganti ruang kerja</a>
            </div>
        </aside>

        <section class="marketplace-content">
            <header class="marketplace-topbar">
                <div>
                    <div v-if="eyebrow" class="eyebrow">{{ eyebrow }}</div>
                    <h1>{{ title }}</h1>
                    <p v-if="subtitle" class="muted">{{ subtitle }}</p>
                </div>
                <div class="topbar-actions"><slot name="actions" /></div>
            </header>
            <slot />
        </section>
    </main>
</template>
