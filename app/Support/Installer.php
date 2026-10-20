<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class Installer
{
    public static function flagPath(): string
    {
        return storage_path('app/installed');
    }

    public static function isInstalled(): bool
    {
        if (is_file(self::flagPath())) {
            return true;
        }
        try {
            if (User::query()->exists()) {
                self::markInstalled();

                return true;
            }
        } catch (\Throwable) {
            // no database / tables yet
        }

        return false;
    }

    public static function markInstalled(): void
    {
        @file_put_contents(self::flagPath(), now()->toIso8601String());
    }

    /** @return array<string,bool|string> requirement => ok */
    public static function requirements(): array
    {
        $writable = fn (string $p) => is_dir($p) && is_writable($p);

        return [
            'PHP >= 8.3' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'BCMath / mbstring / OpenSSL / PDO' => extension_loaded('mbstring') && extension_loaded('openssl') && extension_loaded('pdo'),
            'JSON, Ctype, Tokenizer, XML, Fileinfo' => extension_loaded('json') && extension_loaded('ctype') && extension_loaded('tokenizer') && extension_loaded('xml') && extension_loaded('fileinfo'),
            'storage/ writable' => $writable(storage_path()),
            'bootstrap/cache/ writable' => $writable(base_path('bootstrap/cache')),
            '.env writable' => is_writable(base_path('.env')) || (! file_exists(base_path('.env')) && is_writable(base_path())),
        ];
    }

    /** Persist key=value pairs into .env, preserving everything else. */
    public static function writeEnv(array $values): void
    {
        $path = base_path('.env');
        $env = is_file($path) ? file_get_contents($path) : '';
        foreach ($values as $key => $value) {
            $value = (string) $value;
            if ($value === '' || preg_match('/[\s#"\'\\\\$]/', $value)) {
                $value = '"'.addcslashes($value, '"\$').'"';
            }
            $line = "{$key}={$value}";
            if (preg_match("/^{$key}=.*$/m", $env)) {
                $env = preg_replace("/^{$key}=.*$/m", str_replace('\\', '\\\\', str_replace('$', '\\$', $line)), $env);
            } else {
                $env = rtrim($env)."\n{$line}\n";
            }
        }
        file_put_contents($path, $env);
    }

    public static function migrate(): string
    {
        DB::purge();
        Artisan::call('migrate', ['--force' => true]);

        return Artisan::output();
    }
}
