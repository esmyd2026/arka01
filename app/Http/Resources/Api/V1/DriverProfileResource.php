<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Perfil editable del conductor para la app móvil (roadmap app móvil, "full
 * backend"). Las URLs de documentos privados apuntan a las rutas móviles
 * dedicadas (`driver-profile.mobile.license-photo`/`.document`, bajo
 * Sanctum) — reusan tal cual `DriverProfileController::licensePhoto()`/
 * `document()` (web), que ya solo dependían de `$request->user()`.
 */
class DriverProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'driver_type' => $this->driver_type,
            'vehicle_make' => $this->vehicle_make,
            'vehicle_model' => $this->vehicle_model,
            'vehicle_color' => $this->vehicle_color,
            'vehicle_type' => $this->vehicle_type,
            'vehicle_plate' => $this->vehicle_plate,
            'vehicle_year' => $this->vehicle_year,
            'passenger_capacity' => $this->passenger_capacity,
            'has_trunk' => (bool) $this->has_trunk,
            'vehicle_amenities' => $this->vehicle_amenities ?? [],
            'vehicle_photo_url' => $this->vehicle_photo_url,
            'service_category' => $this->service_category,
            'rate_per_km' => $this->rate_per_km !== null ? (float) $this->rate_per_km : null,
            'minimum_fare' => $this->minimum_fare !== null ? (float) $this->minimum_fare : null,
            'max_request_distance_km' => $this->max_request_distance_km,
            // Zona de cobertura por sector (pedido explícito del usuario) —
            // ver App\Models\DriverProfile::coverageSectors().
            'coverage_sector_ids' => $this->coverageSectors()->pluck('sectors.id'),
            'accepts_cash' => (bool) $this->accepts_cash,
            'accepts_transfer' => (bool) $this->accepts_transfer,
            'has_insurance' => (bool) $this->has_insurance,
            'is_public' => (bool) $this->is_public,
            'profile_public' => (bool) $this->profile_public,
            'verification_status' => $this->verification_status,
            'verification_rejection_reason' => $this->verification_rejection_reason,
            'has_identity_document' => filled($this->identity_document_path),
            'has_license_photo' => filled($this->license_photo_path),
            // Se conservan por compatibilidad con documentos ya subidos —
            // ya no se pide ni se exige uno nuevo (reemplazado por
            // vehicle_registration, ver DriverVerificationRequirementRegistry).
            'has_police_record' => filled($this->police_record_path),
            'has_vehicle_registration' => filled($this->vehicle_registration_path),
            'identity_document_url' => $this->identity_document_path
                ? route('api.v1.driver-profile.mobile.document', ['user' => $this->user_id, 'type' => 'identity'])
                : null,
            'license_photo_url' => $this->license_photo_path
                ? route('api.v1.driver-profile.mobile.license-photo', $this->user_id)
                : null,
            'police_record_url' => $this->police_record_path
                ? route('api.v1.driver-profile.mobile.document', ['user' => $this->user_id, 'type' => 'police-record'])
                : null,
            'vehicle_registration_url' => $this->vehicle_registration_path
                ? route('api.v1.driver-profile.mobile.document', ['user' => $this->user_id, 'type' => 'vehicle-registration'])
                : null,
            'total_points' => $this->total_points,
            'registration_complete' => $this->registration_complete,
            'deactivated_at' => $this->deactivated_at,
        ];
    }
}
