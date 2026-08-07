@component('mail::message')
# Alguien intentó entrar a su cuenta

Hola {{ $user->name }},

Justo ahora alguien intentó iniciar sesión en su cuenta de Arka01 desde otro dispositivo, mientras su sesión actual seguía activa. Como medida de seguridad, no lo dejamos entrar.

@component('mail::panel')
@if($ipAddress)
Dirección desde la que intentaron entrar: **{{ $ipAddress }}**
@endif
Fecha: {{ now()->format('d/m/Y H:i') }}
@endcomponent

Si fue usted (por ejemplo, desde otro celular o computadora), cierre sesión en el dispositivo donde está ahora para poder entrar desde el otro — o pida un código para cerrarla de forma remota desde la pantalla de inicio de sesión.

@component('mail::button', ['url' => $lockUrl, 'color' => 'error'])
No fui yo, bloquear mi cuenta
@endcomponent

Si no fue usted, use el botón de arriba para bloquear la cuenta de inmediato — un administrador la revisará para reactivarla.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
