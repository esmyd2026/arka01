<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SosAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'ride_id',
        'triggered_by',
        'driver_name',
        'driver_phone',
        'vehicle_plate',
        'vehicle_make',
        'vehicle_model',
        'lat',
        'lng',
        'notified_contacts_count',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
