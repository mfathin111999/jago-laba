# Pemetaan dari PHP lama ke Laravel

Pemetaan lengkap ada di [legacy-removal.md](architecture/legacy-removal.md). Ringkasnya:

| Area lama | Sekarang |
| --- | --- |
| `app/Core/Database`, `app/Database.php` | `Connection` Laravel (`config/database.php`) |
| `app/config.php`, `config/*.php` lama | `config/jago.php`, `config/services.php`, `.env` |
| `app/Shared/Runtime`, `Modules/*/Module.php` | service provider + container |
| `public/index.php`, `public/*.php`, `public/api/*.php` | rute Laravel (URL sama, mis. `/api/rpc.php`) |
| `app/Http/RpcRequest`, `JsonResponse` | `KonveksiRpcController` + `UnavailableResponse` |
| `app/Modules/Marketplace/**` | `app/Services/Marketplace/**` |
| `app/Modules/Konveksi/Views` | `resources/konveksi/views` |
| `Bridges/KonveksiMarketplace/ExplicitStockTransfer` | tidak dipakai; tanpa pemotongan stok implisit |
| `bin/workers/*.php`, `scripts/marketplace-cron.php` | `php artisan schedule:run`, `queue:work`, `marketplace:sync` |
