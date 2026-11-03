<script setup lang="ts" generic="Row extends Record<string, any>">
import { formatDate, formatText, formatValue } from '../lib/format';

export interface Column {
    key: string;
    label: string;
    /** How the raw value is shown; `text` is the default. */
    kind?: 'text' | 'currency' | 'count' | 'percent' | 'decimal' | 'date';
    align?: 'left' | 'right';
}

const props = defineProps<{
    columns: Column[];
    rows: Row[];
    rowKey?: string;
    empty?: string;
}>();

function cell(row: Row, column: Column): string {
    const value = row[column.key];
    switch (column.kind) {
        case 'currency':
        case 'count':
        case 'percent':
        case 'decimal':
            return formatValue(value, column.kind);
        case 'date':
            return formatDate(value);
        default:
            return formatText(value);
    }
}

const keyOf = (row: Row, index: number): string | number => (props.rowKey ? row[props.rowKey] : index);
</script>

<template>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key" :class="{ 'is-right': column.align === 'right' }">{{ column.label }}</th>
                    <th v-if="$slots.actions" class="is-right"><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="keyOf(row, index)">
                    <td v-for="column in columns" :key="column.key" :class="{ 'is-right': column.align === 'right' }">
                        <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">{{ cell(row, column) }}</slot>
                    </td>
                    <td v-if="$slots.actions" class="is-right table-actions"><slot name="actions" :row="row" /></td>
                </tr>
            </tbody>
        </table>
        <p v-if="rows.length === 0" class="empty-note">{{ empty ?? 'Tidak ada data.' }}</p>
    </div>
</template>
