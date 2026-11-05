<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; title: string; busy?: boolean }>();
const emit = defineEmits<{ close: [] }>();

const dialog = ref<HTMLDialogElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        await nextTick();
        if (open && dialog.value && !dialog.value.open) {
            dialog.value.showModal();
        } else if (!open && dialog.value?.open) {
            dialog.value.close();
        }
    },
    { immediate: true },
);

function onCancel(event: Event) {
    // Escape must not discard a request that is still running.
    event.preventDefault();
    if (!props.busy) {
        emit('close');
    }
}
</script>

<template>
    <dialog ref="dialog" class="mp-order-dialog" aria-labelledby="modal-title" @cancel="onCancel" @click.self="!busy && emit('close')">
        <div v-if="open">
            <header>
                <h2 id="modal-title">{{ title }}</h2>
                <button type="button" :disabled="busy" aria-label="Tutup" @click="emit('close')">×</button>
            </header>
            <slot />
        </div>
    </dialog>
</template>
