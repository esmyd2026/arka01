<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificación de teléfono por WhatsApp (consideración de seguridad agregada
 * al alcance) — mismo patrón que la verificación de email de Breeze, pero
 * con un código de un solo uso en vez de un enlace firmado.
 */
class PhoneVerificationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->phone || $user->phone_verified_at) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        return Inertia::render('Auth/VerifyPhone', [
            'phone' => $user->phone,
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();

        if (! $user->verifyPhoneCode($validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Ese código no es válido o ya venció. Pida uno nuevo.',
            ]);
        }

        Log::info('Teléfono verificado por WhatsApp.', ['user_id' => $user->id]);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Bug crítico reportado por el usuario: cuentas creadas que se quedaban
     * trabadas acá para siempre porque el código nunca llegaba (envío
     * fallido — token vencido, límite de Meta, etc.) y "Reenviar" repetía el
     * mismo fallo en silencio, siempre mostrando "le mandamos un código
     * nuevo" aunque no fuera cierto. Ahora, si el envío de verdad falla, el
     * teléfono queda auto-verificado en vez de trabar la cuenta — mismo
     * criterio que cuando la integración ni está configurada
     * (RegisteredUserController). Esto además es la salida para quien YA
     * está trabado hoy: le alcanza con tocar "Reenviar código" una vez más
     * después de este cambio.
     */
    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->phone || $user->phone_verified_at) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        $code = $user->issuePhoneVerificationCode();
        $sent = WhatsAppVerificationSender::sendCode($user->phone, $code);

        Log::info('Código de verificación de teléfono reenviado.', ['user_id' => $user->id, 'enviado_por_whatsapp' => $sent]);

        if (! $sent) {
            $user->forceFill([
                'phone_verified_at' => now(),
                'phone_verification_code' => null,
                'phone_verification_expires_at' => null,
            ])->save();

            return redirect()->intended(RouteServiceProvider::HOME)
                ->with('status', 'No pudimos mandarle el código por WhatsApp, así que lo dejamos verificado igual — ya puede seguir usando la app.');
        }

        return back()->with('status', 'Le mandamos un código nuevo por WhatsApp.');
    }
}
