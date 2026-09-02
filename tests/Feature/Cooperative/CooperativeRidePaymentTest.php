<?php

namespace Tests\Feature\Cooperative;

use App\Events\RidePaymentUpdated;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RidePaymentStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CooperativeRidePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_uploads_an_optimized_private_proof_and_the_cooperative_sees_it(): void
    {
        Storage::fake('local');
        Notification::fake();
        [$owner, $client, $driver, $ride] = $this->cooperativeRide('transferencia');

        $proof = UploadedFile::fake()->image('comprobante.png', 2400, 1800);
        $this->actingAs($client)->post(route('rides.payment-proof.store', $ride), [
            'payment_proof' => $proof,
        ])->assertSessionHasNoErrors();

        $ride->refresh();
        $this->assertSame('proof_submitted', $ride->payment_status);
        $this->assertSame('image/webp', $ride->payment_proof_mime);
        $this->assertNotNull($ride->payment_proof_uploaded_at);
        Storage::disk('local')->assertExists($ride->payment_proof_path);

        [$width, $height] = getimagesize(Storage::disk('local')->path($ride->payment_proof_path));
        $this->assertLessThanOrEqual(1600, max($width, $height));
        $this->assertGreaterThan(0, $ride->payment_proof_stored_size);
        Notification::assertSentTo($owner, RidePaymentStatusNotification::class);

        $this->actingAs($owner)->get(route('cooperative.wallet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentStats.proofs_to_review', 1)
                ->has('paymentReviews', 1)
                ->where('paymentReviews.0.id', $ride->id));

        $this->actingAs($driver)->get(route('rides.payment-proof.show', $ride))->assertForbidden();
        $this->actingAs($owner)->get(route('rides.payment-proof.show', $ride))->assertOk();
    }

    public function test_cooperative_confirmation_marks_transfer_paid_for_driver_and_client(): void
    {
        Storage::fake('local');
        Notification::fake();
        [$owner, $client, $driver, $ride] = $this->cooperativeRide('transferencia');
        $this->actingAs($client)->post(route('rides.payment-proof.store', $ride), [
            'payment_proof' => UploadedFile::fake()->image('comprobante.jpg', 1800, 1200),
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('cooperative.payments.transfer.confirm', $ride))
            ->assertSessionHasNoErrors();

        $ride->refresh();
        $this->assertSame('confirmed', $ride->payment_status);
        $this->assertSame($owner->id, $ride->payment_confirmed_by_user_id);
        $this->assertNotNull($ride->payment_confirmed_at);
        Notification::assertSentTo($driver, RidePaymentStatusNotification::class);
        Notification::assertSentTo($client, RidePaymentStatusNotification::class);
    }

    public function test_rejected_transfer_proof_can_be_replaced_by_the_client(): void
    {
        Storage::fake('local');
        Notification::fake();
        [$owner, $client, , $ride] = $this->cooperativeRide('transferencia');
        $this->actingAs($client)->post(route('rides.payment-proof.store', $ride), [
            'payment_proof' => UploadedFile::fake()->image('primero.png', 1200, 900),
        ]);
        $firstPath = $ride->fresh()->payment_proof_path;

        $this->actingAs($owner)->post(route('cooperative.payments.transfer.reject', $ride), [
            'reason' => 'El monto no coincide con el total de la carrera.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $ride->fresh()->payment_status);

        $this->actingAs($client)->post(route('rides.payment-proof.store', $ride), [
            'payment_proof' => UploadedFile::fake()->image('corregido.png', 1200, 900),
        ])->assertSessionHasNoErrors();

        $ride->refresh();
        $this->assertSame('proof_submitted', $ride->payment_status);
        $this->assertNotSame($firstPath, $ride->payment_proof_path);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($ride->payment_proof_path);
    }

    public function test_driver_confirms_cash_and_the_cooperative_sees_the_paid_state(): void
    {
        Notification::fake();
        Event::fake([RidePaymentUpdated::class]);
        [$owner, $client, $driver, $ride] = $this->cooperativeRide('efectivo');

        $this->actingAs($driver)->post(route('rides.cash-payment.confirm', $ride))
            ->assertSessionHasNoErrors();

        $ride->refresh();
        $this->assertSame('confirmed', $ride->payment_status);
        $this->assertSame($driver->id, $ride->payment_confirmed_by_user_id);
        Notification::assertSentTo($owner, RidePaymentStatusNotification::class);
        Notification::assertSentTo($client, RidePaymentStatusNotification::class);
        Event::assertDispatched(RidePaymentUpdated::class, fn (RidePaymentUpdated $event) => $event->ride->is($ride));

        $this->actingAs($owner)->get(route('cooperative.wallet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentStats.confirmed', 1)
                ->where('rides.data.0.payment_status', 'confirmed'));
    }

    /** @return array{User, User, User, Ride} */
    private function cooperativeRide(string $paymentMethod): array
    {
        $owner = User::factory()->create();
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $owner->id, 'name' => 'Viaje Seguro']);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $owner->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $request = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'cooperative_id' => $cooperative->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'accepted',
        ]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $request->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'payment_method' => $paymentMethod,
            'payment_status' => 'pending',
            'status' => 'completed',
            'completed_at' => now(),
            'settled_price' => 10,
            'price' => 10,
        ]);

        return [$owner, $client, $driver, $ride];
    }
}
