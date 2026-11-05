<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ platform?: string | null; size?: 24 | 32 | 40 | 48 }>(), { platform: '', size: 32 });
const key = computed(() => String(props.platform ?? '').toLowerCase().replace(/[^a-z0-9]/g, ''));
const asset = computed(() => (key.value === 'shopee' ? 'shopee' : key.value === 'tiktok' || key.value === 'tiktokshop' ? 'tiktok' : null));
const box = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }));
</script>

<template>
    <img v-if="asset" class="jl-platform-logo" :src="`/assets/marketplace/images/marketplaces/${asset}.png`" :width="size" :height="size" :style="box" :alt="`Logo ${platform}`" decoding="async" />
    <span v-else class="jl-platform-logo jl-platform-logo-fallback" :style="box" :aria-label="String(platform)">{{ String(platform ?? '').slice(0, 2).toUpperCase() }}</span>
</template>
