<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido explícito del usuario: el conductor declara varias cuentas
 * bancarias en su perfil y marca una como favorita — el cliente las ve (la
 * favorita primero) cuando la carrera es por transferencia y el conductor
 * va en camino a recogerlo (ver Ride/Show.vue).
 */
class DriverBankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'account_holder_name',
        'identity_number',
        'bank_name',
        'account_type',
        'account_number',
        'is_favorite',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Pedido explícito del usuario: una sola favorita por conductor —
        // marcar una nueva como favorita desmarca automáticamente las demás
        // de ese mismo conductor, sin que el frontend tenga que orquestarlo.
        static::saved(function (DriverBankAccount $account) {
            if ($account->is_favorite) {
                self::query()
                    ->where('user_id', $account->user_id)
                    ->where('id', '!=', $account->id)
                    ->update(['is_favorite' => false]);
            }
        });

        // Bug evitado a propósito: si se borra la única (o la última)
        // cuenta favorita y quedan otras, ninguna quedaría marcada — el
        // conductor se quedaría sin favorita sin haberlo decidido. Se
        // promueve automáticamente la más reciente de las que quedan.
        static::deleted(function (DriverBankAccount $account) {
            if (! $account->is_favorite) {
                return;
            }

            self::query()->where('user_id', $account->user_id)->latest('id')->first()
                ?->update(['is_favorite' => true]);
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Catálogo de bancos de Ecuador para el selector del formulario (pedido
     * explícito del usuario: "que sea un seleccionable... y coloques los
     * principales primero") — los primeros son los de mayor tamaño/uso real
     * en el país; el resto sigue orden alfabético. `bank_name` en la base
     * sigue siendo texto libre a propósito (no un enum): "Otro" en el
     * frontend deja escribir un nombre que no esté en esta lista, sin que
     * eso rompa la validación ni deje afuera bancos nuevos o cooperativas.
     *
     * @return array<int, string>
     */
    public static function banks(): array
    {
        return [
            // Principales, por tamaño/uso real en Ecuador.
            'Banco Pichincha',
            'Banco Guayaquil',
            'Banco del Pacífico',
            'Produbanco',
            'Banco Bolivariano',
            'Banco Internacional',
            'Banco del Austro',
            'Banco de Machala',
            'Banco Amazonas',
            // Resto, orden alfabético.
            'BanEcuador',
            'Banco Capital',
            'Banco Coopnacional',
            'Banco D-MIRO',
            'Banco de Loja',
            'Banco Diners Club',
            'Banco Finca',
            'Banco General Rumiñahui',
            'Banco ProCredit',
            'Banco Solidario',
            'Banco VisionFund Ecuador',
            'Citibank N.A. Sucursal Ecuador',
            'Cooperativa JEP',
            'Otro',
        ];
    }

    /**
     * Confidencialidad (mismo criterio que DriverProfile::maskedPlate()):
     * el cliente que va a transferirle nunca necesita ver la cédula
     * completa del titular, solo lo suficiente para confirmar que la
     * cuenta es de la persona correcta.
     */
    public function maskedIdentityNumber(): string
    {
        return str_repeat('x', max(0, strlen($this->identity_number) - 3)).substr($this->identity_number, -3);
    }
}
