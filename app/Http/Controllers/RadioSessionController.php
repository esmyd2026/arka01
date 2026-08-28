<?php

namespace App\Http\Controllers;

use App\Services\RadioAccessToken;
use App\Services\RideRadioAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RadioSessionController extends Controller
{
    public function status(Request $request, RideRadioAccess $access): JsonResponse
    {
        abort_unless($request->user()->isClient() || $request->user()->isDriver(), 403);

        $channels = $access->resolveAll($request->user());
        $context = $channels->first();

        return response()->json($context
            ? ['enabled' => true, ...$context, 'channels' => $channels]
            : ['enabled' => false]);
    }

    public function __invoke(Request $request, RadioAccessToken $tokens, RideRadioAccess $access): JsonResponse
    {
        abort_unless($request->user()->isClient() || $request->user()->isDriver(), 403);

        $channelPublicId = $request->validate([
            'channel_public_id' => ['nullable', 'uuid'],
        ])['channel_public_id'] ?? null;
        $context = $access->resolve($request->user(), $channelPublicId);

        abort_unless($context, 409, 'La radio solo está disponible durante una solicitud o carrera activa.');

        $issued = $tokens->issue($request->user(), $context['room_id']);

        return response()->json([
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            ...$context,
        ]);
    }
}
