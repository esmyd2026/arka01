<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Usuario" autogenerado (consideración agregada al alcance): primera letra
 * del primer nombre + primer apellido, con reglas de respaldo cuando ya
 * existe (segundo apellido, segundo nombre, y por último un número).
 */
class UsernameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_first_initial_plus_first_surname(): void
    {
        $this->assertSame('jperez', UsernameGenerator::generate('Juan Pérez'));
    }

    public function test_assumes_two_surnames_when_the_name_has_three_words(): void
    {
        $this->assertSame('jperez', UsernameGenerator::generate('Juan Pérez Gómez'));
    }

    public function test_uses_the_first_and_third_word_with_four_or_more_words(): void
    {
        $this->assertSame('jperez', UsernameGenerator::generate('Juan Carlos Pérez Gómez'));
    }

    public function test_falls_back_to_second_surname_initial_on_collision(): void
    {
        User::factory()->create(['username' => 'jperez']);

        $this->assertSame('jperezg', UsernameGenerator::generate('Juan Pérez Gómez'));
    }

    public function test_falls_back_to_a_number_when_every_initial_variant_is_taken(): void
    {
        User::factory()->create(['username' => 'jperez']);
        User::factory()->create(['username' => 'jperezg']);

        $this->assertSame('jperez2', UsernameGenerator::generate('Juan Pérez Gómez'));
    }

    public function test_generated_usernames_are_lowercase_without_accents(): void
    {
        $this->assertSame('mnino', UsernameGenerator::generate('María Niño'));
    }
}
