<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RadioChannel extends Model
{
    use HasFactory;

    protected $fillable = ['owner_user_id', 'name', 'share_code'];

    protected static function booted(): void
    {
        static::creating(function (RadioChannel $channel): void {
            $channel->public_id ??= (string) Str::uuid();
            $channel->share_code ??= Str::random(48);
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(RadioChannelMember::class);
    }

    public function rotateShareCode(): void
    {
        do {
            $code = Str::random(48);
        } while (self::query()->where('share_code', $code)->exists());

        $this->forceFill(['share_code' => $code])->save();
    }
}
