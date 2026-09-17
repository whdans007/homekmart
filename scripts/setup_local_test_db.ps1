param(
    [string]$DumpFile = "u622428657_homekmart.sql",
    [string]$Database = "u622428657_main",
    [int]$Port = 3306
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$dumpPath = Join-Path $repoRoot $DumpFile
if (-not (Test-Path -LiteralPath $dumpPath -PathType Leaf)) {
    throw "SQL dump was not found: $dumpPath"
}

$mysqlCandidates = @(
    'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe',
    'C:\laragon\bin\mysql\mariadb-11.8.8-winx64\bin\mariadb.exe'
)
if ($env:LARAGON_ROOT) {
    $mysqlCandidates += Join-Path $env:LARAGON_ROOT 'bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe'
    $mysqlCandidates += Join-Path $env:LARAGON_ROOT 'bin\mysql\mariadb-11.8.8-winx64\bin\mariadb.exe'
}
$mysqlCandidates = $mysqlCandidates | Where-Object { Test-Path -LiteralPath $_ }
$mysql = $mysqlCandidates | Select-Object -First 1
if (-not $mysql) { throw 'Laragon MySQL/MariaDB client was not found.' }

Write-Host "Recreating local database '$Database' on 127.0.0.1:$Port..."
$createSql = "DROP DATABASE IF EXISTS ``$Database``; CREATE DATABASE ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
& $mysql --host=127.0.0.1 --port=$Port --user=root --execute=$createSql
if ($LASTEXITCODE -ne 0) { throw 'Could not create the local database.' }

$quotedMysql = '"' + $mysql + '"'
$quotedDump = '"' + $dumpPath + '"'
$command = "$quotedMysql --host=127.0.0.1 --port=$Port --user=root $Database < $quotedDump"
cmd.exe /d /s /c $command
if ($LASTEXITCODE -ne 0) { throw 'The SQL dump import failed.' }

$migrations = @(
    'create_inventory_expirations.sql',
    'create_expiry_management_tables.sql'
)
foreach ($migration in $migrations) {
    $migrationPath = Join-Path $repoRoot $migration
    if (-not (Test-Path -LiteralPath $migrationPath -PathType Leaf)) { continue }
    $quotedMigration = '"' + $migrationPath + '"'
    $migrationCommand = "$quotedMysql --host=127.0.0.1 --port=$Port --user=root $Database < $quotedMigration"
    cmd.exe /d /s /c $migrationCommand
    if ($LASTEXITCODE -ne 0) { throw "The migration failed: $migration" }
}

$localConfigPath = Join-Path $repoRoot 'config\db_config.local.php'
 $localConfig = @"
<?php
// Generated for the local Laragon test server. This file is ignored by Git.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '$Database');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
"@
[System.IO.File]::WriteAllText($localConfigPath, $localConfig, [System.Text.UTF8Encoding]::new($false))

Write-Host "Local database '$Database' is ready."
