<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RefreshToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired()
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isExpired();
    }

    public static function generateToken()
    {
        return Str::random(64);
    }

    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public static function createForUser(User $user): self
    {
        // Eliminar refresh tokens antiguos del usuario
        self::where('user_id', $user->id)->delete();

        // Asegurar que sea entero
        $expirationDays = (int) config('auth.refresh_token_expiration', 7);

        return self::create([
            'user_id' => $user->id,
            'token' => self::generateToken(),
            'expires_at' => Carbon::now()->addDays($expirationDays),
        ]);
    }
}
