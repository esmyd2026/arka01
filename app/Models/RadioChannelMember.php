<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadioChannelMember extends Model
{
    use HasFactory;

    protected $fillable = ['radio_channel_id', 'user_id', 'joined_at'];

    protected $casts = ['joined_at' => 'datetime'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(RadioChannel::class, 'radio_channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
