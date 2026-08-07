@component('mail::message')
# Cierre su otra sesión

Hola {{ $user->name }},

Solicitaron un código para cerrar su sesión activa en otro dispositivo y poder entrar desde acá.

@component('mail::panel')
Su código es: **{{ $code }}**

Vence en 10 minutos.
@endcomponent

@component('mail::button', ['url' => $lockUrl, 'color' => 'error'])
No fui yo, bloquear mi cuenta
@endcomponent

Si no fue usted quien lo solicitó, use el botón de arriba para bloquear la cuenta de inmediato — un administrador la revisará para reactivarla.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
