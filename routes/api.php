<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Webhook entrante de WhatsApp Cloud API (pedido explícito del usuario): sin
// middleware de sesión/CSRF a propósito — es Meta quien llama, no un
// navegador con sesión — ver WhatsAppWebhookController. El POST (donde llegan
// mensajes reales) sí valida la firma HMAC de Meta (auditoría de seguridad,
// ver VerifyWhatsAppSignature) — el GET de verificación no la necesita,
// se valida solo con el hub_verify_token.
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('whatsapp.signed')
    ->name('webhooks.whatsapp.receive');
