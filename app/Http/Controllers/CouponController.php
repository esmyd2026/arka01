<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de cupones y beneficios (pedido explícito del usuario): apartado
 * exclusivo por rol — cliente y conductor tienen promociones completamente
 * independientes. Cada cuenta es una cosa o la otra (sección 3.1), así que la
 * audiencia se resuelve del rol real, no de un selector manual.
 */
class CouponController extends Controller
{
    public function index(Request $request): Response
    {
        $audience = $request->user()->isDriver() ? 'driver' : 'client';

        return Inertia::render('Coupons/Index', [
            'audience' => $audience,
            'coupons' => Coupon::query()->where('audience', $audience)->visible()->orderBy('sort_order')->get(),
        ]);
    }
}
