<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Support\Installer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Browser installer for shared hosting where there is no SSH access. */
class InstallController extends Controller
{
    public function show()
    {
        abort_if(Installer::isInstalled(), 404);

        return view('install', [
            'requirements' => Installer::requirements(),
            'drivers' => collect(['sqlite' => extension_loaded('pdo_sqlite'), 'mysql' => extension_loaded('pdo_mysql'), 'pgsql' => extension_loaded('pdo_pgsql')]),
            'appUrl' => rtrim(url('/'), '/'),
        ]);
    }

    public function store(Request $request)
    {
        abort_if(Installer::isInstalled(), 404);

        $data = $request->validate([
            'driver' => 'required|in:sqlite,mysql,pgsql',
            'db_host' => 'nullable|string|max:255',
            'db_port' => 'nullable|string|max:8',
            'db_name' => 'nullable|string|max:255',
            'db_user' => 'nullable|string|max:255',
            'db_pass' => 'nullable|string|max:255',
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'password' => ['required', 'confirmed', Password::min(8)],
            'app_url' => 'nullable|url',
        ]);

        $env = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $data['app_url'] ?: url('/'),
            'DB_CONNECTION' => $data['driver'],
            'TIDEMARK_SALT' => Str::random(40),
        ];
        if ($data['driver'] === 'sqlite') {
            $path = database_path('database.sqlite');
            if (! file_exists($path)) {
                touch($path);
            }
            $env['DB_DATABASE'] = $path;
        } else {
            $env += [
                'DB_HOST' => $data['db_host'] ?: '127.0.0.1',
                'DB_PORT' => $data['db_port'] ?: ($data['driver'] === 'mysql' ? '3306' : '5432'),
                'DB_DATABASE' => $data['db_name'] ?? '',
                'DB_USERNAME' => $data['db_user'] ?? '',
                'DB_PASSWORD' => $data['db_pass'] ?? '',
            ];
        }

        // Verify credentials before persisting anything.
        config(['database.default' => $data['driver']]);
        foreach ($env as $k => $v) {
            if (str_starts_with($k, 'DB_')) {
                config(['database.connections.'.$data['driver'].'.'.match ($k) {
                    'DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_DATABASE' => 'database', 'DB_USERNAME' => 'username', 'DB_PASSWORD' => 'password', default => '_ignore'
                } => $v]);
            }
        }
        try {
            DB::purge($data['driver']);
            DB::connection($data['driver'])->getPdo();
        } catch (\Throwable $e) {
            return back()->withInput($request->except('password', 'password_confirmation', 'db_pass'))
                ->withErrors(['db' => 'Could not connect to the database: '.Str::limit($e->getMessage(), 200)]);
        }

        Installer::writeEnv($env);
        try {
            Installer::migrate();
        } catch (\Throwable $e) {
            return back()->withInput($request->except('password', 'password_confirmation', 'db_pass'))
                ->withErrors(['db' => 'Migration failed: '.Str::limit($e->getMessage(), 300)]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_admin' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Organization::personalFor($user);
        Installer::markInstalled();
        Auth::login($user);

        return redirect('/dashboard');
    }
}
