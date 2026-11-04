<#
.SYNOPSIS
  Menjalankan Jago Laba (Laravel) di komputer lokal dengan XAMPP MySQL. Aman diulang: yang sudah ada tidak ditimpa.

.DESCRIPTION
  Langkah (semua dilewati bila sudah beres):
    1. cari PHP 8.3+ dan pastikan MySQL menyala
    2. buat laravel-foundation\.env bila belum ada (APP_KEY otomatis; rahasia tidak masuk Git)
    3. buat database + skema + login owner bila database BELUM berisi tabel Jago (database yang sudah berisi tidak disentuh)
    4. -Seed: isi data contoh Marketplace (sintetis)
    5. pasang tabel antrean, bangun tampilan (npm) bila belum
    6. jalankan server web, worker antrean, dan penjadwal di jendela terpisah

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\development\run-local.ps1 -Seed
  powershell -ExecutionPolicy Bypass -File scripts\development\run-local.ps1 -DbName jago_laba -Port 8000
#>
param(
    [string]$DbName = 'jago_laba',
    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbUser = 'root',
    [string]$DbPass = '',
    [int]$Port = 8000,
    [string]$Php = '',
    [string]$OwnerPassword = 'owner123',
    [switch]$Seed,
    [switch]$NoWorker,
    [switch]$Rebuild,
    [switch]$Live,
    [switch]$Sandbox
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$app = Join-Path $repo 'laravel-foundation'
function Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }
function Fail($text) { Write-Host "`nGAGAL: $text" -ForegroundColor Red; exit 1 }

# ---------- 1. PHP dan MySQL ----------
Step 'Memeriksa PHP dan MySQL'
$candidates = @($Php, $env:PHP_BIN, 'C:\xampp\php8_4\php.exe', 'C:\xampp\php8_3\php.exe', 'php') | Where-Object { $_ }
$phpExe = $null
foreach ($candidate in $candidates) {
    try {
        $version = & $candidate -r 'echo PHP_VERSION;' 2>$null
        if ($LASTEXITCODE -eq 0 -and [version]($version -replace '[^0-9.].*$', '') -ge [version]'8.3') { $phpExe = $candidate; break }
    } catch { }
}
if (-not $phpExe) { Fail 'PHP 8.3 atau lebih baru tidak ditemukan. Beri path lewat -Php "C:\xampp\php8_4\php.exe".' }
Write-Host "PHP: $phpExe ($(& $phpExe -r 'echo PHP_VERSION;'))"

$tcp = New-Object System.Net.Sockets.TcpClient
try { $tcp.Connect($DbHost, $DbPort) } catch { Fail "MySQL tidak menjawab di ${DbHost}:${DbPort}. Nyalakan MySQL lewat XAMPP Control Panel (tombol Start di baris MySQL), lalu jalankan lagi." } finally { $tcp.Close() }
$mysql = @('C:\xampp\mysql\bin\mysql.exe') | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $mysql) { Fail 'mysql.exe tidak ditemukan di C:\xampp\mysql\bin.' }
function Sql($statement) {
    $arguments = @("-h$DbHost", "-P$DbPort", "-u$DbUser", '-N', '-B')
    if ($DbPass -ne '') { $arguments += "-p$DbPass" }
    return (& $mysql @arguments -e $statement) -join "`n"
}
Write-Host "MySQL menjawab di ${DbHost}:${DbPort}"

# ---------- 2. .env ----------
Step 'Menyiapkan laravel-foundation\.env'
$envFile = Join-Path $app '.env'
function Set-EnvValue($lines, $key, $value) {
    $found = $false
    $result = foreach ($line in $lines) {
        if ($line -match "^\s*#?\s*$([regex]::Escape($key))=") { if (-not $found) { $found = $true; "$key=$value" } } else { $line }
    }
    if (-not $found) { $result = @($result) + "$key=$value" }
    return @($result)
}
if (Test-Path $envFile) {
    Write-Host '.env sudah ada, dipakai apa adanya (tidak ditimpa).'
} else {
    $lines = Get-Content (Join-Path $app '.env.example')
    Push-Location $app
    $key = (& $phpExe artisan key:generate --show --no-interaction).Trim()
    Pop-Location
    $randomKey = [Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }) -as [byte[]])
    $values = [ordered]@{
        APP_ENV = 'local'; APP_DEBUG = 'true'; APP_URL = "http://127.0.0.1:$Port"; APP_KEY = $key
        DB_CONNECTION = 'mysql'; DB_HOST = $DbHost; DB_PORT = "$DbPort"; DB_DATABASE = $DbName; DB_USERNAME = $DbUser; DB_PASSWORD = $DbPass
        JL_DB_HOST = $DbHost; JL_DB_PORT = "$DbPort"; JL_DB_NAME = $DbName; JL_DB_USER = $DbUser; JL_DB_PASS = $DbPass
        SESSION_DRIVER = 'file'; CACHE_STORE = 'file'; QUEUE_CONNECTION = 'database'
        JL_COMPANY_KEY = 'LOCAL-COMPANY'; JL_MARKETPLACE_OAUTH_MODE = 'sandbox'; JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY = $randomKey
        JL_BACKUP_DIR = (Join-Path $repo 'storage\local-backups'); JL_MYSQLDUMP = 'C:\xampp\mysql\bin\mysqldump.exe'
    }
    foreach ($name in $values.Keys) { $lines = Set-EnvValue $lines $name $values[$name] }
    Set-Content -Path $envFile -Value $lines -Encoding UTF8
    Write-Host ".env dibuat (database: $DbName di ${DbHost}:${DbPort}). File ini tidak ikut Git."
}

