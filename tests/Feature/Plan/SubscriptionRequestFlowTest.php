<?php

namespace Tests\Feature\Plan;

use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\PlanPromotion;
use App\Models\PricingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Notifications\PlanActivatedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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
        Notification::fake();

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

        // Reporte del usuario: "activé un plan y no le llegó la
        // notificación" — este es el camino de auto-activación, el otro caso
        // real que reportó (además de la aprobación manual del admin, ya
        // cubierta en AdminSubscriptionTest).
        Notification::assertSentTo($user, PlanActivatedPushNotification::class);
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

    // Promociones de precio por tiempo limitado (pedido explícito del
    // usuario: "regalar o promocionar los planes por un tiempo determinado").

    public function test_selecting_a_free_promotion_activates_immediately_and_records_it_as_used(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => '1 mes gratis',
            'promo_price' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), [
                'subscription_plan_id' => $plan->id,
                'plan_promotion_id' => $promotion->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        // Sin este registro, la promo se le seguiría ofreciendo para siempre.
        $this->assertDatabaseHas('subscription_requests', [
            'user_id' => $user->id,
            'plan_promotion_id' => $promotion->id,
            'status' => 'approved',
        ]);
    }

    public function test_the_same_promotion_cannot_be_used_twice_by_the_same_user(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => '1 mes gratis',
            'promo_price' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('subscription-requests.store'), [
            'subscription_plan_id' => $plan->id,
            'plan_promotion_id' => $promotion->id,
        ]);

        // Elegir el mismo plan ya activo está permitido (ver
        // test_can_select_the_same_plan_already_active_again) — lo único que
        // debería bloquear este segundo intento es la promoción ya usada.
        $this->actingAs($user)
            ->post(route('subscription-requests.store'), [
                'subscription_plan_id' => $plan->id,
                'plan_promotion_id' => $promotion->id,
            ])
            ->assertSessionHasErrors('plan_promotion_id');
    }

    public function test_selecting_a_paid_promotion_creates_an_awaiting_proof_request_linked_to_it(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => 'Medio precio',
            'promo_price' => $plan->monthly_price / 2,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), [
                'subscription_plan_id' => $plan->id,
                'plan_promotion_id' => $promotion->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('subscription_requests', [
            'user_id' => $user->id,
            'plan_promotion_id' => $promotion->id,
            'status' => 'awaiting_proof',
        ]);
        $this->assertDatabaseMissing('subscriptions', ['user_id' => $user->id]);
    }

    public function test_an_expired_promotion_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => 'Vencida',
            'promo_price' => 0,
            'ends_at' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), [
                'subscription_plan_id' => $plan->id,
                'plan_promotion_id' => $promotion->id,
            ])
            ->assertSessionHasErrors('plan_promotion_id');

        $this->assertDatabaseMissing('subscriptions', ['user_id' => $user->id]);
    }

    public function test_a_promotion_for_a_different_plan_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $otherPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'pro')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $otherPlan->id,
            'label' => 'De otro plan',
            'promo_price' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('subscription-requests.store'), [
                'subscription_plan_id' => $plan->id,
                'plan_promotion_id' => $promotion->id,
            ])
            ->assertSessionHasErrors('plan_promotion_id');
    }

    public function test_the_plan_catalog_exposes_the_active_promotion(): void
    {
        $driver = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => '1 mes gratis',
            'promo_price' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($driver)->get(route('driver.plan.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => collect($plans)
                ->firstWhere('code', 'plus')['active_promotion']['label'] === '1 mes gratis'
            )
        );
    }

    public function test_the_plan_catalog_hides_a_promotion_the_user_already_used(): void
    {
        $driver = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => '1 mes gratis',
            'promo_price' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($driver)->post(route('subscription-requests.store'), [
            'subscription_plan_id' => $plan->id,
            'plan_promotion_id' => $promotion->id,
        ]);

        $response = $this->actingAs($driver)->get(route('driver.plan.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => collect($plans)->firstWhere('code', 'plus')['active_promotion'] === null)
        );
    }

    /**
     * Bug real reportado por el usuario: el pedido en curso mostraba el
     * precio de LISTA del plan (ej. $15) aunque se haya elegido con una
     * promoción vigente (ej. $7) — no había coherencia con lo que mostraba
     * el catálogo. `pendingRequest` tiene que traer la promoción usada.
     */
    public function test_the_pending_request_exposes_the_promotion_it_was_created_with(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        $promotion = PlanPromotion::query()->create([
            'subscription_plan_id' => $plan->id,
            'label' => 'Medio precio',
            'promo_price' => $plan->monthly_price / 2,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('subscription-requests.store'), [
            'subscription_plan_id' => $plan->id,
            'plan_promotion_id' => $promotion->id,
        ]);

        $response = $this->actingAs($user)->get(route('driver.plan.edit'));

        // `decimal:2` en el modelo serializa promo_price como string ("7.50")
        // — se compara como número para no depender de ese detalle de cast.
        $response->assertInertia(fn ($page) => $page
            ->where('pendingRequest.plan_promotion.id', $promotion->id)
            ->where('pendingRequest.plan_promotion.promo_price', fn ($value) => (float) $value === 7.5)
        );
    }

    /**
     * Proyección de ganancia (pedido explícito del usuario: "indiquemos en
     * cada plan las carreras estimadas y un estimado a ganar mensualmente,
     * ejemplo en el básico... 150 carreras mensuales... 450 por ser un
     * ticket de 3 por carrera").
     */
    public function test_the_driver_plan_catalog_exposes_the_earnings_projection(): void
    {
        PricingSetting::current()->update(['average_ticket_price' => 3]);
        $driver = User::factory()->create();
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'basico')->firstOrFail();
        $plan->update(['estimated_monthly_rides' => 150]);

        $response = $this->actingAs($driver)->get(route('driver.plan.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('plans', function ($plans) {
                $projection = collect($plans)->firstWhere('code', 'basico')['earnings_projection'];

                return $projection
                    && (int) $projection['monthly_rides'] === 150
                    && (float) $projection['monthly_earnings'] === 450.0
                    && (float) $projection['ticket'] === 3.0;
            })
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
