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

$secureDatabasePassword = Read-Host 'MariaDB-Passwort für schulverwaltung' -AsSecureString
$databasePasswordPointer = [IntPtr]::Zero
$adminPasswordPointer = [IntPtr]::Zero
$adminPasswordConfirmationPointer = [IntPtr]::Zero
$plainAdminPassword = $null
$plainAdminPasswordConfirmation = $null

try {
    $databasePasswordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureDatabasePassword)
    $env:DB_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($databasePasswordPointer)

    Write-Host ''
    Write-Host '1. Neues Administratorkonto anlegen'
    Write-Host '2. Bestehendes Konto zum Administrator machen (Passwort bleibt unverändert)'
    $mode = Read-Host 'Bitte 1 oder 2 eingeben'

    if ($mode -eq '1') {
        $username = Read-Host 'Neuer Benutzername'
        $secureAdminPassword = Read-Host 'Neues Passwort (mindestens 12 Zeichen)' -AsSecureString
        $secureAdminPasswordConfirmation = Read-Host 'Neues Passwort wiederholen' -AsSecureString
        try {
            $adminPasswordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureAdminPassword)
            $adminPasswordConfirmationPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureAdminPasswordConfirmation)
            $plainAdminPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($adminPasswordPointer)
            $plainAdminPasswordConfirmation = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($adminPasswordConfirmationPointer)

            if ($plainAdminPassword -cne $plainAdminPasswordConfirmation) {
                throw 'Die Passwörter stimmen nicht überein.'
            }

            $env:APP_ADMIN_PASSWORD = $plainAdminPassword
        } finally {
            if ($adminPasswordPointer -ne [IntPtr]::Zero) {
                [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($adminPasswordPointer)
            }
            if ($adminPasswordConfirmationPointer -ne [IntPtr]::Zero) {
                [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($adminPasswordConfirmationPointer)
            }
            $secureAdminPassword.Dispose()
            $secureAdminPasswordConfirmation.Dispose()
        }
    } elseif ($mode -eq '2') {
        $username = Read-Host 'Benutzername des bestehenden aktiven Kontos'
    } else {
        throw 'Ungültige Auswahl. Das Setup wurde abgebrochen.'
    }

    if ([string]::IsNullOrWhiteSpace($username)) {
        throw 'Der Benutzername darf nicht leer sein.'
    }

    $action = if ($mode -eq '1') { 'create' } else { 'promote' }
    & $php `
        -d "extension_dir=$phpDirectory\ext" `
        -d 'extension=pdo_mysql' `
        (Join-Path $PSScriptRoot 'bin\setup-admin.php') `
        $action `
        $username

    if ($LASTEXITCODE -ne 0) {
        throw 'Das Administratorkonto konnte nicht eingerichtet werden. Prüfe die Meldung und DB_* Einstellungen.'
    }
} finally {
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:APP_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    if ($databasePasswordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($databasePasswordPointer)
    }
    $plainAdminPassword = $null
    $plainAdminPasswordConfirmation = $null
    $secureDatabasePassword.Dispose()
}
