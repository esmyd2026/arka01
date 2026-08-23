<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * El servidor real ("VPS a mano", Nginx + PHP-FPM en la misma máquina,
     * ver deploy/) no confiaba en ningún proxy por defecto (`$proxies` sin
     * valor) — así Laravel nunca lee `X-Forwarded-Proto` y ve cada
     * request como HTTP plano aunque el navegador esté en HTTPS de
     * verdad, porque quien atiende PHP-FPM es Nginx en localhost, no el
     * navegador directo. Se confía en '*' porque PHP-FPM no queda expuesto
     * a Internet (solo Nginx sí) — si eso cambiara, esto habría que
     * ajustarlo a la IP puntual del proxy.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
