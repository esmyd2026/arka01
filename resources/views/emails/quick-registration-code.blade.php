@component('mail::message')
# Confirme su número

Hola,

Alguien (probablemente usted) está creando una cuenta en Arka01 con este correo como respaldo, porque el código no llegó por WhatsApp.

@component('mail::panel')
Su código es: **{{ $code }}**

Vence en 10 minutos.
@endcomponent

Si usted no inició este registro, puede ignorar este correo — no se creó ninguna cuenta todavía.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
