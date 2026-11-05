import { ROUTES } from './routes';
export interface NavItem {
    key: string;
    label: string;
    path: string;
    /** The page needs an active store, so the store id is carried in the link. */
    needsStore: boolean;
}

/**
 * Pages of the Marketplace workspace that exist in Laravel. A page is added here in the same change that
 * adds its route, so the menu never links to something that is not there.
 */
export const MARKETPLACE_NAV: NavItem[] = [
    { key: 'dashboard', label: 'Dashboard toko', path: ROUTES.dashboard, needsStore: false },
    { key: 'orders', label: 'Transaksi', path: ROUTES.orders, needsStore: false },
    { key: 'analytics', label: 'Analitik & laporan', path: ROUTES.analytics, needsStore: false },
    { key: 'catalog', label: 'Produk & HPP', path: ROUTES.catalog, needsStore: true },
    { key: 'stock', label: 'Stok', path: ROUTES.stock, needsStore: true },
    { key: 'marketing', label: 'Iklan', path: ROUTES.marketing, needsStore: true },
    { key: 'income', label: 'Pemasukan dan penarikan', path: ROUTES.income, needsStore: false },
    { key: 'expenses', label: 'Pengeluaran', path: ROUTES.expenses, needsStore: true },
    { key: 'sync', label: 'Sinkronisasi', path: ROUTES.sync, needsStore: true },
    { key: 'integrations', label: 'Integrasi', path: ROUTES.integrations, needsStore: false },
    { key: 'stores', label: 'Toko', path: ROUTES.stores, needsStore: false },
];

export function navHref(item: NavItem, storeId?: string | null): string {
    if (!storeId) {
        return item.path;
    }
    const query = new URLSearchParams({ store: storeId, store_id: storeId });

    return `${item.path}?${query.toString()}`;
}
