<?php

namespace Tests\Unit\Services\Push;

use App\Services\Push\FcmSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Push nativo (FCM, HTTP v1) — groundwork del Hito 6 (roadmap app móvil).
 * Sin credenciales de Firebase en .env (PENDIENTES_USUARIO_APP_MOVIL.md,
 * punto 1) el envío tiene que ser un no-op silencioso, nunca un error que
 * tumbe el resto del flujo de notificaciones — eso es lo que cubren la
 * mayoría de estos tests, junto con el camino feliz simulado con Http::fake().
 */
class FcmSenderTest extends TestCase
{
    // Clave RSA de prueba, generada una sola vez con el CLI de openssl y
    // fijada acá — nunca se usó ni se usará para nada real. `openssl_sign()`
    // (lo único que FcmSender llama en producción) firma bien con esto; la
    // GENERACIÓN de una clave nueva en el momento vía openssl_pkey_new()
    // depende de que PHP encuentre un openssl.cnf configurado, algo que en
    // Windows suele faltar y no tiene nada que ver con si firmar funciona.
    private const TEST_PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEuwIBADANBgkqhkiG9w0BAQEFAASCBKUwggShAgEAAoIBAQCidnfHSJxkaVUp
    e/gD+WOqHJRUjnym/P3C/dh6q74MvtS2W25aiyHQOIzO/0ZSW290br/C4RupFSRl
    lNsGesFrz2ILRkjKmEh5PxOsHVD4Bgg6nAT2X3UwQ5eTs4SXwY3H7Za96TZUt730
    jVEqn3jPB4260wGbLYsLTbxGUINfmdZXsxboXUejaDIxLOdr5isyHwqx+U8aZr9z
    MuuaRq3o9bcMQ7ixuHKY6k7kMdF5gNIDKPU8BYJVHU2P/6Rbq0qcJZky6P+8Q6xh
    jCm1IXEsCjLF7AeLgq9X49tpSjQqFXP/CHdHpNVntvgDDDN6A4Je6FOQl5OE5PGv
    7eE/nelxAgMBAAECgf9AcdeRqOJgvzSVzPDVCSU/yMmnwRD+LKnjPE7JjaOvhDaP
    7JtNApcNjpRMiENptMH0rcawYOgk0JfF5wZhZTbUs68KSePi4AV5Rkwt06ZxIsA6
    f4+rkeVdzKVgy0hz6mBGrmuqNph9HDKw9fdi5CxH85C4S0gxbIXhz5qfsDbTKARk
    XxNIkcmtEt5uwkfE3qHJElT03PPEeceQf+mYgCFQIentD6ZK0u+4AJEqnwHM5ms4
    tGhmR6djwm8P0sqVzviatAB3T2GRYzbDnnPOnMq8MyXeJZ8uitM/pTjYjK7xg+YP
    Eu6Zt15vuxlF+tuDtQFr1rpRJLv1atdCS833ybECgYEAz/z9WdqjUZTg1vbFf+CQ
    2CinHMFYKgdKLejAj2ICZjnkgarsnynEUU7P4ewDrKKtZ39zBjt9t+26sCI16u6p
    RxqhWXmkMu5cW1M2KChyzyAaVw0NPVlJ4X9/ZmxtJlfnbFW+i8pqtpu6TKkKvWq8
    ymii/yY0TGZGjX3NrMU/3dkCgYEAx/cph/nS/aF4Plj2j243cunyp6lMzELQEN9U
    Co2xfzx1mZDMAZLcPCfY6tewqyZ4HC9zz7tcrJuI36zrPqKT9ZvMlYNKeq12bMgr
    3W9GpbU86kycAvh1CVywaSlNqmNjflYKip/EmK07ek8X8hp1Ld625lzY4EKp/SZP
    cDBFcVkCgYEApl0BNY8p1YaVNbzbiwbQNOaHjPumDrAYCJLE+6t0qdoiygkzDbi1
    3CeDJn3HDp5sv311PwX08FsKWfpzUECoTESjzNL8oPSCEVaE2ihIdR6Dkx/QeELk
    UqfN+to9kE79wGzxZhV5uCjmIo2QFqS679g0kIw5hubEBkcbbde01zkCgYAvs8SS
    jYRJwQVASlS3LqHHpgue94I8kAu2mrAfvGGFatTbB3HDRleHejIDA+U/TvQENUW5
    XobjHk9unH+Z94q4YqxRVnqj/VFs7euY7Xy69xtLAW+OPdsdmqDy1UmTxhXsA3aM
    T0TE7z5rFQcvWCRwBts25RtPlFec7lCxALLwMQKBgAifqbsv04TtPc8MyMkRSgfm
    Y1lf8BMdUkbcTRiS7kr+d6RJasN7SeCKYxI7TixhaiLnFnbQQ1A3hCdVnbDVYJGP
    GHKgJZ9k35wFSvrLEs1icGUfyQvR+yroWylLQjIBQ7aHQOZffpVC+BEpYg/h2p6+
    EhMsuQEZBx3VUOl1uSZa
    -----END PRIVATE KEY-----
    PEM;

