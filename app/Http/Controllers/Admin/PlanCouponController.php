<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanCoupon;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cupones de descuento para suscripciones (pedido explícito del usuario:
 * "generar cupones de descuentos... para clientes y para conductores como
 * para cooperativa... si el cupon cubre el 100 o 50"). Mismo esqueleto que
 * Admin\PlanPromotionController (index/store/update/destroy, validación en
 * un método privado) — ver App\Models\PlanCoupon para el porqué del nombre.
 */
class PlanCouponController extends Controller
{
    public function index(): Response
    {
        $coupons = PlanCoupon::query()->with(['createdBy', 'referrer'])->latest()->get();

        // Usos reales, siempre recalculados (nunca un contador guardado que
        // se pueda desincronizar) — ver PlanCoupon::redemptionsCount().
        $coupons->each(fn (PlanCoupon $coupon) => $coupon->redemptions_count = $coupon->redemptionsCount());

        return Inertia::render('Admin/PlanCoupons', [
            'coupons' => $coupons,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCoupon($request);

        PlanCoupon::query()->create([
            ...$validated,
            'created_by_admin_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Cupón creado.');
    }

    public function update(Request $request, PlanCoupon $planCoupon): RedirectResponse
    {
        $validated = $this->validateCoupon($request, $planCoupon);

        $planCoupon->update($validated);

        return back()->with('status', 'Cupón actualizado.');
    }

    /**
     * Pedido explícito del usuario: poder desactivarlo sin borrarlo (para
     * conservar el historial de a quién se le aplicó) — atajo de un solo
     * clic en vez de abrir el formulario completo de edición.
     */
    public function toggle(PlanCoupon $planCoupon): RedirectResponse
    {
        $planCoupon->update(['is_active' => ! $planCoupon->is_active]);

        return back()->with('status', $planCoupon->is_active ? 'Cupón activado.' : 'Cupón desactivado.');
    }

    public function destroy(PlanCoupon $planCoupon): RedirectResponse
    {
        $planCoupon->delete();

        return back()->with('status', 'Cupón eliminado.');
    }

    /**
     * Buscar a quién atribuirle el cupón como referidor (pedido explícito
     * del usuario) — por nombre, usuario o código de socio, cualquier rol
     * (el referido puede terminar siendo cliente, conductor o cooperativa,
     * no hace falta que coincida con el owner_type del cupón). Panel admin,
     * de confianza: a diferencia de los buscadores del lado usuario, acá sí
     * alcanza con LIKE por nombre — no hay nada que "proteger" de un admin.
     */
    public function searchReferrer(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = ltrim($validated['q'], '@');

        $users = User::query()
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%");

                if (ctype_digit($term)) {
                    $query->orWhere('member_code', (int) $term);
                }
            })
            ->limit(10)
            ->get(['id', 'name', 'username', 'member_code', 'role']);

        return response()->json(['users' => $users]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCoupon(Request $request, ?PlanCoupon $current = null): array
    {
        // Bug real: el código se guarda siempre en mayúsculas (más abajo),
        // pero la unicidad se validaba con el valor tal cual lo escribió el
        // admin — "yaexiste" no chocaba contra "YAEXISTE" en la validación,
        // y recién reventaba con un error de SQL crudo al insertar, ya
        // convertido. Se normaliza ACÁ, antes de validar, para que la regla
        // `unique` compare lo mismo que se va a guardar.
        if ($request->filled('code')) {
            $request->merge(['code' => mb_strtoupper($request->string('code'))]);
        }

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('plan_coupons', 'code')->ignore($current?->id),
            ],
            'owner_type' => ['required', Rule::in(['driver', 'client', 'cooperative'])],
            'discount_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'label' => ['nullable', 'string', 'max:255'],
            'referrer_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $validated['is_active'] ??= true;

        return $validated;
    }
}
