<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Código de socio secuencial desde el 500 (consideración agregada al
 * alcance) — ver la migración `member_code_sequences` sobre por qué no se
 * puede usar el AUTO_INCREMENT nativo de `users.id` para esto.
 */
class MemberCodeSequence
{
    public static function next(): int
    {
        return DB::transaction(function () {
            $row = DB::table('member_code_sequences')->lockForUpdate()->first();

            DB::table('member_code_sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + 1]);

            return (int) $row->next_value;
        });
    }
}
