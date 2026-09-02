<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Models\VanTrip;
use Illuminate\Support\Facades\Storage;

/**
 * Borra del disco todo archivo que un usuario haya subido — avatar, fotos de
 * licencia/vehículo, fotos de sus Viajes en VAN publicados, comprobantes de
 * pago. Nada de esto lo borra el cascade de las FKs (eso solo se lleva las
 * filas de la base), así que hay que llamarlo ANTES de borrar al usuario.
 * Antes vivía solo, duplicada, en Admin\SystemController (reinicio de demo)
 * — se extrajo acá porque Admin\UserProfileController (borrar una cuenta
 * real) necesita exactamente lo mismo.
 */
class UserFileCleanup
{
    public static function purge(User $user): void
    {
        // Foto de perfil: puede ser una URL externa (login con Google), ahí
        // no hay nada que borrar del disco — mismo criterio que ProfileController.
        if ($user->avatar_path && ! str_starts_with($user->avatar_path, 'http')) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        if ($user->driverProfile) {
            if ($user->driverProfile->identity_document_path) {
                Storage::disk('local')->delete($user->driverProfile->identity_document_path);
            }
            if ($user->driverProfile->license_photo_path) {
                Storage::disk('local')->delete($user->driverProfile->license_photo_path);
            }
            if ($user->driverProfile->police_record_path) {
                Storage::disk('local')->delete($user->driverProfile->police_record_path);
            }
            if ($user->driverProfile->vehicle_registration_path) {
                Storage::disk('local')->delete($user->driverProfile->vehicle_registration_path);
            }
            if ($user->driverProfile->vehicle_photo_path) {
                Storage::disk('public')->delete($user->driverProfile->vehicle_photo_path);
            }

            // Fotos de sus Viajes en VAN publicados, si publicó alguno.
            VanTrip::query()
                ->where('driver_user_id', $user->id)
                ->with('photos')
                ->get()
                ->each(function (VanTrip $trip) {
                    $trip->photos->each(fn ($photo) => Storage::disk('public')->delete($photo->photo_path));
                });
        }

        // Comprobantes de pago subidos — disco privado, ver SubscriptionRequestController.
        SubscriptionRequest::query()
            ->where('user_id', $user->id)
            ->whereNotNull('payment_proof_path')
            ->get()
            ->each(fn (SubscriptionRequest $request) => Storage::disk('local')->delete($request->payment_proof_path));

        // Comprobantes de carreras. También se incluyen las carreras de una
        // cooperativa administrada por esta cuenta: las cascadas de la base
        // eliminan las filas, pero no conocen el archivo privado del disco.
        Ride::query()
            ->whereNotNull('payment_proof_path')
            ->where(function ($query) use ($user) {
                $query->where('client_user_id', $user->id)
                    ->orWhere('driver_user_id', $user->id)
                    ->orWhereHas('rideRequest.cooperative', fn ($cooperative) => $cooperative->where('user_id', $user->id));
            })
            ->get()
            ->each(fn (Ride $ride) => Storage::disk('local')->delete($ride->payment_proof_path));
    }
}
