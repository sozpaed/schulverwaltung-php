<?php

declare(strict_types=1);

use Schulverwaltung\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile ausführbar.');
}

require_once __DIR__ . '/../src/Database.php';

function backupSetting(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function optionValue(string $value): string
{
    if (str_contains($value, "\0")) {
        throw new RuntimeException('Die Datenbankkonfiguration enthält ein ungültiges Nullbyte.');
    }
    return '"' . strtr($value, [
        '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t',
    ]) . '"';
}

function backupDatabase(): void
{
    $root = dirname(__DIR__);
    $directory = backupSetting('BACKUP_DIR', $root . DIRECTORY_SEPARATOR . 'backup');
    $fingerprint = strtoupper(backupSetting('BACKUP_GPG_FINGERPRINT'));
    if (!preg_match('/\A(?:[A-F0-9]{40}|[A-F0-9]{64})\z/', $fingerprint)) {
        throw new RuntimeException('BACKUP_GPG_FINGERPRINT muss ein vollständiger GnuPG-Fingerabdruck sein.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Das Backup-Verzeichnis konnte nicht angelegt werden.');
    }
    $directory = realpath($directory);
    if ($directory === false || !is_writable($directory)) {
        throw new RuntimeException('Das Backup-Verzeichnis ist nicht zugänglich oder nicht beschreibbar.');
    }
    foreach ([$root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'web'] as $webRoot) {
        $webRoot = realpath($webRoot);
        if ($webRoot !== false && ($directory === $webRoot || str_starts_with($directory, $webRoot . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Das Backup-Verzeichnis darf nicht im Webverzeichnis liegen.');
        }
    }
    if (PHP_OS_FAMILY !== 'Windows' && (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('Das Backup-Verzeichnis darf nur für seinen Eigentümer zugänglich sein (chmod 700).');
    }

    $lock = fopen($directory . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Eine Sicherung läuft bereits oder die Sperrdatei ist nicht zugänglich.');
    }

    $config = Database::configuration();
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $setting) {
        if (!isset($config[$setting]) || !is_string($config[$setting])
            || ($setting !== 'DB_PASSWORD' && $config[$setting] === '')) {
            throw new RuntimeException($setting . ' fehlt in der Datenbankkonfiguration.');
        }
    }
    if (!Database::isValidDatabaseName($config['DB_NAME'])
        || !preg_match('/\A[A-Za-z0-9.:-]+\z/', $config['DB_HOST'])) {
        throw new RuntimeException('Datenbankname oder Host enthält ungültige Zeichen.');
    }
    $port = $config['DB_PORT'] ?? 3306;
    if ($port === '') {
        $port = 3306;
    }
    if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        throw new RuntimeException('DB_PORT muss zwischen 1 und 65535 liegen.');
    }

    $suffix = bin2hex(random_bytes(8));
    $base = 'schulverwaltung-' . gmdate('Ymd-His') . '-' . $suffix;
    $optionsPath = $directory . DIRECTORY_SEPARATOR . '.' . $base . '.cnf';
    $partialPath = $directory . DIRECTORY_SEPARATOR . '.' . $base . '.part';
    $finalPath = $directory . DIRECTORY_SEPARATOR . $base . '.sql.gpg';
    $oldUmask = umask(0077);
    $options = null;
    $output = null;
    $dump = null;
    $gpg = null;
    $dumpPipes = [];
    $gpgPipes = [];
    $dumpErrors = tmpfile();
    $gpgErrors = tmpfile();
    try {
        if ($dumpErrors === false || $gpgErrors === false) {
            throw new RuntimeException('Temporäre Fehlerprotokolle konnten nicht angelegt werden.');
        }
        $options = fopen($optionsPath, 'x+b');
        $output = fopen($partialPath, 'x+b');
        if ($options === false || $output === false) {
            throw new RuntimeException('Temporäre Backup-Dateien konnten nicht angelegt werden.');
        }
        $contents = "[client]\n"
            . 'host=' . optionValue($config['DB_HOST']) . "\n"
            . 'port=' . $port . "\n"
            . 'user=' . optionValue($config['DB_USER']) . "\n"
            . 'password=' . optionValue($config['DB_PASSWORD']) . "\n";
        if (fwrite($options, $contents) !== strlen($contents) || !fflush($options)) {
            throw new RuntimeException('Die temporäre Datenbankkonfiguration konnte nicht geschrieben werden.');
        }
        fclose($options);
        $options = null;
        unset($contents, $config['DB_PASSWORD']);

        $dump = proc_open([
            backupSetting('BACKUP_DUMP_BIN', 'mariadb-dump'),
            '--defaults-file=' . $optionsPath,
            '--protocol=tcp', '--single-transaction', '--quick', '--skip-lock-tables',
            '--no-tablespaces', '--default-character-set=utf8mb4', '--', $config['DB_NAME'],
        ], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $dumpErrors], $dumpPipes);
        if (!is_resource($dump)) {
            throw new RuntimeException('mariadb-dump konnte nicht gestartet werden.');
        }
        $gpg = proc_open([
            backupSetting('BACKUP_GPG_BIN', 'gpg'), '--batch', '--no-tty', '--trust-model', 'always',
            '--encrypt', '--recipient', $fingerprint,
        ], [0 => ['pipe', 'r'], 1 => $output, 2 => $gpgErrors], $gpgPipes);
        if (!is_resource($gpg)) {
            throw new RuntimeException('GnuPG konnte nicht gestartet werden.');
        }
        $copied = stream_copy_to_stream($dumpPipes[1], $gpgPipes[0]);
        fclose($dumpPipes[1]);
        unset($dumpPipes[1]);
        fclose($gpgPipes[0]);
        unset($gpgPipes[0]);
        $dumpStatus = proc_close($dump);
        $dump = null;
        $gpgStatus = proc_close($gpg);
        $gpg = null;
        if ($copied === false || $copied === 0 || $dumpStatus !== 0 || $gpgStatus !== 0 || !fflush($output)) {
            rewind($dumpErrors);
            rewind($gpgErrors);
            throw new RuntimeException(sprintf(
                "Sicherung fehlgeschlagen (Dump: %d, GnuPG: %d).\n%s%s",
                $dumpStatus, $gpgStatus, stream_get_contents($dumpErrors), stream_get_contents($gpgErrors)
            ));
        }
        fclose($output);
        $output = null;
        if (!unlink($optionsPath)) {
            throw new RuntimeException('Die temporäre Datei mit den Datenbankzugangsdaten konnte nicht entfernt werden.');
        }
        if (!rename($partialPath, $finalPath)) {
            throw new RuntimeException('Die fertige Sicherung konnte nicht abgelegt werden.');
        }

        $cutoff = time() - 30 * 86400;
        foreach (new DirectoryIterator($directory) as $file) {
            if ($file->isLink() || !$file->isFile()
                || !preg_match('/\Aschulverwaltung-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\.sql\.gpg\z/', $file->getFilename())
                || $file->getMTime() >= $cutoff || $file->getPathname() === $finalPath) {
                continue;
            }
            if (!unlink($file->getPathname())) {
                throw new RuntimeException('Alte Sicherung konnte nicht gelöscht werden: ' . $file->getFilename());
            }
        }
        fwrite(STDOUT, "Verschlüsselte Datenbanksicherung erstellt: " . $finalPath . "\n");
    } finally {
        foreach ([$dumpPipes, $gpgPipes] as $pipes) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        foreach ([$dump, $gpg] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach ([$options, $output, $dumpErrors, $gpgErrors] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        foreach ([$optionsPath, $partialPath] as $path) {
            if (is_file($path) && !unlink($path)) {
                fwrite(STDERR, "Temporäre Datei konnte nicht entfernt werden: " . $path . "\n");
            }
        }
        umask($oldUmask);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    backupDatabase();
} catch (RuntimeException $exception) {
    fwrite(STDERR, "Backup-Fehler: " . $exception->getMessage() . "\n");
    exit(1);
}
