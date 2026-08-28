<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TrustCircleConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_user_id',
        'addressee_user_id',
        'status',
        'responded_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (TrustCircleConnection $connection) {
            $connection->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_user_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TrustCircleSetting::class, 'connection_id');
    }

    public function includes(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->requester_user_id === $userId || $this->addressee_user_id === $userId;
    }

    public function otherUser(User|int $user): User
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->requester_user_id === $userId ? $this->addressee : $this->requester;
    }
}
