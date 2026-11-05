/**
 * Backend URLs the pages call, in one place. Every static path here is checked against the real route list by
 * lib/__tests__/routes.test.ts (tests/Feature/fixtures/routes.snapshot.json), so a typo or a renamed route fails a
 * test instead of a user. Paths with parameters are built where they are used (encodeURIComponent on every value).
 */
export const ROUTES = {
    /** Inertia pages (Marketplace workspace). */
    dashboard: '/pilot/marketplace',
    dashboardExport: '/pilot/marketplace/export',
    orders: '/pilot/marketplace/orders',
    analytics: '/pilot/marketplace/analytics',
    catalog: '/marketplace/catalog',
    stock: '/marketplace/stock',
    marketing: '/marketplace/marketing',
    marketingTemplate: '/marketplace/marketing/template',
    income: '/marketplace/income',
    expenses: '/marketplace/expenses',
    integrations: '/marketplace/integrations',
    sync: '/marketplace/sync',
    stores: '/marketplace/stores',

    /** JSON writes. */
    storesWrite: '/pilot/marketplace/stores',
    expensesWrite: '/pilot/marketplace/expenses',
    hppGlobal: '/pilot/marketplace/hpp/global',
    withdrawalsPreview: '/pilot/marketplace/withdrawals/preview',
    oauthStart: '/pilot/marketplace/oauth/start',
    oauthRefresh: '/pilot/marketplace/oauth/refresh',

    /** Legacy-contract endpoints (kept for the old clients). */
    api: {
        oauth: '/api/marketplace-oauth.php',
        fulfillment: '/api/marketplace-fulfillment.php',
        shippingDocument: '/api/marketplace-shipping-document.php',
        withdrawals: '/api/marketplace-withdrawals.php',
        sync: '/api/marketplace-sync.php',
        storeDeletion: '/api/marketplace-store-deletion.php',
        stock: '/api/marketplace-stock.php',
        price: '/api/marketplace-price.php',
        orderFinance: '/api/marketplace-order-finance.php',
        integrationStatus: '/api/marketplace-integration-status.php',
        branding: '/api/marketplace-branding.php',
        autoSync: '/api/marketplace-auto-sync.php',
    },

    auth: {
        login: '/auth/login',
        me: '/auth/me',
        logout: '/auth/logout',
        workspace: '/auth/workspace',
    },

    konveksi: '/konveksi',
    workspacePage: '/workspace.php',
} as const;

/** Every static path above, flattened (used by the route-contract test). */
export function allRoutePaths(): string[] {
    const paths: string[] = [];
    const visit = (value: unknown): void => {
        if (typeof value === 'string') {
            paths.push(value);
        } else if (value && typeof value === 'object') {
            Object.values(value).forEach(visit);
        }
    };
    visit(ROUTES);

    return paths;
}
