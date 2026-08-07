<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mantenimiento del centro de cupones (pedido explícito del usuario): cupones
 * ilimitados, separados por audiencia (cliente/conductor), cada uno con
 * imagen, título, descripción, botón, enlace, vigencia y estado.
 */
class CouponController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Coupons', [
            'coupons' => Coupon::query()->orderBy('audience')->orderBy('sort_order')->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCoupon($request);

        $validated['image_path'] = $request->file('image')->store('coupons', 'public');

        Coupon::query()->create($validated);

        return back()->with('status', 'Cupón creado.');
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $validated = $this->validateCoupon($request, isUpdate: true);

        if ($request->hasFile('image')) {
            Storage::disk('public')->delete($coupon->image_path);
            $validated['image_path'] = $request->file('image')->store('coupons', 'public');
        }

        $coupon->update($validated);

        return back()->with('status', 'Cupón actualizado.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        Storage::disk('public')->delete($coupon->image_path);
        $coupon->delete();

        return back()->with('status', 'Cupón eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCoupon(Request $request, bool $isUpdate = false): array
    {
        $validated = $request->validate([
            'audience' => ['required', Rule::in(['client', 'driver'])],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'button_label' => ['nullable', 'string', 'max:50'],
            'button_url' => ['nullable', 'url', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'image' => [$isUpdate ? 'nullable' : 'required', 'image', 'max:4096'],
        ]);

        unset($validated['image']);
        $validated['is_active'] ??= true;
        $validated['sort_order'] ??= 0;

        return $validated;
    }
}
