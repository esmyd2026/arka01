<?php

namespace Tests\Unit\Notifications;

use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\FleetInvitationAutoAcceptedPushNotification;
use App\Notifications\FleetInvitationPushNotification;
use App\Notifications\FleetInvitationRespondedPushNotification;
use App\Notifications\RideStartedPushNotification;
use App\Services\Push\FcmSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * El canal de push nativo (Hito 6) tiene que mandar a CADA dispositivo móvil
 * con push_token registrado (un usuario puede tener varios, ver
 * personal_access_tokens.device_id) y nunca tocar dispositivos de sesión web
 * (esas filas no tienen push_provider). Reusa `toWebPush()` de la propia
 * notificación en vez de pedirle un método nuevo — este test elige
 * RideStartedPushNotification solo como caso representativo del patrón que
 * comparten las ~19 notificaciones de carreras/cooperativa/círculo de
 * confianza que ya declaran este canal.
 */
class FcmChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_to_every_registered_mobile_device_of_the_notifiable(): void
    {
        $user = User::factory()->create();
        $user->createToken('android-1')->accessToken->forceFill(['push_provider' => 'fcm', 'push_token' => 'token-1'])->save();
        $user->createToken('android-2')->accessToken->forceFill(['push_provider' => 'fcm', 'push_token' => 'token-2'])->save();
        // Sesión web sin push registrado — nunca debería recibir nada.
        $user->createToken('web-session');

        $sender = Mockery::mock(FcmSender::class);
        $sender->shouldReceive('isConfigured')->andReturn(true);
        $sender->shouldReceive('send')->once()->with('token-1', Mockery::type('string'), Mockery::type('string'), Mockery::type('array'));
        $sender->shouldReceive('send')->once()->with('token-2', Mockery::type('string'), Mockery::type('string'), Mockery::type('array'));

        $notification = new RideStartedPushNotification(Ride::factory()->create());
        (new FcmChannel($sender))->send($user, $notification);
    }

    public function test_it_does_nothing_when_the_user_has_no_mobile_device_registered(): void
    {
        $user = User::factory()->create();
        $user->createToken('web-session');

        $sender = Mockery::mock(FcmSender::class);
        $sender->shouldReceive('isConfigured')->andReturn(true);
        $sender->shouldNotReceive('send');

        $notification = new RideStartedPushNotification(Ride::factory()->create());
        (new FcmChannel($sender))->send($user, $notification);
    }

    public function test_it_does_nothing_when_fcm_is_not_configured(): void
    {
        $user = User::factory()->create();
        $user->createToken('android')->accessToken->forceFill(['push_provider' => 'fcm', 'push_token' => 'token-1'])->save();

        $sender = Mockery::mock(FcmSender::class);
        $sender->shouldReceive('isConfigured')->andReturn(false);
        $sender->shouldNotReceive('send');

        $notification = new RideStartedPushNotification(Ride::factory()->create());
        (new FcmChannel($sender))->send($user, $notification);
    }

    /**
     * Pedido explícito del usuario: "esto debe ocurrir en las dos
     * plataforma web y movil" — el aviso de una invitación de flota nueva
     * (o de que ya quedó vinculado, si el conductor tiene la aprobación
     * manual apagada) tiene que llegar por push nativo igual que por
     * WebPush, no solo al navegador. Las tres notificaciones de esta
     * familia (pendiente de responder, auto-aceptada, y la respuesta del
     * cliente/conductor) declaran FcmChannel — este test confirma que un
     * dispositivo móvil registrado de verdad recibe el envío.
     */
    public function test_fleet_invitation_notifications_reach_a_registered_mobile_device(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        $driver->createToken('android')->accessToken->forceFill(['push_provider' => 'fcm', 'push_token' => 'driver-token'])->save();

        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $client->id,
            'initiated_by' => 'client',
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $sender = Mockery::mock(FcmSender::class);
        $sender->shouldReceive('isConfigured')->andReturn(true);
        $sender->shouldReceive('send')->times(3)->with('driver-token', Mockery::type('string'), Mockery::type('string'), Mockery::type('array'));

        $channel = new FcmChannel($sender);
        $channel->send($driver, new FleetInvitationPushNotification($invitation));
        $channel->send($driver, new FleetInvitationAutoAcceptedPushNotification($invitation));
        $channel->send($driver, new FleetInvitationRespondedPushNotification($invitation, true));
    }
}
