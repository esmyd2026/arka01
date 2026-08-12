<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\MemberCodeSequence;
use App\Services\UsernameGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    // Se agrega a la serialización para que el frontend tenga la URL lista
    // (login con Google guarda ahí la foto de perfil de la cuenta de Google).
    protected $appends = ['avatar_url'];

    // Mismo default que la columna en la migración — sin esto, un modelo
    // recién creado en memoria (sin releerlo de la BD con fresh()) muestra
    // 'role' vacío hasta el próximo query, aunque la fila ya haya quedado
    // bien en la base de datos.
    protected $attributes = [
        'role' => 'cliente',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'user_type',
        'google_id',
        'avatar_path',
        'city_id',
        'registration_lat',
        'registration_lng',
        'registration_neighborhood',
        'role',
        // Trazabilidad de referidos (pedido explícito del usuario): siempre
        // se pasa por RegisteredUserController::store() ya validado
        // (exists:users,id), nunca directo desde un formulario del propio
        // usuario, así que mass-assignment acá no es un riesgo real.
        'referred_by_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * Auditoría de seguridad (pedido explícito del usuario): antes solo
     * tapaba password/remember_token — los códigos de verificación (aunque
     * están hasheados), locked_at y google_id se quedaron afuera cuando se
     * agregaron sus migraciones, y HandleInertiaRequests manda el modelo
     * completo de auth.user al frontend. is_admin NO va acá a propósito: el
     * propio usuario lo necesita para su nav (AuthenticatedLayout.vue). Donde
     * un contexto de confianza sí necesite alguno de estos (ej. el admin
     * viendo si una cuenta ajena está bloqueada), se usa ->makeVisible() en
     * ese controlador puntual en vez de destaparlo acá para todo el mundo.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'session_takeover_code',
        'session_takeover_expires_at',
        'phone_verification_code',
        'phone_verification_expires_at',
        'locked_at',
        'google_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'phone_verification_expires_at' => 'datetime',
        'session_takeover_expires_at' => 'datetime',
        'locked_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
        'registration_lat' => 'decimal:7',
        'registration_lng' => 'decimal:7',
    ];

    protected static function booted(): void
    {
        // "Usuario" y código de socio (consideración agregada al alcance):
        // se generan solos apenas se crea la cuenta, sea por el formulario
        // de registro o por Google — nunca hay que pedírselos al usuario.
        static::creating(function (User $user) {
            if (empty($user->username)) {
                $user->username = UsernameGenerator::generate($user->name);
            }

            if (empty($user->member_code)) {
                $user->member_code = MemberCodeSequence::next();
            }
        });

        // Mantiene la columna `role` (consultable por SQL directo) al día
        // sola cuando cambia `is_admin` — el otro disparador (pasar a
        // conductor) vive en App\Models\DriverProfile::booted(), porque ese
        // es el momento real en que cambia, no acá. Si en algún momento se
        // agrega una forma de degradar a un admin, este mismo hook ya lo
        // cubre (recalcula 'conductor'/'cliente' según corresponda).
        static::saving(function (User $user) {
            if ($user->isDirty('is_admin')) {
                $user->role = $user->is_admin ? 'admin' : ($user->isDriver() ? 'conductor' : 'cliente');
            }
        });
    }

    /**
     * Genera y guarda un código de verificación de teléfono nuevo (6
     * dígitos, vence en 10 minutos) — se usa tanto al registrarse como al
     * pedir que se reenvíe. Se guarda hasheado, igual que la contraseña, por
     * si la base de datos se llega a filtrar.
     */
    public function issuePhoneVerificationCode(): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'phone_verification_code' => Hash::make($code),
            'phone_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }

    /**
     * true si el código coincide y todavía no venció — en ese caso, ya deja
     * el teléfono marcado como verificado y limpia el código usado.
     */
    public function verifyPhoneCode(string $code): bool
    {
        if (! $this->phone_verification_code || ! $this->phone_verification_expires_at?->isFuture()) {
            return false;
        }

        if (! Hash::check($code, $this->phone_verification_code)) {
            return false;
        }

        $this->forceFill([
            'phone_verified_at' => now(),
            'phone_verification_code' => null,
            'phone_verification_expires_at' => null,
        ])->save();

        return true;
    }

    /**
     * Sesión única por cuenta (pedido explícito del usuario, caso real: no
     * sabía en qué navegador había quedado logueado y no lo dejaba entrar
     * desde otro) — mismo patrón que issuePhoneVerificationCode(), pero en
     * columnas separadas: no tiene nada que ver con verificar el teléfono,
     * no debería pisar ni depender de ese estado.
     */
    public function issueSessionTakeoverCode(): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'session_takeover_code' => Hash::make($code),
            'session_takeover_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }

    /**
     * true si el código coincide y todavía no venció — limpia el código
     * usado, pero a propósito NO toca las sesiones acá: quien llama decide
     * qué hacer con esa confirmación (ver SessionTakeoverController).
     */
    public function verifySessionTakeoverCode(string $code): bool
    {
        if (! $this->session_takeover_code || ! $this->session_takeover_expires_at?->isFuture()) {
            return false;
        }

        if (! Hash::check($code, $this->session_takeover_code)) {
            return false;
        }

        $this->forceFill([
            'session_takeover_code' => null,
            'session_takeover_expires_at' => null,
        ])->save();

        return true;
    }

    /**
     * Bloqueo de cuenta (pedido explícito del usuario): la salida real para
     * "si no fue usted" en el aviso de intento de sesión concurrente — a
     * diferencia de "ignore este mensaje", esto corta el acceso a la cuenta
     * de inmediato, sin depender de que el dueño real haga nada más. Un
     * admin la reactiva desde /admin/usuarios.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Resuelve a qué cuenta se refiere un campo de login: correo (si trae
     * "@"), teléfono (si son solo dígitos, con o sin el "+" del código de
     * país — también sin el 0 inicial, como se lo dice de memoria la gente)
     * o, si no es ninguna de las dos cosas, el usuario autogenerado (ej.
     * "jperez", ver App\Services\UsernameGenerator). Única fuente de verdad
     * para esta resolución — la usan tanto el login normal
     * (App\Http\Requests\Auth\LoginRequest) como pedir el código para cerrar
     * la otra sesión (App\Http\Controllers\Auth\SessionTakeoverController),
     * que necesitan identificar la cuenta de la misma forma exacta.
     */
    public static function findByLoginIdentifier(string $input): ?self
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (str_contains($input, '@')) {
            return static::where('email', Str::lower($input))->first();
        }

        $normalized = preg_replace('/[\s-]/', '', $input);

        if (preg_match('/^\+?\d{7,15}$/', $normalized)) {
            $digits = ltrim($normalized, '+');
            $digitsWithoutTrunk = preg_replace('/^0/', '', $digits);

            return static::where('phone', $normalized)
                ->orWhere('phone', 'like', '%'.$digits)
                ->orWhere('phone', 'like', '%'.$digitsWithoutTrunk)
                ->first();
        }

        return static::where('username', Str::lower($input))->first();
    }

    /**
     * Ciudad donde vive (consideración de seguridad/UX agregada al alcance):
     * la solicitud de carrera arranca con esta ciudad por defecto.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Perfil de conductor de este usuario, si decidió activarlo (sección 3.1: un
     * usuario puede ser cliente y/o conductor a la vez).
     */
    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    /**
     * Flotas de las que este usuario es dueño (rol de cliente).
     */
    public function fleets(): HasMany
    {
        return $this->hasMany(Fleet::class, 'owner_user_id');
    }

    /**
     * Trazabilidad de referidos (pedido explícito del usuario): quién
     * compartió el enlace (invitación de flota de un conductor, o el perfil
     * público de cualquiera) que hizo que esta cuenta se registrara — null
     * si llegó por su cuenta, sin invitación de nadie.
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /**
     * Cuentas que se registraron a través de un enlace compartido por este
     * usuario — la tabla de "Mis referidos" en Profile/Edit.vue y el panel
     * admin (Admin\ReferralController) muestran esto.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    /**
     * Reseñas que otros dejaron sobre este usuario (como cliente o como
     * conductor, sección 3.6): forman su historial público de confianza.
     */
    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewee_user_id');
    }

    /**
     * Reseñas que este usuario dejó sobre otros.
     */
    public function reviewsGiven(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_user_id');
    }

    /**
     * Historial de suscripciones contratadas (planes pasados y el actual).
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Auditoría de cambios de plan de este usuario (sección 9.6).
     */
    public function subscriptionChanges(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class);
    }

    /**
     * La suscripción vigente de un tipo dado ('driver' o 'client'), si tiene
     * una. Si no hay ninguna, se asume el plan Gratis correspondiente — ver
     * app/Services/PlanLimits.php, que es quien realmente resuelve los límites.
     */
    public function activeSubscription(string $ownerType): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', ['active', 'grace'])
            ->whereHas('plan', fn ($query) => $query->where('owner_type', $ownerType))
            ->latest('started_at')
            ->first();
    }

    /**
     * A quién avisar si algo sale mal durante un viaje (sección 8: botón SOS
     * y seguimiento en vivo compartible).
     */
    public function trustedContacts(): HasMany
    {
        return $this->hasMany(TrustedContact::class);
    }

    /**
     * Historial de aperturas de la ventana de 24h de WhatsApp (pedido
     * explícito del usuario) — cada vez que este usuario le escribe al
     * número oficial, se abre una fila nueva.
     */
    public function whatsappSessions(): HasMany
    {
        return $this->hasMany(WhatsAppSession::class);
    }

    /**
     * La ventana vigente (o la última que tuvo, ya vencida) — null si nunca
     * le escribió al número oficial.
     */
    public function currentWhatsAppSession(): ?WhatsAppSession
    {
        return $this->whatsappSessions()->latest('expires_at')->first();
    }

    public function hasActiveWhatsAppSession(): bool
    {
        return $this->currentWhatsAppSession()?->isActive() ?? false;
    }

    /**
     * Expresos que este usuario publicó como cliente (sección 4).
     */
    public function expressRoutes(): HasMany
    {
        return $this->hasMany(ExpressRoute::class, 'client_user_id');
    }

    /**
     * "Mis rutas" (pedido explícito del usuario): origen+destino guardados a
     * propósito, con alias opcional, para pedir la próxima carrera sin
     * volver a escribir ni marcar nada — ver App\Models\SavedRoute.
     */
    public function savedRoutes(): HasMany
    {
        return $this->hasMany(SavedRoute::class, 'client_user_id');
    }

    /**
     * Postulaciones que este usuario hizo como conductor a rutas Expreso.
     */
    public function expressApplications(): HasMany
    {
        return $this->hasMany(ExpressApplication::class, 'driver_user_id');
    }

    /**
     * true si el usuario ya tiene perfil de conductor creado Y activo.
     *
     * Pedido explícito del usuario ("pasarme a conductor / pasarme a
     * cliente, fácil"): un conductor puede pausar su perfil sin borrarlo
     * (App\Models\DriverProfile::isDeactivated(), ver
     * DriverProfileController::deactivate()) — mientras esté pausado, acá da
     * false y la cuenta vuelve a operar como cliente, aunque el
     * `driver_profiles` siga existiendo con todos sus datos.
     */
    public function isDriver(): bool
    {
        return $this->driverProfile()->whereNull('deactivated_at')->exists();
    }

    /**
     * true si el usuario es del lado cliente — el rol por defecto de
     * cualquier cuenta que no sea admin ni tenga un perfil de conductor
     * activo en este momento. Cada cuenta opera como cliente O conductor
     * (nunca las dos al mismo tiempo), pero sí puede cambiar de uno a otro
     * — ver los chequeos en FleetController y DriverProfileController.
     */
    public function isClient(): bool
    {
        return ! $this->is_admin && ! $this->isDriver();
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * URL lista para mostrar en el `<img>` del avatar. Google manda una URL
     * externa completa (queda tal cual); si en algún momento se sube un
     * avatar propio como archivo, `avatar_path` sería una ruta relativa del
     * disco 'public' y acá se resuelve con Storage::url().
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return str_starts_with($this->avatar_path, 'http')
            ? $this->avatar_path
            : Storage::disk('public')->url($this->avatar_path);
    }
}
