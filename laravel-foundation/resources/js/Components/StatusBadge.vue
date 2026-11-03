<script setup lang="ts">
defineProps<{ tone?: 'ok' | 'warn' | 'danger' | 'muted' | 'info'; label: string }>();
</script>

<template>
    <span class="status-badge" :class="`status-${tone ?? 'muted'}`">{{ label }}</span>
</template>
