<?php

namespace App\Http\Controllers;

use App\Models\DriverBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pedido explícito del usuario: el conductor declara varias cuentas
 * bancarias en su perfil (cédula, banco, tipo de cuenta, número de cuenta) y
 * marca una como favorita. Aparte de DriverProfileController::update()
 * (formulario monolítico 1:1) porque esto es una lista de filas propia, con
 * su propio ciclo de alta/baja — mismo criterio que ya separa, por ejemplo,
 * SavedRouteController del resto del perfil del cliente.
 */
class DriverBankAccountController extends Controller
{
    private function rules(): array
    {
        return [
            'account_holder_name' => ['nullable', 'string', 'max:120'],
            // Cédula ecuatoriana: 10 dígitos. No se valida el dígito
            // verificador a propósito — el resto del proyecto tampoco lo
            // hace para el documento de identidad (ese va como archivo
            // adjunto, no como texto), no corresponde exigir acá más de lo
            // que ya se exige ahí.
            'identity_number' => ['required', 'digits:10'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_type' => ['required', Rule::in(['ahorros', 'corriente'])],
            'account_number' => ['required', 'string', 'max:30'],
            // Pedido explícito del usuario: puede tener varias, pero la
            // primera que declara tiene que quedar como favorita de una —
            // si no, el cliente no vería ninguna cuenta priorizada.
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $user = $request->user();

        // La web lo precarga para que sea rápido, pero el servidor conserva
        // el mismo comportamiento para clientes API o formularios antiguos.
        // Es editable porque la cuenta puede pertenecer a otra persona.
        $validated['account_holder_name'] = filled($validated['account_holder_name'] ?? null)
            ? trim($validated['account_holder_name'])
            : $user->full_name;

        $validated['is_favorite'] = ($validated['is_favorite'] ?? false) || ! $user->bankAccounts()->exists();

        $user->bankAccounts()->create($validated);

        return back()->with('status', 'Cuenta bancaria agregada.');
    }

    public function update(Request $request, DriverBankAccount $bankAccount): RedirectResponse
    {
        abort_unless($bankAccount->user_id === $request->user()->id, 403);

        $validated = $request->validate($this->rules());
        if (array_key_exists('account_holder_name', $validated) && ! filled($validated['account_holder_name'])) {
            $validated['account_holder_name'] = $bankAccount->account_holder_name ?: $request->user()->full_name;
        }

        $bankAccount->update($validated);

        return back()->with('status', 'Cuenta bancaria actualizada.');
    }

    public function destroy(Request $request, DriverBankAccount $bankAccount): RedirectResponse
    {
        abort_unless($bankAccount->user_id === $request->user()->id, 403);

        $bankAccount->delete();

        return back()->with('status', 'Cuenta bancaria eliminada.');
    }

    /**
     * Marcar una cuenta puntual como favorita — separado de update() para
     * que el frontend no tenga que reenviar los demás campos solo por
     * cambiar cuál está priorizada.
     */
    public function markFavorite(Request $request, DriverBankAccount $bankAccount): RedirectResponse
    {
        abort_unless($bankAccount->user_id === $request->user()->id, 403);

        $bankAccount->update(['is_favorite' => true]);

        return back()->with('status', 'Cuenta marcada como favorita.');
    }
}
