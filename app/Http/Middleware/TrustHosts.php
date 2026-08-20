<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $hosts = [
            $this->allSubdomainsOfApplicationUrl(),
        ];

        // PHPUnit usa localhost y el servidor local puede abrirse por IP.
        // Estas excepciones jamás se agregan en producción: allí solo se
        // acepta el dominio de APP_URL y sus subdominios (por ejemplo www).
        if (app()->environment(['local', 'testing'])) {
            $hosts[] = '^localhost$';
            $hosts[] = '^127\.0\.0\.1$';
        }

        return array_values(array_filter($hosts));
    }
}