    private function fakeCredentialsFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fcm-test-credentials-').'.json';
        file_put_contents($path, json_encode([
            'client_email' => 'test-fcm@arka01-test.iam.gserviceaccount.com',
            'private_key' => self::TEST_PRIVATE_KEY,
        ]));

        return $path;
    }

    public function test_is_not_configured_without_env_vars(): void
    {
        config(['fcm.project_id' => null, 'fcm.credentials_path' => null]);

        $this->assertFalse((new FcmSender)->isConfigured());
    }

    public function test_is_not_configured_when_the_credentials_file_does_not_exist(): void
    {
        config(['fcm.project_id' => 'arka01-test', 'fcm.credentials_path' => '/does/not/exist.json']);

        $this->assertFalse((new FcmSender)->isConfigured());
    }

    public function test_send_is_a_silent_no_op_when_not_configured(): void
    {
        Http::fake();
        config(['fcm.project_id' => null, 'fcm.credentials_path' => null]);

        $sent = (new FcmSender)->send('device-token', 'Título', 'Cuerpo');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_send_exchanges_the_service_account_jwt_and_posts_to_fcm(): void
    {
        $path = $this->fakeCredentialsFile();
        config(['fcm.project_id' => 'arka01-test', 'fcm.credentials_path' => $path]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/arka01-test/messages/1'], 200),
        ]);

        $sent = (new FcmSender)->send('device-token-123', 'Nueva carrera', 'Un cliente te pidió una carrera.', ['ride_request_id' => '5']);

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'fcm.googleapis.com/v1/projects/arka01-test/messages:send')
                && $request->hasHeader('Authorization', 'Bearer fake-access-token')
                && $request['message']['token'] === 'device-token-123'
                && $request['message']['notification']['title'] === 'Nueva carrera'
                && $request['message']['data']['ride_request_id'] === '5';
        });
    }

    public function test_send_returns_false_when_fcm_rejects_the_message(): void
    {
        $path = $this->fakeCredentialsFile();
        config(['fcm.project_id' => 'arka01-test', 'fcm.credentials_path' => $path]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
            'fcm.googleapis.com/*' => Http::response(['error' => ['message' => 'Requested entity was not found.']], 404),
        ]);

        $this->assertFalse((new FcmSender)->send('stale-token', 'Título', 'Cuerpo'));
    }

    public function test_the_access_token_is_cached_and_not_fetched_twice(): void
    {
        Cache::flush();
        $path = $this->fakeCredentialsFile();
        config(['fcm.project_id' => 'arka01-test', 'fcm.credentials_path' => $path]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok'], 200),
        ]);

        $sender = new FcmSender;
        $sender->send('token-a', 'Título', 'Cuerpo');
        $sender->send('token-b', 'Título', 'Cuerpo');

        Http::assertSentCount(3); // 1 canje de token + 2 envíos a FCM.
    }
}
