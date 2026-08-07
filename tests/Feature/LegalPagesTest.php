<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario (gap identificado antes del despliegue):
 * páginas de Términos y Privacidad, accesibles sin sesión.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_terms_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.terms'))->assertOk();
    }

    public function test_the_privacy_page_is_publicly_accessible(): void
    {
        $this->get(route('legal.privacy'))->assertOk();
    }
}
