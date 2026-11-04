<script setup lang="ts">
import { computed } from 'vue';
import { formatValue, type ValueKind } from '../lib/format';

const props = defineProps<{ label: string; value: unknown; kind?: ValueKind; hint?: string; tone?: string; text?: string }>();
/** `text` is shown as given (for ratios and notes); otherwise the value is formatted by `kind`. */
const shown = computed(() => props.text ?? formatValue(props.value, props.kind ?? 'currency'));
</script>

<template>
    <article class="metric-card" :class="tone ? `metric-${tone}` : ''">
        <span class="metric-label">{{ label }}</span>
        <strong>{{ shown }}</strong>
        <small v-if="hint">{{ hint }}</small>
    </article>
</template>
