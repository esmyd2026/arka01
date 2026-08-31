<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CooperativeBankAccount extends Model
{
    protected $fillable = [
        'cooperative_id',
        'account_holder_name',
        'identity_number',
        'bank_name',
        'account_type',
        'account_number',
        'is_favorite',
    ];

    protected $casts = ['is_favorite' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(function (self $account) {
            if ($account->is_favorite) {
                self::query()
                    ->where('cooperative_id', $account->cooperative_id)
                    ->where('id', '!=', $account->id)
                    ->update(['is_favorite' => false]);
            }
        });

        static::deleted(function (self $account) {
            if ($account->is_favorite) {
                self::query()->where('cooperative_id', $account->cooperative_id)->latest('id')->first()
                    ?->update(['is_favorite' => true]);
            }
        });
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }
}