# ---------- 2b. mode integrasi ----------
if ($Live -or $Sandbox) {
    $mode = if ($Live) { 'live' } else { 'sandbox' }
    $current = Get-Content $envFile
    Set-Content -Path $envFile -Value (Set-EnvValue $current 'JL_MARKETPLACE_OAUTH_MODE' $mode) -Encoding UTF8
    Write-Host "Mode integrasi marketplace: $mode (JL_MARKETPLACE_OAUTH_MODE di .env)."
}
$modeNow = ((Get-Content $envFile | Where-Object { $_ -match '^JL_MARKETPLACE_OAUTH_MODE=' }) -replace '^JL_MARKETPLACE_OAUTH_MODE=', '')
if ($modeNow -eq 'live') {
    $text = Get-Content $envFile -Raw
    $missing = @('JL_SHOPEE_PARTNER_ID', 'JL_SHOPEE_PARTNER_KEY', 'JL_TIKTOK_SHOP_APP_KEY', 'JL_TIKTOK_SHOP_APP_SECRET', 'JL_TIKTOK_SHOP_SERVICE_ID') | Where-Object { $text -notmatch "(?m)^$_=\S" }
    if ($missing) { Write-Host ('Mode live aktif, tetapi belum terisi di .env: ' + ($missing -join ', ') + '. Isi kunci platform yang akan Anda uji, lalu jalankan ulang.') -ForegroundColor Yellow }
}

# ---------- 3. database ----------
Step "Memeriksa database $DbName"
$env:JL_DB_HOST = $DbHost; $env:JL_DB_PORT = "$DbPort"; $env:JL_DB_NAME = $DbName; $env:JL_DB_USER = $DbUser; $env:JL_DB_PASS = $DbPass
$env:JL_SETUP_ALLOW_NAME = $DbName
$env:JL_COMPANY_KEY = 'LOCAL-COMPANY'
$tables = 0
if ((Sql "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$DbName'") -ne '0') {
    $tables = [int](Sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DbName' AND table_name IN ('marketplace_orders','marketplace_stores','jl_data_produk')")
}
if ($tables -gt 0) {
    Write-Host "Database $DbName sudah berisi tabel Jago: tidak diubah. (Data Anda aman.)" -ForegroundColor Green
} else {
    Write-Host 'Database belum ada isinya: membuat skema, data Konveksi contoh, dan login owner...'
    & $phpExe (Join-Path $repo 'scripts\development\local-staging-setup.php') "--owner-password=$OwnerPassword"
    if ($LASTEXITCODE -ne 0) { Fail 'Pembuatan skema gagal (lihat pesan di atas).' }
    if ($Seed) {
        & $phpExe (Join-Path $repo 'scripts\development\local-staging-seed.php')
        if ($LASTEXITCODE -ne 0) { Fail 'Pengisian data contoh gagal.' }
        Sql "DELETE FROM $DbName.marketplace_product_ads WHERE store_id LIKE 'STR-%-JAGOIN'" | Out-Null
    }
}
Push-Location $app
if ((Sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DbName' AND table_name='jobs'") -eq '0') {
    & $phpExe artisan migrate --path=database/migrations/0001_01_01_000002_create_jobs_table.php --force --no-interaction | Out-Null
    Write-Host 'Tabel antrean dipasang.'
}
& $phpExe artisan marketplace:store-deletion --install --no-interaction 2>&1 | Out-Null
Pop-Location

# ---------- 5. tampilan ----------
Step 'Memeriksa tampilan (npm)'
if ($Rebuild -or -not (Test-Path (Join-Path $app 'public\build\manifest.json'))) {
    Push-Location $app
    if (-not (Test-Path 'node_modules')) { npm ci; if ($LASTEXITCODE -ne 0) { Pop-Location; Fail 'npm ci gagal. Pastikan Node.js terpasang.' } }
    npm run build
    if ($LASTEXITCODE -ne 0) { Pop-Location; Fail 'npm run build gagal.' }
    Pop-Location
} else { Write-Host 'Tampilan sudah dibangun (pakai -Rebuild untuk membangun ulang).' }

# ---------- 6. jalankan ----------
Step 'Menjalankan aplikasi'
$serve = "Set-Location '$app'; & '$phpExe' artisan serve --host=127.0.0.1 --port=$Port"
Start-Process powershell -ArgumentList '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle='Jago Laba - web'; $serve"
if (-not $NoWorker) {
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle='Jago Laba - antrean'; Set-Location '$app'; & '$phpExe' artisan queue:work --queue=marketplace,default --sleep=3"
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle='Jago Laba - penjadwal'; Set-Location '$app'; & '$phpExe' artisan schedule:work"
}
Start-Sleep -Seconds 4
Write-Host "`nSelesai. Buka: http://127.0.0.1:$Port" -ForegroundColor Green
Write-Host "Login: owner / $OwnerPassword   (hanya bila database dibuat oleh skrip ini)"
Write-Host 'Menutup aplikasi: tutup jendela PowerShell "Jago Laba - ..." yang terbuka.'
