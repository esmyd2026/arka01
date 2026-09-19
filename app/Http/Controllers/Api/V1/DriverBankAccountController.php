<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DriverBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuentas bancarias del conductor desde la app móvil (roadmap app móvil,
 * "full backend" — paridad con la web). Sin servicio aparte: CRUD simple,
 * mismas validaciones que DriverBankAccountController (web).
 */
class DriverBankAccountController extends Controller
{
    private function rules(): array
    {
        return [
            'account_holder_name' => ['nullable', 'string', 'max:120'],
            'identity_number' => ['required', 'digits:10'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_type' => ['required', Rule::in(['ahorros', 'corriente'])],
            'account_number' => ['required', 'string', 'max:30'],
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['bank_accounts' => $request->user()->bankAccounts()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $user = $request->user();

        $validated['account_holder_name'] = filled($validated['account_holder_name'] ?? null)
            ? trim($validated['account_holder_name'])
            : $user->full_name;

        $validated['is_favorite'] = ($validated['is_favorite'] ?? false) || ! $user->bankAccounts()->exists();

        $bankAccount = $user->bankAccounts()->create($validated);

        return response()->json(['bank_account' => $bankAccount], 201);
    }

    public function update(Request $request, DriverBankAccount $bankAccount): JsonResponse
    {
        abort_unless($bankAccount->user_id === $request->user()->id, 403);

        $validated = $request->validate($this->rules());
        if (array_key_exists('account_holder_name', $validated) && ! filled($validated['account_holder_name'])) {
            $validated['account_holder_name'] = $bankAccount->account_holder_name ?: $request->user()->full_name;
        }

        $bankAccount->update($validated);

        return response()->json(['bank_account' => $bankAccount->fresh()]);
    }

    public function destroy(Request $request, DriverBankAccount $bankAccount): JsonResponse
    {
        abort_unless($bankAccount->user_id === $request->user()->id, 403);

        $bankAccount->delete();

        return response()->json(['message' => 'Cuenta bancaria eliminada.']);
    }

    public function markFavorite(Request $request, DriverBankAccount $bankAccount): JsonResponse
    {
        abort_unless($bankAccount->user_id === $request->user()->id, 403);

        $bankAccount->update(['is_favorite' => true]);

        return response()->json(['bank_account' => $bankAccount->fresh()]);
    }
}
