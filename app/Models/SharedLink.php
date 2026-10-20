<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class SharedLink extends Model
{
    use HasUuids;

    protected $fillable = ['site_id', 'name', 'slug', 'password_hash'];

    protected $hidden = ['password_hash'];

    protected $appends = ['password_protected'];

    public function getPasswordProtectedAttribute(): bool
    {
        return $this->password_hash !== null;
    }

    public function passwordMatches(?string $password): bool
    {
        return $this->password_hash === null || ($password !== null && Hash::check($password, $this->password_hash));
    }
}
