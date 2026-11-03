<script setup lang="ts">
defineProps<{ message: string | null; kind?: 'success' | 'error' }>();
defineEmits<{ dismiss: [] }>();
</script>

<template>
    <div v-if="message" class="flash" :class="`flash-${kind ?? 'success'}`" :role="kind === 'error' ? 'alert' : 'status'">
        <span>{{ message }}</span>
        <button type="button" class="flash-dismiss" aria-label="Tutup pesan" @click="$emit('dismiss')">×</button>
    </div>
</template>
