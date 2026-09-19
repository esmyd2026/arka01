<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centro de cupones y beneficios desde la app móvil (roadmap app móvil,
 * "full backend" — paridad con la web). Sin servicio aparte: es una lectura
 * simple sin ninguna regla de negocio real, mismo filtro que
 * CouponController (web).
 */
class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $audience = $request->user()->isDriver() ? 'driver' : 'client';

        return response()->json([
            'audience' => $audience,
            'coupons' => Coupon::query()->where('audience', $audience)->visible()->orderBy('sort_order')->get(),
        ]);
    }
}
