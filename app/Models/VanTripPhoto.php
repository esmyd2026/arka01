<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class VanTripPhoto extends Model
{
    use HasFactory;

    protected $appends = ['photo_url'];

    protected $fillable = [
        'van_trip_id',
        'photo_path',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(VanTrip::class, 'van_trip_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
