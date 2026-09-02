<?php

namespace App\Models;

use App\Services\DriverVerificationRequirementRegistry;
use App\Services\Haversine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DriverProfile extends Model
{
    use HasFactory;

    // Se agregan a la serialización para que el frontend tenga la URL lista
    // para pintar la foto (sección 8: "verificación visible"), sin tener que
    // reconstruir la ruta del disco 'public' del lado del cliente.
    protected $appends = [
        'license_photo_url',
        'vehicle_photo_url',
        'identity_document_url',
        'police_record_url',
        'vehicle_registration_url',
        'registration_complete',
    ];

    protected $fillable = [
        'user_id',
        'driver_type',
        'license_photo_path',
        'identity_document_path',
        'police_record_path',
        // Pedido explícito del usuario: reemplaza a police_record_path en el
        // formulario del conductor (ver DriverVerificationRequirementRegistry)
        // — police_record_path se conserva para no perder documentos viejos,
        // pero ya no se pide ni se exige.
        'vehicle_registration_path',
        'has_insurance',
        'vehicle_make',
        'vehicle_model',
        'vehicle_color',
        'vehicle_type',
        'vehicle_plate',
        'vehicle_year',
        'passenger_capacity',
        'has_trunk',
        'vehicle_amenities',
        'service_category',
        'public_category',
        'vehicle_photo_path',
        'rate_per_km',
        'minimum_fare',
        'accepts_cash',
        'accepts_transfer',
        'is_available',
        'is_public',
        'profile_public',
        'current_lat',
        'current_lng',
        'location_updated_at',
        'verification_status',
        'verification_rejection_reason',
        'verified_at',
        'verified_by',
        'max_request_distance_km',
        // Cargo por distancia de recogida (pedido explícito del usuario): el
        // conductor lo apaga o prende desde su propio perfil, igual que su
        // tarifa por km — con esto en false, la función no existe para él en
        // ninguna solicitud. Ver App\Services\PriceCalculator::pickupSurchargeForDriver().
        'pickup_surcharge_enabled',
        'whatsapp_ride_actions_enabled',
    ];

    protected $casts = [
        'rate_per_km' => 'decimal:2',
        'minimum_fare' => 'decimal:2',
        'accepts_cash' => 'boolean',
        'accepts_transfer' => 'boolean',
        'is_available' => 'boolean',
        'is_public' => 'boolean',
        'profile_public' => 'boolean',
        'verified_at' => 'datetime',
        'current_lat' => 'decimal:7',
        'current_lng' => 'decimal:7',
        'location_updated_at' => 'datetime',
        'max_request_distance_km' => 'integer',
        'pickup_surcharge_enabled' => 'boolean',
        'suspended_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'passenger_capacity' => 'integer',
        'has_trunk' => 'boolean',
        'has_insurance' => 'boolean',
        'vehicle_amenities' => 'array',
        'whatsapp_ride_actions_enabled' => 'boolean',
        'admin_activated_at' => 'datetime',
        'admin_requires_documents_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Todo perfil de conductor necesita su código de invitación desde el
        // momento en que se crea, para que ya se pueda compartir por link/QR
        // (sección 3.2 y 9.5) sin un paso extra.
        static::creating(function (DriverProfile $profile) {
            if (empty($profile->invite_code)) {
                $profile->invite_code = static::generateUniqueInviteCode();
            }
        });

        // Mantiene `users.role` (columna de consulta rápida, ver la migración
        // que la crea) al día apenas alguien activa su perfil de conductor —
        // el momento real en que deja de ser "cliente por defecto". No pasa
        // por User::saving() porque acá el cambio arranca del lado de
        // DriverProfile, no de User.
        static::created(function (DriverProfile $profile) {
            // Bug reportado por el usuario ("se estan registrando como
            // conductor y el sistema termina creandole como cliente"):
            // acá es donde de verdad se termina de convertir en conductor
            // — se apaga la señal de "le falta terminar" que se prendió
            // en el registro (ver RegisteredUserController::store() y
            // EnsureDriverOnboardingIsComplete).
            User::whereKey($profile->user_id)->update(['role' => 'conductor', 'intends_to_drive' => false]);
        });

        // Simétrico al de arriba: si el perfil de conductor se borra (hoy no
        // hay una pantalla que lo haga, pero sí puede pasar a mano —
        // justamente así quedó desactualizada la columna de Demo Cliente
        // antes de este fix), `role` tiene que volver a reflejar la realidad.
        static::deleted(function (DriverProfile $profile) {
            $user = User::find($profile->user_id);
            if ($user) {
                $user->role = $user->is_admin ? 'admin' : ($user->isCooperative() ? 'cooperativa' : 'cliente');
                $user->save();
            }
        });
    }

    protected static function generateUniqueInviteCode(): string
    {
        do {
            // Código corto en mayúsculas, fácil de leer o de escribir a mano
            // si alguien lo dicta por teléfono.
            $code = strtoupper(Str::random(8));
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLicensePhotoUrlAttribute(): ?string
    {
        // Auditoría de seguridad: la licencia es un documento de identidad,
        // vive en el disco privado — esta URL pasa por un controlador que
        // chequea que quien la pide sea el propio conductor o un admin (ver
        // DriverProfileController::licensePhoto()), no un link directo al disco.
        return $this->license_photo_path ? route('driver-profile.license-photo', $this->user_id) : null;
    }

    public function getVehiclePhotoUrlAttribute(): ?string
    {
        return $this->vehicle_photo_path ? Storage::disk('public')->url($this->vehicle_photo_path) : null;
    }

    public function getIdentityDocumentUrlAttribute(): ?string
    {
        return $this->identity_document_path
            ? route('driver-profile.document', ['user' => $this->user_id, 'type' => 'identity'])
            : null;
    }

    public function getPoliceRecordUrlAttribute(): ?string
    {
        return $this->police_record_path
            ? route('driver-profile.document', ['user' => $this->user_id, 'type' => 'police-record'])
            : null;
    }

    public function getVehicleRegistrationUrlAttribute(): ?string
    {
        return $this->vehicle_registration_path
            ? route('driver-profile.document', ['user' => $this->user_id, 'type' => 'vehicle-registration'])
            : null;
    }

    public function getTrustLabelAttribute(): string
    {
        return $this->driver_type === 'public_transport'
            ? 'Transporte Público Verificado'
            : 'Conductor Independiente Verificado';
    }

    /** El vínculo activo aceptado; para el MVP un conductor opera con una cooperativa. */
    public function cooperativeMembership(): ?CooperativeDriverMembership
    {
        return CooperativeDriverMembership::query()
            ->where('driver_user_id', $this->user_id)
            ->where('status', 'accepted')
            ->whereNull('ended_at')
            ->with('cooperative')
            ->first();
    }

    /**
     * Catálogo fijo de tipos de carrocería (pedido explícito del usuario):
     * a diferencia del color (VEHICLE_COLORS en Driver/Profile.vue, texto
     * libre con opciones sugeridas), acá el valor guardado tiene que ser
     * una de estas claves — es lo que sí se muestra tal cual al cliente en
     * las pantallas públicas, así que no puede quedar en cualquier formato.
     *
     * @return array<string, string>
     */
    public static function vehicleTypes(): array
    {
        return [
            'sedan' => 'Sedán',
            'suv' => 'SUV',
            'hatchback' => 'Hatchback',
            'pickup' => 'Camioneta',
            'van' => 'Van / Furgoneta',
            'otro' => 'Otro',
        ];
    }

    /**
     * Comodidades opcionales que el conductor declara y administración
     * revisa antes de asignar una categoría. Se guardan como claves en JSON
     * para poder ampliar el catálogo sin agregar una columna por cada check.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function vehicleAmenities(): array
    {
        return [
            'air_conditioning' => ['label' => 'Aire acondicionado', 'description' => 'Climatización funcional para todos los pasajeros.'],
            'four_doors' => ['label' => 'Cuatro puertas', 'description' => 'Acceso independiente y cómodo a la fila trasera.'],
            'spacious_interior' => ['label' => 'Interior amplio', 'description' => 'Buen espacio para piernas y viaje cómodo.'],
            'leather_seats' => ['label' => 'Asientos de cuero', 'description' => 'Tapicería de cuero o material equivalente.'],
            'phone_charger' => ['label' => 'Cargador para celular', 'description' => 'Puerto USB o cargador disponible durante el viaje.'],
            'wifi' => ['label' => 'Wi-Fi a bordo', 'description' => 'Conexión a internet disponible para pasajeros.'],
            'smoke_free' => ['label' => 'Vehículo libre de humo', 'description' => 'No se fuma dentro y no conserva olor a cigarrillo.'],
            'sanitized' => ['label' => 'Limpieza entre viajes', 'description' => 'Limpieza frecuente de superficies e interior.'],
            'accepts_pets' => ['label' => 'Acepta mascotas', 'description' => 'Permite viajar con mascotas bajo coordinación.'],
            'child_seat' => ['label' => 'Asiento infantil', 'description' => 'Cuenta con sistema de retención infantil.'],
            'wheelchair_accessible' => ['label' => 'Accesibilidad', 'description' => 'Facilidades para pasajeros con movilidad reducida.'],
        ];
    }

    /**
     * Categoría comercial asignada por administración. No reemplaza la
     * medalla por puntos: una describe el servicio/vehículo y la otra la
     * trayectoria del conductor dentro de Arka01.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function serviceCategories(): array
    {
        return [
            'standard' => ['label' => 'Estándar', 'description' => 'Servicio cotidiano, seguro y funcional.'],
            'comfort' => ['label' => 'Confort', 'description' => 'Vehículo moderno, climatizado y con interior cómodo.'],
            'premium' => ['label' => 'Premium', 'description' => 'Experiencia superior, acabados y presentación premium.'],
            'xl' => ['label' => 'XL', 'description' => 'Mayor capacidad para pasajeros o equipaje.'],
            'accessible' => ['label' => 'Accesible', 'description' => 'Adaptado o equipado para movilidad reducida.'],
        ];
    }

    public function serviceCategoryLabel(): ?string
    {
        return self::serviceCategories()[$this->service_category]['label'] ?? null;
    }

    /**
     * Clasificación pública administrada. No revela `driver_type`, que se
     * conserva únicamente para reglas operativas internas.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function publicCategories(): array
    {
        return [
            'professional' => [
                'label' => 'Conductor Profesional',
                'description' => 'Conductor profesional cuya documentación y actividad fueron revisadas.',
            ],
            'verified' => [
                'label' => 'Conductor Verificado',
                'description' => 'Conductor que completó satisfactoriamente la verificación de Arka01.',
            ],
        ];
    }

    public function publicCategoryLabel(): ?string
    {
        return self::publicCategories()[$this->public_category]['label'] ?? null;
    }

    public function visiblePublicCategoryLabel(): ?string
    {
        return $this->verification_status === 'approved' ? $this->publicCategoryLabel() : null;
    }

    public function vehicleTypeLabel(): ?string
    {
        return self::vehicleTypes()[$this->vehicle_type] ?? null;
    }

    /**
     * Confidencialidad (pedido explícito del usuario): en las pantallas de
     * cara al cliente (directorio, lista de conductores al pedir carrera,
     * perfil público, seguimiento en vivo) la placa no va completa — solo la
     * primera letra y los últimos dos caracteres, el resto tapado. La placa
     * real y sin tapar sigue viajando tal cual a quien ya la necesita
     * completa: el propio conductor (Driver/Profile.vue), el admin
     * (Admin\DriverVerifications, Admin\UserProfile) y el registro de una
     * alerta SOS (App\Models\SosAlert — ahí la placa completa es información
     * de seguridad real, no un dato de navegación).
     */
    public function maskedPlate(): ?string
    {
        if (blank($this->vehicle_plate)) {
            return null;
        }

        return mb_substr($this->vehicle_plate, 0, 1).'xxx'.mb_substr($this->vehicle_plate, -2);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Admin que forzó la activación de este conductor puntual (pedido
     * explícito del usuario) — ver hasCompleteRegistrationInformation() y
     * Admin\UserProfileController::forceActivate().
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_activated_by');
    }

    /**
     * Admin que le exigió documentos puntuales a este conductor, al
     * contrario de activatedBy() de arriba (pedido explícito del usuario) —
     * ver missingRegistrationInformation() y
     * Admin\UserProfileController::requireDocuments().
     */
    public function requiresDocumentsSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_requires_documents_by');
    }

    /**
     * Cuántas flotas activas integra este conductor ahora mismo (sus "clientes
     * de confianza" vigentes). Se usa contra el límite del plan Gratis del
     * conductor (sección 7.2 / config/arka.php) antes de aceptar una invitación nueva.
     */
    public function activeClientCount(): int
    {
        return FleetMember::query()
            ->where('driver_user_id', $this->user_id)
            ->whereNull('left_at')
            ->count();
    }

    /**
     * Zona de cobertura (pedido explícito del usuario): true si un punto
     * (origen de una carrera) cae dentro de la distancia máxima que este
     * conductor configuró desde su ubicación actual — para no recibir
     * solicitudes que no le convienen por los km, aunque sea de su propia
     * flota ("si está fuera de rango, así sea de mi flota, tiene que estar
     * fuera de rango"). Sin límite configurado, o sin ubicación conocida
     * todavía, no hay nada que descartar: se deja pasar.
     */
    /**
     * Panel admin (pedido explícito del usuario: "bloquear o deshabilitar o
     * desconectar" — un solo mecanismo para las tres). A propósito NO está en
     * $fillable: solo se toca desde Admin\DriverController vía forceFill(),
     * nunca desde un formulario del propio conductor.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * "Pausado" por el propio conductor (pedido explícito del usuario:
     * poder pasar de conductor a cliente y volver, fácil) — distinto de
     * `isSuspended()`, que es moderación de un admin. A propósito NO está en
     * $fillable: solo se toca desde
     * DriverProfileController::deactivate()/reactivate()/update(), nunca
     * desde el formulario genérico del conductor. Mientras esté pausado,
     * `User::isDriver()` da false — todos los datos siguen guardados tal
     * cual, listos para reactivar sin volver a completar nada.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Minutos sin ping de ubicación antes de considerar que un conductor
     * "disponible" ya no está de verdad — editable desde /admin/tarifas
     * (pedido explícito del usuario, antes era una constante fija en el
     * código). Mismo valor que usa App\Console\Commands\SweepStaleDriverAvailability
     * para desconectarlo del todo; acá vive como única fuente de verdad para
     * que ese comando Y el filtrado de candidatos al pedir una carrera
     * (App\Services\RideDispatchCandidates, RideRequestController) usen
     * siempre el mismo número.
     */
    public static function staleAfterMinutes(): int
    {
        return (int) PricingSetting::current()->driver_stale_after_minutes;
    }

    /**
     * Bug reportado por el usuario: si el conductor pasa la app a segundo
     * plano, cierra el navegador o pierde señal, `is_available` se queda en
     * `true` hasta que corre el barrido periódico — mientras tanto, seguía
     * pudiendo recibir/aceptar solicitudes como si estuviera activo. Este
     * método deja que cualquier punto que arme la lista de candidatos (o que
     * muestre "disponible" en pantalla) descarte a un conductor sin ping
     * reciente de inmediato, sin esperar a ese barrido.
     */
    public function isStale(): bool
    {
        return $this->location_updated_at === null
            || $this->location_updated_at->lt(now()->subMinutes(self::staleAfterMinutes()));
    }

    /**
     * Bug reportado por el usuario: un conductor sin ping de ubicación
     * reciente (isStale()) no necesariamente dejó de trabajar de verdad — si
     * sigue con la ventana de WhatsApp abierta (le escribió al número oficial
     * y no pasaron 24h), es señal de que sigue con el celular a mano y se lo
     * puede seguir contactando por ahí aunque la app esté en segundo plano o
     * el navegador cerrado. En ese caso NO se lo trata como desconectado:
     * sigue apareciendo disponible para los clientes, sigue en la bolsa de
     * candidatos, y sigue recibiendo los avisos de carrera nueva por
     * WhatsApp. El llamador pasa el flag ya resuelto (en vez de que este
     * método consulte `$this->user` solo) para que cada lugar decida cómo
     * cargarlo sin pisar el eager loading que ya tenga armado.
     */
    public function isReachable(bool $hasActiveWhatsAppSession): bool
    {
        return ! $this->isStale() || $hasActiveWhatsAppSession;
    }

    /**
     * Pedido explícito del usuario: si el conductor no completó los datos
     * de su vehículo (hacen falta para filtrar por cantidad de pasajeros y
     * cajuela al pedir una carrera), no puede ponerse disponible — ver
     * DriverLocationController::update().
     */
    public function hasCompleteVehicleInfo(): bool
    {
        return $this->missingVehicleInformation() === [];
    }

    /**
     * Campos concretos que aún faltan en la ficha del vehículo. Esta lista
     * alimenta el perfil web y conserva la misma fuente de verdad que bloquea
     * la disponibilidad; así nunca vuelve a decir "completo" mientras abajo
     * pide datos genéricos.
     *
     * @return array<int, array{key: string, label: string, section: string}>
     */
    public function missingVehicleInformation(): array
    {
        $fields = [
            'vehicle_make' => 'marca',
            'vehicle_model' => 'modelo',
            'vehicle_color' => 'color',
            'vehicle_type' => 'tipo de vehículo',
            'vehicle_plate' => 'placa',
            'vehicle_year' => 'año',
            'passenger_capacity' => 'cantidad de pasajeros',
        ];

        $missing = collect($fields)
            ->filter(fn (string $label, string $field) => ! filled($this->{$field}))
            ->map(fn (string $label, string $field) => [
                'key' => $field,
                'label' => $label,
                'section' => 'vehicle',
            ])
            ->values();

        // `false` es una respuesta válida: significa que el vehículo no
        // tiene cajuela. Solo null representa que todavía no respondió.
        if ($this->has_trunk === null) {
            $missing->push(['key' => 'has_trunk', 'label' => 'disponibilidad de cajuela', 'section' => 'vehicle']);
        }

        return $missing->all();
    }

    /**
     * Requisitos reales pendientes para solicitar aprobación y conectarse.
     * Los requisitos deshabilitados por administración no aparecen.
     *
     * @return array<int, array{key: string, label: string, section: string}>
     */
    public function missingRegistrationInformation(): array
    {
        if ($this->admin_activated_at !== null) {
            return [];
        }

        $missing = collect();

        if (! filled($this->driver_type)) {
            $missing->push(['key' => 'driver_type', 'label' => 'tipo de conductor', 'section' => 'verification']);
        }

        $missing->push(...$this->missingVehicleInformation());

        if (! is_numeric($this->rate_per_km) || (float) $this->rate_per_km < 0) {
            $missing->push(['key' => 'rate_per_km', 'label' => 'tarifa por kilómetro', 'section' => 'work']);
        }

        if (! $this->accepts_cash && ! $this->accepts_transfer) {
            $missing->push(['key' => 'payment_method', 'label' => 'al menos una forma de pago', 'section' => 'work']);
        }

        // Pedido explícito del usuario: con los documentos ya opcionales
        // para todos (interruptor global de arriba), un admin puede
        // exigírselos igual a UN conductor puntual — ver
        // Admin\UserProfileController::requireDocuments().
        $requiredForThisDriver = $this->admin_requires_documents_at !== null;

        foreach ([
            'identity_document' => ['identity_document_path', 'foto de cédula'],
            'license_photo' => ['license_photo_path', 'foto de licencia'],
            'vehicle_registration' => ['vehicle_registration_path', 'matrícula del vehículo'],
        ] as $requirement => [$field, $label]) {
            if ((DriverVerificationRequirementRegistry::isRequired($requirement) || $requiredForThisDriver) && ! filled($this->{$field})) {
                $missing->push(['key' => $requirement, 'label' => $label, 'section' => 'verification']);
            }
        }

        if (DriverVerificationRequirementRegistry::isRequired('has_insurance') && ! $this->has_insurance) {
            $missing->push(['key' => 'has_insurance', 'label' => 'declaración de seguro vigente', 'section' => 'verification']);
        }

        return $missing->values()->all();
    }

    /**
     * Información mínima que administración debe revisar antes de permitir
     * que el conductor se conecte y reciba solicitudes.
     */
    public function hasCompleteRegistrationInformation(): bool
    {
        // Activación manual de un admin para un conductor puntual (pedido
        // explícito del usuario: "permiteme colocar a un conductor activo
        // asi no mande toda la informacion... para que pueda operar") —
        // salta TODO el resto de este chequeo. El admin asume la
        // responsabilidad del caso (cuenta de demo, ya vetado por una
        // cooperativa, etc.) — ver Admin\UserProfileController::forceActivate(),
        // que exige dejar por qué como nota obligatoria.
        if ($this->admin_activated_at !== null) {
            return true;
        }

        // Pedido explícito del usuario: "eso de numero de licencia para
        // conductores eso no existe en el ecuador" — ya no se exige
        // `license_number` acá (la cédula, que sí existe, queda cubierta
        // por `identity_document_path` más abajo).
        //
        // Cada `filled($this->x) || ! isRequired('x')` de acá abajo es
        // "hace falta el dato, A MENOS que un admin lo haya apagado desde
        // /admin/sistema" (pedido explícito del usuario: "permiteme desde
        // el admin poder activar o no lo obligatorio para que el conductor
        // se le haga mas facil activarse" — ver DriverVerificationRequirementRegistry).
        return $this->missingRegistrationInformation() === [];
    }

    /**
     * Una sola regla para API, dashboard y despacho. Así el botón no puede
     * habilitar algo que luego el servidor o la asignación deberían negar.
     */
    public function availabilityBlockReason(): ?string
    {
        if ($this->isDeactivated()) {
            return 'Su perfil de conductor está pausado. Reactívelo para conectarse.';
        }

        if ($this->isSuspended()) {
            return 'Su cuenta de conductor está suspendida. Contacte a soporte.';
        }

        if (! $this->hasCompleteRegistrationInformation()) {
            return 'Complete toda su información, vehículo y documentos en el perfil antes de conectarse.';
        }

        return match ($this->verification_status) {
            'approved' => null,
            'rejected' => 'Administración rechazó la verificación. Revise el motivo y corrija su información.',
            default => 'Su información está pendiente de aprobación administrativa. Le avisaremos cuando pueda conectarse.',
        };
    }

    public function canBecomeAvailable(): bool
    {
        return $this->availabilityBlockReason() === null;
    }

    public function getRegistrationCompleteAttribute(): bool
    {
        return $this->hasCompleteRegistrationInformation();
    }

    /**
     * Pedido explícito del usuario: mientras la documentación está "en
     * revisión", el conductor no puede subir fotos nuevas — solo puede
     * volver a hacerlo si un admin la rechaza (o todavía no subió ninguna).
     */
    public function canUploadDocuments(): bool
    {
        return $this->verification_status !== 'pending';
    }

    public function isWithinRangeOf(float $originLat, float $originLng): bool
    {
        if ($this->max_request_distance_km === null) {
            return true;
        }

        if ($this->current_lat === null || $this->current_lng === null) {
            return true;
        }

        $distanceKm = Haversine::distanceKm($originLat, $originLng, (float) $this->current_lat, (float) $this->current_lng);

        return $distanceKm <= $this->max_request_distance_km;
    }
}
