@component('mail::message')
# Alerta de seguridad

**{{ $triggeredByName }}** activó el botón de emergencia durante un viaje en Arka01.

@component('mail::panel')
Conductor: **{{ $alert->driver_name }}**
@if($alert->vehicle_plate)
Vehículo: {{ trim($alert->vehicle_make . ' ' . $alert->vehicle_model) }} — placa {{ $alert->vehicle_plate }}
@endif
@if($alert->lat && $alert->lng)
Última ubicación conocida: {{ $alert->lat }}, {{ $alert->lng }}
@endif
@endcomponent

@if($trackingUrl)
@component('mail::button', ['url' => $trackingUrl])
Ver seguimiento en vivo
@endcomponent
@endif

Este es un aviso automático generado por Arka01. Si le preocupa la seguridad de {{ $triggeredByName }}, intente contactarlo directamente.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
