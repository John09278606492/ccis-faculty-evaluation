<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/** Verify/stage a restore without touching the running application's database. */
class BackupRecovery
{
    public function protectKey(string $key, string $passphrase): string
    {
        if (mb_strlen($passphrase) < 12) {
            throw new RuntimeException('Use a backup recovery passphrase of at least 12 characters.');
        }
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $derived = hash_pbkdf2('sha256', $passphrase, $salt, 210000, 32, true);
        $encrypted = openssl_encrypt($key, 'aes-256-gcm', $derived, OPENSSL_RAW_DATA, $iv, $tag, 'ccis-backup-key-v1');
        if ($encrypted === false) {
            throw new RuntimeException('Could not protect the recovery key.');
        }

        return json_encode(['version' => 1, 'algorithm' => 'aes-256-gcm', 'iterations' => 210000,
            'salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($encrypted)], JSON_THROW_ON_ERROR);
    }

    public function recoverKey(string $envelope, string $passphrase, string $fingerprint): string
    {
        $data = json_decode($envelope, true, 10, JSON_THROW_ON_ERROR);
        if (($data['version'] ?? null) !== 1 || ($data['iterations'] ?? null) !== 210000 || ($data['algorithm'] ?? '') !== 'aes-256-gcm') {
            throw new RuntimeException('Unsupported recovery envelope.');
        }
        $derived = hash_pbkdf2('sha256', $passphrase, base64_decode($data['salt'], true), 210000, 32, true);
        $key = openssl_decrypt(base64_decode($data['ciphertext'], true), 'aes-256-gcm', $derived, OPENSSL_RAW_DATA,
            base64_decode($data['iv'], true), base64_decode($data['tag'], true), 'ccis-backup-key-v1');
        if (! is_string($key) || ! hash_equals($fingerprint, hash('sha256', $key))) {
            throw new RuntimeException('The recovery passphrase is incorrect or the key envelope was changed.');
        }

        return $key;
    }

    public function inspect(string $archive): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Cannot open the backup archive.');
        }
        try {
            $raw = $zip->getFromName('manifest.json');
            if (! is_string($raw) || strlen($raw) > 5_000_000) {
                throw new RuntimeException('A supported recovery manifest is missing.');
            }
            $manifest = json_decode($raw, true, 30, JSON_THROW_ON_ERROR);
            if (($manifest['version'] ?? null) !== 2 || ! is_array($manifest['entries'] ?? null) || $zip->numFiles > 50000) {
                throw new RuntimeException('Unsupported backup format.');
            }
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $this->safeEntry($name);
                if (isset($seen[$name])) {
                    throw new RuntimeException('Duplicate archive entry.');
                }
                $seen[$name] = true;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Symbolic links are not permitted in recovery archives.');
                }
                if ($name === 'manifest.json') {
                    continue;
                }
                $expected = $manifest['entries'][$name] ?? null;
                if (! is_array($expected) || ($expected['bytes'] ?? -1) !== $stat['size'] || $stat['size'] > 10_000_000_000) {
                    throw new RuntimeException('Unexpected or invalid backup entry: '.$name);
                }
                $stream = $zip->getStream($name);
                if (! $stream) {
                    throw new RuntimeException('Cannot read backup entry: '.$name);
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! hash_equals((string) $expected['sha256'], hash_final($hash))) {
                    throw new RuntimeException('Backup integrity check failed: '.$name);
                }
            }
            if (array_diff(array_keys($manifest['entries']), array_keys($seen))) {
                throw new RuntimeException('Backup files are missing.');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function stage(string $archive, string $destination, ?string $passphrase = null): array
    {
        $manifest = $this->inspect($archive);
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $destination)) {
            throw new RuntimeException('Choose an absolute, new staging directory.');
        }
        if (file_exists($destination)) {
            throw new RuntimeException('The staging directory must not already exist. Existing data was not changed.');
        }
        $parent = realpath(dirname($destination));
        if (! $parent) {
            throw new RuntimeException('The staging directory parent must exist.');
        }
        $destination = $parent.DIRECTORY_SEPARATOR.basename($destination);
        File::ensureDirectoryExists($destination, 0700);
        $zip = new ZipArchive;
        $zip->open($archive);
        try {
            foreach (array_keys($manifest['entries']) as $name) {
                $target = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
                File::ensureDirectoryExists(dirname($target), 0700);
                $input = $zip->getStream($name);
                $output = fopen($target, 'xb');
                if (! $input || ! $output) {
                    throw new RuntimeException('Could not write the staging directory.');
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                @chmod($target, 0600);
            }
            file_put_contents($destination.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if ($passphrase !== null) {
                $envelope = $zip->getFromName('recovery/application-key.enc.json');
                if (! is_string($envelope)) {
                    throw new RuntimeException('This backup has no protected key envelope. Supply the original APP_KEY separately.');
                }
                $key = $this->recoverKey($envelope, $passphrase, $manifest['application_key_fingerprint']);
                file_put_contents($destination.'/recovery/application-key.txt', $key);
                @chmod($destination.'/recovery/application-key.txt', 0600);
            }
            file_put_contents($destination.'/RESTORE-INSTRUCTIONS.md', $this->instructions($manifest));
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    private function safeEntry(string $name): void
    {
        if ($name === '' || str_contains($name, '\\') || str_contains($name, "\0") || str_starts_with($name, '/')
            || preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $name)) {
            throw new RuntimeException('Unsafe archive entry.');
        }
    }

    private function instructions(array $manifest): string
    {
        return "# Verified restore staging\n\nNothing has been written to the running application's database.\n\n"
            ."1. Use a new project installation and an empty destination database. Keep the live server and original backup intact.\n"
            ."2. Set the original APP_KEY from recovery/application-key.txt (if recovered) in the destination's private .env; never generate a replacement key. Do not publish this key. Clear cached configuration.\n"
            ."3. Import {$manifest['main_database']} into the empty destination database (SQLite: copy the file while the destination is stopped; MySQL: import the SQL with the destination credentials).\n"
            ."4. Copy files/private and files/public into the destination storage/app directories.\n"
            ."5. Install the destination student database service using its own new root credentials, then restore each workspace SQL into its matching database name from manifest.json. Do not copy old MySQL data folders across server versions. App-managed student accounts/privileges are recreated on opening a workspace.\n"
            ."6. Run migrations, classroom:assessments-maintain, and verify login, a protected evaluator identity, a completed SQL snapshot, uploaded files and a sample report before switching the campus address. Keep maintenance mode until all checks pass.\n\n"
            ."Workspace exports are logical per-database snapshots taken at the capture times recorded in the manifest. Active attempts can continue changing after backup; those later changes are not included.\n";
    }
}
