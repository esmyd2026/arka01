<?php

namespace App\Http\Controllers;

use App\Models\CooperativeBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CooperativeBankAccountController extends Controller
{
    private function rules(): array
    {
        return [
            'account_holder_name' => ['required', 'string', 'max:150'],
            'identity_number' => ['required', 'string', 'max:20'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_type' => ['required', Rule::in(['ahorros', 'corriente'])],
            'account_number' => ['required', 'string', 'max:30'],
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();
        $validated = $request->validate($this->rules());
        $validated['is_favorite'] = ($validated['is_favorite'] ?? false) || ! $cooperative->bankAccounts()->exists();
        $cooperative->bankAccounts()->create($validated);

        return back()->with('status', 'Cuenta bancaria de la cooperativa agregada.');
    }

    public function destroy(Request $request, CooperativeBankAccount $bankAccount): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();
        abort_unless($bankAccount->cooperative_id === $cooperative->id, 403);
        $bankAccount->delete();

        return back()->with('status', 'Cuenta bancaria eliminada.');
    }

    public function markFavorite(Request $request, CooperativeBankAccount $bankAccount): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();
        abort_unless($bankAccount->cooperative_id === $cooperative->id, 403);
        $bankAccount->update(['is_favorite' => true]);

        return back()->with('status', 'Cuenta principal actualizada.');
    }
}
