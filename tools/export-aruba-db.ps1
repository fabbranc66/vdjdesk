param(
    [string]$Output = "storage\aruba-vdjdesk.sql",
    [string]$LocalDatabase = "vdjdesk",
    [string]$MysqlDump = "C:\xampp\mysql\bin\mysqldump.exe"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$outputPath = Join-Path $root $Output
$outputDir = Split-Path -Parent $outputPath

if (!(Test-Path $MysqlDump)) {
    $fallback = "C:\xampp2\mysql\bin\mysqldump.exe"
    if (Test-Path $fallback) {
        $MysqlDump = $fallback
    }
}

if (!(Test-Path $MysqlDump)) {
    throw "mysqldump non trovato. Passa -MysqlDump con il percorso corretto."
}

if (!(Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

& $MysqlDump `
    --user=root `
    --host=127.0.0.1 `
    --default-character-set=utf8mb4 `
    --single-transaction `
    --routines `
    --triggers `
    --skip-add-locks `
    --no-create-db `
    $LocalDatabase |
    Set-Content -Path $outputPath -Encoding UTF8

Write-Host "Export creato: $outputPath"
Write-Host "Importalo su Aruba nel database Sql1874742_4."
