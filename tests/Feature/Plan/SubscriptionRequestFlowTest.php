<?php

namespace Tests\Feature\Plan;

use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Botón de acción" para elegir un plan (consideración agregada al alcance):
 * sin pasarela de pago (sección 7.5), el usuario elige el plan y sube el
 * comprobante; un admin lo revisa y recién ahí se activa la suscripción real.
 */
class SubscriptionRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_select_a_plan_and_upload_a_payment_proof(): void
    {
        // Disco privado (auditoría de seguridad): el comprobante es un
        // documento financiero, no va al disco público.
        Storage::fake('local');
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $plan->id])
            ->assertRedirect();

        $subscriptionRequest = SubscriptionRequest::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('awaiting_proof', $subscriptionRequest->status);

        $this->actingAs($user)
            ->post(route('subscription-requests.upload-proof', $subscriptionRequest), [
                'payment_proof' => UploadedFile::fake()->image('comprobante.jpg'),
            ])
            ->assertRedirect();

        $subscriptionRequest->refresh();
        $this->assertSame('pending_review', $subscriptionRequest->status);
        Storage::disk('local')->assertExists($subscriptionRequest->payment_proof_path);
    }

    /**
     * Un plan de $0 no tiene nada que transferir — pedir comprobante no
     * tenía sentido (reporte del usuario), así que se activa directo.
     */
    public function test_selecting_a_free_plan_activates_it_immediately_without_a_payment_proof(): void
    {
        $user = User::factory()->create();
        $freePlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'gratis')->firstOrFail();

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $freePlan->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('subscription_requests', ['user_id' => $user->id]);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'subscription_plan_id' => $freePlan->id,
            'status' => 'active',
        ]);
    }

    public function test_cannot_select_a_second_plan_of_the_same_side_while_one_is_pending(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $otherPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'pro')->firstOrFail();

        $this->actingAs($user)->post(route('subscription-requests.store'), ['subscription_plan_id' => $plan->id]);

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $otherPlan->id])
            ->assertSessionHasErrors('subscription_plan_id');
    }

    public function test_admin_approving_a_request_activates_the_real_subscription(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $subscriptionRequest = SubscriptionRequest::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'payment_proof_path' => 'payment-proofs/fake.jpg',
            'status' => 'pending_review',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.subscription-requests.approve', $subscriptionRequest))
            ->assertRedirect();

        $subscriptionRequest->refresh();
        $this->assertSame('approved', $subscriptionRequest->status);
        $this->assertSame($admin->id, $subscriptionRequest->reviewed_by);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_rejecting_a_request_does_not_activate_anything(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $subscriptionRequest = SubscriptionRequest::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'payment_proof_path' => 'payment-proofs/fake.jpg',
            'status' => 'pending_review',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.subscription-requests.reject', $subscriptionRequest), ['admin_note' => 'Comprobante ilegible'])
            ->assertRedirect();

        $this->assertSame('rejected', $subscriptionRequest->fresh()->status);
        $this->assertDatabaseMissing('subscriptions', ['user_id' => $user->id]);
    }

    /**
     * Consideración agregada al alcance: no dejar elegir un plan (ni
     * siquiera volver al Gratis) que ya no le alcanza con lo que tiene armado.
     */
    public function test_driver_cannot_select_a_plan_that_does_not_fit_their_current_clients(): void
    {
        $driver = User::factory()->create();
        $tightPlan = SubscriptionPlan::query()->create([
            'owner_type' => 'driver', 'code' => 'ajustado', 'name' => 'Ajustado',
            'monthly_price' => 5, 'max_clients' => 1, 'is_active' => true,
        ]);

        // Dos clientes de confianza activos, ya por encima del cupo del plan.
        foreach (range(1, 2) as $i) {
            $client = User::factory()->create();
            $fleet = Fleet::factory()->for($client, 'owner')->create();
            FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);
        }

        $this->actingAs($driver)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $tightPlan->id])
            ->assertSessionHasErrors('subscription_plan_id');

        $this->assertDatabaseMissing('subscription_requests', ['user_id' => $driver->id]);
    }

    public function test_client_cannot_downgrade_to_a_plan_with_less_fleets_than_they_already_have(): void
    {
        $client = User::factory()->create();
        $tightPlan = SubscriptionPlan::query()->create([
            'owner_type' => 'client', 'code' => 'ajustado', 'name' => 'Ajustado',
            'monthly_price' => 0, 'max_fleets' => 1, 'max_drivers_per_fleet' => 20, 'is_active' => true,
        ]);

        Fleet::factory()->for($client, 'owner')->count(2)->create();

        $this->actingAs($client)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $tightPlan->id])
            ->assertSessionHasErrors('subscription_plan_id');
    }

    /**
     * Sección 19 de las directrices de arquitectura: las suscripciones son
     * una progresión, nunca un retroceso — ni siquiera si el cupo del plan
     * inferior le alcanzaría de sobra.
     */
    public function test_cannot_downgrade_to_a_lower_tier_plan_even_if_it_would_fit(): void
    {
        $driver = User::factory()->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $basicoPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();

        Subscription::query()->create([
            'user_id' => $driver->id,
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($driver)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $basicoPlan->id])
            ->assertSessionHasErrors('subscription_plan_id');

        $this->assertDatabaseMissing('subscription_requests', ['user_id' => $driver->id]);
    }

    public function test_can_select_the_same_plan_already_active_again(): void
    {
        $driver = User::factory()->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        Subscription::query()->create([
            'user_id' => $driver->id,
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($driver)
            ->post(route('subscription-requests.store'), ['subscription_plan_id' => $plusPlan->id])
            ->assertSessionHasNoErrors();
    }

    /**
     * El catálogo que ve el usuario tampoco debe incluir planes de nivel
     * inferior al vigente (sección 19: "no deberá visualizar nuevamente los
     * planes inferiores") — se filtra en el backend, no solo en la UI.
     */
    public function test_the_driver_plan_catalog_never_includes_lower_tier_plans(): void
    {
        $driver = User::factory()->create();
        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        Subscription::query()->create([
            'user_id' => $driver->id,
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($driver)->get(route('driver.plan.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => ! collect($plans)->contains('code', 'gratis')
                && ! collect($plans)->contains('code', 'basico')
                && collect($plans)->contains('code', 'plus')
                && collect($plans)->contains('code', 'pro')
            )
        );
    }

    /**
     * Auditoría de seguridad (pedido explícito del usuario): el comprobante
     * de pago es un documento financiero, va al disco privado — solo quien
     * lo subió o un admin pueden pedirlo.
     */
    public function test_only_the_owner_or_an_admin_can_fetch_the_payment_proof(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('payment-proofs/comprobante.jpg', 'contenido-falso');

        $client = User::factory()->create(['is_admin' => false]);
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $subscriptionRequest = SubscriptionRequest::query()->create([
            'user_id' => $client->id,
            'subscription_plan_id' => $plan->id,
            'payment_proof_path' => 'payment-proofs/comprobante.jpg',
            'status' => 'pending_review',
        ]);

        $stranger = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($stranger)
            ->get(route('subscription-requests.payment-proof', $subscriptionRequest))
            ->assertForbidden();

        $this->actingAs($client)
            ->get(route('subscription-requests.payment-proof', $subscriptionRequest))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('subscription-requests.payment-proof', $subscriptionRequest))
            ->assertOk();
    }
}
