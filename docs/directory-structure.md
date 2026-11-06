# Struktur Direktori

```text
laravel-foundation/
  app/
    Audit/            # AuditRecorded + listener WriteAuditLog
    Auth/             # AuthenticatedUser, provider, AuthenticationService
    Authorization/    # RequestContext, ability/peran
    Console/          # perintah artisan (konveksi:*, marketplace:*)
    Enums/            # Platform, ConnectionStatus, FinanceState, PayoutStatus, StockStatus, AnalyticsMode
    Http/             # controller, middleware, FormRequest
    Jobs/             # MarketplaceSyncJob, MarketplaceLiveSyncJob
    Services/
      Marketplace/    # Analytics, Catalog, Expenses, Export, Http, Income, Integrations,
                      # Marketing, OAuth, Price, Shipping, Stock, Sync, Withdrawal, Concerns/RunsSql
      Konveksi/       # koleksi bertipe, sinkron + HPP, akun pengguna, baca pilot
  config/             # jago.php, services.php, ...
  database/
    migrations/       # satu-satunya sumber skema
    schema/           # registri koleksi Konveksi (konveksi.php, konveksi_typed.php)
    seeders/          # MarketplaceDemoSeeder
    snapshots/        # snapshot awal Konveksi
  resources/
    js/               # Inertia + Vue 3
    konveksi/views/   # tampilan SPA Konveksi
    css/legacy/       # utilitas Tailwind yang dibangun dari sumber Vue
  routes/             # web, api, console
  tests/              # Feature, Unit, e2e (Playwright), visual
docs/                 # dokumen ini
scripts/              # run-local, build release
```
