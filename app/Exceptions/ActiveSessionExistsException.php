<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sesión única por cuenta (consideración de seguridad agregada al alcance):
 * si ya hay una sesión activa en otro dispositivo, no se permite iniciar una
 * segunda hasta que se cierre la primera. Evita que una cuenta se comparta
 * entre varias personas sin control de quién administra la flota en cada
 * momento — aplica tanto a clientes como a conductores.
 */
class ActiveSessionExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ya tiene una sesión activa en otro dispositivo. Cierre sesión ahí para poder entrar acá.');
    }
}
