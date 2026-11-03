/** Shown wherever a figure is unknown. An unknown value is never displayed as zero. */
export const UNKNOWN = 'Belum tersedia';

export type ValueKind = 'currency' | 'count' | 'percent' | 'decimal';

function toNumber(value: unknown): number | null {
    if (value === null || value === undefined || value === '' || typeof value === 'boolean') {
        return null;
    }
    const number = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(number) ? number : null;
}

/** Formats a value for display; null, empty and non-numeric input become {@link UNKNOWN}. */
export function formatValue(value: unknown, kind: ValueKind = 'currency'): string {
    const number = toNumber(value);
    if (number === null) {
        return UNKNOWN;
    }

    switch (kind) {
        case 'count':
            return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(number);
        case 'decimal':
            return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(number);
        case 'percent':
            return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(number)}%`;
        default:
            return `${number < 0 ? '-' : ''}Rp ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.abs(number))}`;
    }
}

export const formatRupiah = (value: unknown): string => formatValue(value, 'currency');

/** Plain text cell: null, undefined and empty strings become {@link UNKNOWN}. */
export function formatText(value: unknown): string {
    if (value === null || value === undefined) {
        return UNKNOWN;
    }
    const text = String(value).trim();

    return text === '' ? UNKNOWN : text;
}

/** "2026-11-04 10:30:00" or ISO → "4 Nov 2026" (date part only; no timezone shifting). */
export function formatDate(value: unknown): string {
    if (typeof value !== 'string' || value.trim() === '') {
        return UNKNOWN;
    }
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());
    if (match === null) {
        return UNKNOWN;
    }
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const month = Number(match[2]);
    if (month < 1 || month > 12) {
        return UNKNOWN;
    }

    return `${Number(match[3])} ${months[month - 1]} ${match[1]}`;
}

/** Profit margin in percent, or null when either side is unknown or sales are zero. */
export function marginPercent(profit: unknown, sales: unknown): number | null {
    const p = toNumber(profit);
    const s = toNumber(sales);

    return p === null || s === null || s === 0 ? null : (p / s) * 100;
}
