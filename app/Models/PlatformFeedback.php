<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una opinión mandada desde "Ayúdanos a mejorar ARKA01" en la página pública
 * (sección 14 del roadmap de mejoras) — ver Admin\PlatformFeedbackController.
 */
class PlatformFeedback extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'type',
        'comment',
        'status',
        'internal_notes',
    ];
}
