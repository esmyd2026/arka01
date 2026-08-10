@extends('emails.layout')

@section('title', '¡Bienvenido a Arka01!')

@section('preheader', 'Su cuenta ya está lista — acá tiene su usuario y su código de socio.')

@section('content')
    <tr>
        <td style="padding-bottom: 16px;">
            <p style="margin:0; font-size:22px; line-height:30px; font-weight:700; color:#e7f4ee;">
                ¡Bienvenido/a, {{ $user->name }}!
            </p>
        </td>
    </tr>

    <tr>
        <td style="padding-bottom: 24px;">
            <p style="margin:0; font-size:15px; line-height:24px; color:#c7d6cf;">
                Su cuenta en {{ config('app.name') }} ya está lista. Desde acá puede pedir una carrera con conductores
                de confianza, armar su propia flota, o publicar rutas fijas y viajes programados si es conductor.
            </p>
        </td>
    </tr>

    {{-- Panel con los dos datos que se generan solos al registrarse (sección
         "Login múltiple"): el usuario autogenerado y el código de socio — no
         se le piden a nadie, pero conviene que los tenga a mano desde el arranque. --}}
    <tr>
        <td style="padding-bottom: 28px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0a0f0c" style="background-color:#0a0f0c; border-radius:12px;">
                <tr>
                    <td style="padding: 20px 24px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding-bottom: 12px;">
                                    <p style="margin:0; font-size:11px; line-height:16px; letter-spacing:0.04em; text-transform:uppercase; color:#93ada2;">Su usuario</p>
                                    <p style="margin:2px 0 0 0; font-size:16px; line-height:22px; font-weight:600; color:#e7f4ee;">{{ '@' . $user->username }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <p style="margin:0; font-size:11px; line-height:16px; letter-spacing:0.04em; text-transform:uppercase; color:#93ada2;">Código de socio</p>
                                    <p style="margin:2px 0 0 0; font-size:16px; line-height:22px; font-weight:600; color:#a3e635;">#{{ $user->member_code }}</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td style="padding-bottom: 28px;">
            <p style="margin:0; font-size:15px; line-height:24px; color:#c7d6cf;">
                Puede iniciar sesión con este usuario, su teléfono o su correo — lo que le resulte más fácil de recordar.
            </p>
        </td>
    </tr>

    {{-- Botón "a prueba de balas" (tabla, no <button>/<a> suelto): así se ve
         bien tanto en Outlook como en clientes modernos. --}}
    <tr>
        <td align="center" style="padding-bottom: 8px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td bgcolor="#34d399" style="background-color:#34d399; border-radius:10px;">
                        <a href="{{ route('login') }}" target="_blank" style="display:inline-block; padding: 13px 32px; font-size:15px; font-weight:700; color:#0a0f0c; text-decoration:none;">
                            Ir a mi cuenta
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
@endsection
