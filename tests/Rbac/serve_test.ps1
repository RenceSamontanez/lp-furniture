# Starts the app for the Stage 11 browser walk against the SCRATCH database only.
# Refuses to start unless the database name resolved by the app ends in _test.
Set-Location (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))

$env:DB_DATABASE = 'furniture_db_test'
$env:APP_URL = 'http://127.0.0.1:8011'
$env:APP_ENV = 'local'

$name = (php artisan tinker --execute="echo config('database.connections.' . config('database.default') . '.database');" | Out-String).Trim()

if (-not $name.EndsWith('_test')) {
    Write-Error "REFUSING to start: the app resolved database '$name', not a _test database."
    exit 99
}

Write-Host "Serving on http://127.0.0.1:8011 against database: $name"
$router = (Resolve-Path 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php').Path
Set-Location public
php -S 127.0.0.1:8011 -t . $router
