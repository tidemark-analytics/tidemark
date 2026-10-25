<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUuids;

    public const SCOPES = ['read', 'full'];

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: self, 1: string} the model and the plain-text token (shown once) */
    public static function issue(User $user, string $name, string $scope = 'full'): array
    {
        $plain = 'tdm_'.Str::random(40);
        $key = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 8),
            'scope' => $scope,
        ]);

        return [$key, $plain];
    }

    /** A read-only key may only read statistics: the Stats API and the MCP endpoint. */
    public function allows(Request $request): bool
    {
        if ($this->scope !== 'read') {
            return true;
        }

        return ($request->is('api/v1/stats/*', 'api/v1/openapi.json') && in_array($request->method(), ['GET', 'HEAD'], true)) || ($request->is('api/mcp') && $request->isMethod('POST'));
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }
}
