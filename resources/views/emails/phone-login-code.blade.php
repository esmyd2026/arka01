@component('mail::message')
# Inicie sesión

Hola {{ $user->name }},

Solicitaron un código para iniciar sesión en su cuenta de Arka01 sin contraseña.

@component('mail::panel')
Su código es: **{{ $code }}**

Vence en 10 minutos.
@endcomponent

Si usted no lo solicitó, puede ignorar este correo — nadie puede entrar a su cuenta sin este código.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
