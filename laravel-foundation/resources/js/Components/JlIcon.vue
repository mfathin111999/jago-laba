<script setup lang="ts">
import { computed } from 'vue';
import { ICON_PATHS } from '../lib/icons';

const props = defineProps<{ name: string }>();
const paths = computed(() => ICON_PATHS[props.name] ?? ICON_PATHS.package);
</script>

<template>
    <svg class="jl-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="paths" />
</template>
