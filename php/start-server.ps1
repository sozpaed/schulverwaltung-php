$ErrorActionPreference = 'Stop'

$phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
if ($null -ne $phpCommand) {
    $php = $phpCommand.Source
} else {
    $packageRoot = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
    $phpPackage = Get-ChildItem $packageRoot -Directory -Filter 'PHP.PHP.8.3_*' |
        Sort-Object Name -Descending |
        Select-Object -First 1

    if ($null -eq $phpPackage) {
        throw 'PHP 8.3 wurde nicht gefunden. Installiere PHP oder ergänze php.exe im PATH.'
    }

    $php = Join-Path $phpPackage.FullName 'php.exe'
}

$phpDirectory = Split-Path -Parent $php
$pdoMysqlExtension = Join-Path $phpDirectory 'ext\php_pdo_mysql.dll'
if (-not (Test-Path $pdoMysqlExtension)) {
    throw "Die PDO-MySQL-Erweiterung wurde nicht gefunden: $pdoMysqlExtension"
}

$modules = & $php -d "extension_dir=$phpDirectory\ext" -d 'extension=pdo_mysql' -m
if ($LASTEXITCODE -ne 0 -or $modules -notcontains 'pdo_mysql') {
    throw 'Die PHP-Erweiterung pdo_mysql konnte nicht geladen werden.'
}

$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_NAME = 'schulverwaltung_dev'
$env:DB_USER = 'schulverwaltung'

$securePassword = Read-Host 'MariaDB-Passwort für schulverwaltung' -AsSecureString
$passwordPointer = [IntPtr]::Zero

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $env:DB_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)

    Write-Host 'PHP-Server läuft unter http://127.0.0.1:8000/ (mit Strg+C beenden).'
    & $php `
        -d "extension_dir=$phpDirectory\ext" `
        -d 'extension=pdo_mysql' `
        -S '127.0.0.1:8000' `
        -t (Join-Path $PSScriptRoot 'public')
} finally {
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    $securePassword.Dispose()
}
