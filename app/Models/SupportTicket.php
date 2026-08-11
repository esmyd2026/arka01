<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un hilo de "Hablar con soporte" (sección 12 del roadmap de mejoras) — un
 * usuario tiene, como mucho, un ticket sin cerrar a la vez (ver
 * openOrCreateFor()), con historial completo de la conversación.
 */
class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    /**
     * Retoma el ticket abierto de este usuario si ya tenía uno (no cerrado);
     * si no, crea uno nuevo — así "Hablar con soporte" nunca duplica hilos.
     */
    public static function openOrCreateFor(User $user): self
    {
        return self::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cerrado')
            ->latest()
            ->first()
            ?? self::query()->create(['user_id' => $user->id, 'status' => 'nuevo']);
    }

    public function isClosed(): bool
    {
        return $this->status === 'cerrado';
    }
}
