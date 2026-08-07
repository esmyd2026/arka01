<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una apertura de la ventana de 24 horas de WhatsApp (pedido explícito del
 * usuario): el estado (activa / próxima a vencer / expirada) se calcula
 * siempre a partir de `expires_at` contra la hora actual — no se guarda un
 * campo "status" aparte que se pueda desincronizar ni hace falta un job
 * programado para "marcarla expirada"; comparar la fecha en el momento de
 * usarla (enviar un mensaje, o mostrarla en pantalla) siempre da el estado
 * real, así que guardar y mantener un status es una capa de más.
 */
class WhatsAppSession extends Model
{
    use HasFactory;

    // Eloquent infiere el nombre de tabla separando por mayúsculas
    // ("WhatsApp" → "whats_app"), distinto de la tabla real — se fija a mano.
    protected $table = 'whatsapp_sessions';

    protected $fillable = [
        'user_id',
        'opened_at',
        'expires_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Cuánto falta para que Meta exija volver a escribir "Hola" (pedido
     * explícito del usuario: avisar cuando está "próxima a vencer").
     */
    private const EXPIRING_SOON_THRESHOLD_HOURS = 2;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function isExpiringSoon(): bool
    {
        return $this->isActive() && now()->diffInHours($this->expires_at, false) <= self::EXPIRING_SOON_THRESHOLD_HOURS;
    }

    /**
     * 'active' | 'expiring_soon' | 'expired' — pedido explícito del usuario
     * ("Estado: Activa / Próxima a vencer / Expirada").
     */
    public function status(): string
    {
        return match (true) {
            ! $this->isActive() => 'expired',
            $this->isExpiringSoon() => 'expiring_soon',
            default => 'active',
        };
    }
}
