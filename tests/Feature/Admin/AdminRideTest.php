<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "ayudame a que pueda eliminar carrera desde
 * el panel para poder depurar esas de prueba... y eso que elimine todo lo
 * referente a la carrera, calificaciones... recalcular... conteo de
 * carreras de conductor. y todo lo relacionado a esa carrera."
 */
class AdminRideTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_list_rides(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.rides.index'))->assertForbidden();
    }

    public function test_an_admin_can_list_rides_with_filters(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['name' => 'Cliente Uno']);
        $driver = User::factory()->create(['name' => 'Conductor Uno']);
        Ride::factory()->create(['client_user_id' => $client->id, 'driver_user_id' => $driver->id, 'status' => 'completed']);
        Ride::factory()->create(['status' => 'cancelled']);

        $response = $this->actingAs($admin)->get(route('admin.rides.index', ['status' => 'completed']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Rides')
            ->has('rides.data', 1)
            ->where('rides.data.0.client_name', 'Cliente Uno')
            ->where('rides.data.0.driver_name', 'Conductor Uno')
        );
    }

    public function test_a_regular_user_cannot_view_a_ride_detail(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $ride = Ride::factory()->create();

        $this->actingAs($user)->get(route('admin.rides.show', $ride))->assertForbidden();
    }

    /**
     * Pedido explícito del usuario: "permiteme también ver el detalle de las
     * carreras cuando le de click alguna de ellas" — toda la info relevante
     * (solicitud original, paradas, reseñas) en una sola pantalla.
     */
    public function test_an_admin_can_view_a_ride_detail(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['name' => 'Cliente Detalle']);
        $driver = User::factory()->create(['name' => 'Conductor Detalle']);

        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'is_scheduled' => true,
            'scheduled_at' => now()->addDay(),
        ]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'price' => 12.5,
        ]);
        Review::factory()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $client->id,
            'reviewee_user_id' => $driver->id,
            'rating' => 5,
            'comment' => 'Excelente viaje',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.rides.show', $ride));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/RideDetail')
            ->where('ride.id', $ride->id)
            ->where('ride.client.name', 'Cliente Detalle')
            ->where('ride.driver.name', 'Conductor Detalle')
            ->where('ride.is_scheduled', true)
            ->where('ride.price', 12.5)
            ->has('reviews', 1)
            ->where('reviews.0.comment', 'Excelente viaje')
        );
    }

    public function test_a_regular_user_cannot_delete_a_ride(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $ride = Ride::factory()->create();

        $this->actingAs($user)->delete(route('admin.rides.destroy', $ride))->assertForbidden();

        $this->assertDatabaseHas('rides', ['id' => $ride->id]);
    }

    /**
     * El caso completo: reseñas, puntos, conteos y la solicitud que la
     * originó, todo debe quedar correcto después de borrar.
     */
    public function test_deleting_a_completed_ride_cascades_reviews_and_reverts_points(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['total_points' => 10]);

        $rideRequest = RideRequest::factory()->create(['client_user_id' => $client->id, 'driver_user_id' => $driver->id]);
        $ride = Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'points_earned' => 2,
        ]);
        Review::factory()->create(['ride_id' => $ride->id, 'reviewer_user_id' => $client->id, 'reviewee_user_id' => $driver->id, 'rating' => 5]);

        // Otra carrera del mismo conductor, para probar que el conteo baja
        // en 1 y no se borra de más.
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'completed']);

        $response = $this->actingAs($admin)->delete(route('admin.rides.destroy', $ride));

        $response->assertRedirect();
        $this->assertDatabaseMissing('rides', ['id' => $ride->id]);
        $this->assertDatabaseMissing('ride_requests', ['id' => $rideRequest->id]);
        $this->assertDatabaseMissing('reviews', ['ride_id' => $ride->id]);

        // Puntos revertidos: tenía 10, la carrera dio 2, quedan 8.
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'total_points' => 8]);

        // El conteo de carreras completadas del conductor se recalcula solo
        // (nunca queda cacheado) — la otra carrera sigue ahí.
        $this->assertSame(1, Ride::query()->where('driver_user_id', $driver->id)->where('status', 'completed')->count());

        // El promedio de calificación del conductor también se recalcula
        // solo — sin reseñas, vuelve a null.
        $this->assertNull($driver->reviewsReceived()->avg('rating'));

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'ride.delete',
            'module' => 'carreras',
        ]);
    }

    /**
     * Puntos nunca deben quedar negativos — si por algún motivo la carrera
     * valía más puntos de los que el conductor tiene acumulados hoy (ajuste
     * manual previo, u otra carrera ya borrada), se planta en 0.
     */
    public function test_reverting_points_never_goes_below_zero(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['total_points' => 1]);

        $ride = Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'completed', 'points_earned' => 2]);

        $this->actingAs($admin)->delete(route('admin.rides.destroy', $ride))->assertRedirect();

        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'total_points' => 0]);
    }

    /**
     * Una carrera programada o en curso (nunca completada, sin puntos que
     * revertir) también se puede borrar — para depurar pruebas justamente
     * antes de llegar a completarse.
     */
    public function test_a_scheduled_ride_can_be_deleted_without_touching_points(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['total_points' => 5]);

        $ride = Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'scheduled', 'points_earned' => null]);

        $this->actingAs($admin)->delete(route('admin.rides.destroy', $ride))->assertRedirect();

        $this->assertDatabaseMissing('rides', ['id' => $ride->id]);
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'total_points' => 5]);
    }
}
