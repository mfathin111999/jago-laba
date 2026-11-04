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
    { key: 'dashboard', label: 'Dashboard toko', path: '/pilot/marketplace', needsStore: false },
    { key: 'orders', label: 'Transaksi', path: '/pilot/marketplace/orders', needsStore: false },
    { key: 'analytics', label: 'Analitik & laporan', path: '/pilot/marketplace/analytics', needsStore: false },
    { key: 'catalog', label: 'Produk & HPP', path: '/marketplace/catalog', needsStore: true },
    { key: 'stock', label: 'Stok', path: '/marketplace/stock', needsStore: true },
    { key: 'marketing', label: 'Iklan', path: '/marketplace/marketing', needsStore: true },
    { key: 'income', label: 'Pemasukan dan penarikan', path: '/marketplace/income', needsStore: false },
    { key: 'expenses', label: 'Pengeluaran', path: '/marketplace/expenses', needsStore: true },
    { key: 'sync', label: 'Sinkronisasi', path: '/marketplace/sync', needsStore: true },
    { key: 'integrations', label: 'Integrasi', path: '/marketplace/integrations', needsStore: false },
    { key: 'stores', label: 'Toko', path: '/marketplace/stores', needsStore: false },
];

export function navHref(item: NavItem, storeId?: string | null): string {
    if (!storeId) {
        return item.path;
    }
    const query = new URLSearchParams({ store: storeId, store_id: storeId });

    return `${item.path}?${query.toString()}`;
}
