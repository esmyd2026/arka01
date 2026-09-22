<?php

namespace Tests\Unit\Notifications;

use App\Models\Ride;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
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
}
