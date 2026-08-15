<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base del módulo de cooperativas de transporte.
 *
 * Las cooperativas son organizaciones verificables y NO reemplazan las
 * flotas privadas de los clientes. Los vínculos con conductores y clientes
 * viven en tablas propias para mantener intacto el comportamiento actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('driver_type')->default('independent')->after('user_id');
            $table->string('identity_document_path')->nullable()->after('license_photo_path');
            $table->string('police_record_path')->nullable()->after('identity_document_path');

            $table->index(['driver_type', 'verification_status']);
        });

        Schema::create('cooperatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('ruc', 13)->nullable()->unique();
            $table->string('main_address')->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('province')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('legal_representative')->nullable();
            $table->unsignedInteger('declared_driver_count')->default(0);
            $table->unsignedInteger('declared_unit_count')->default(0);
            $table->text('geographic_coverage')->nullable();
            $table->text('operating_hours')->nullable();
            $table->unsignedSmallInteger('response_timeout_seconds')->default(30);
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'city_id']);
        });

        Schema::create('cooperative_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label')->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cooperative_id', 'type']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('cooperative_driver_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['cooperative_id', 'driver_user_id'], 'cooperative_driver_unique');
            $table->index(['driver_user_id', 'status']);
            $table->index(['cooperative_id', 'status']);
        });

        Schema::create('client_cooperatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cooperative_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_user_id', 'cooperative_id']);
            $table->index(['cooperative_id', 'client_user_id']);
        });

        Schema::table('ride_requests', function (Blueprint $table) {
            $table->foreignId('cooperative_id')->nullable()->after('fleet_id')->constrained()->nullOnDelete();
            $table->string('cooperative_assignment_status')->nullable()->after('cooperative_id');
            $table->json('cooperative_candidate_ids')->nullable()->after('cooperative_assignment_status');
            $table->timestamp('cooperative_offer_expires_at')->nullable()->after('cooperative_candidate_ids');

            $table->index(['cooperative_id', 'status']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('max_cooperatives')->nullable()->after('max_drivers_per_fleet');
            $table->unsignedInteger('max_units')->nullable()->after('max_cooperatives');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('custom_max_cooperatives')->nullable()->after('custom_max_drivers_per_fleet');
            $table->unsignedInteger('custom_max_units')->nullable()->after('custom_max_cooperatives');
        });

        // El plan del cliente gobierna cuántas cooperativas puede guardar en
        // su red. Los valores se administran después desde /admin/planes.
        DB::table('subscription_plans')->where('owner_type', 'client')->where('code', 'gratis')->update(['max_cooperatives' => 1]);
        DB::table('subscription_plans')->where('owner_type', 'client')->where('code', 'plus')->update(['max_cooperatives' => 3]);
        DB::table('subscription_plans')->where('owner_type', 'client')->where('code', 'multiflota')->update(['max_cooperatives' => 10]);

        $now = now();
        $cooperativePlans = [
            ['code' => 'basico', 'name' => 'Básico', 'monthly_price' => 0, 'max_units' => 10, 'sort_order' => 1],
            ['code' => 'profesional', 'name' => 'Profesional', 'monthly_price' => 0, 'max_units' => 50, 'sort_order' => 2],
            ['code' => 'empresarial', 'name' => 'Empresarial', 'monthly_price' => 0, 'max_units' => null, 'sort_order' => 3],
        ];

        foreach ($cooperativePlans as $plan) {
            DB::table('subscription_plans')->updateOrInsert(
                ['owner_type' => 'cooperative', 'code' => $plan['code']],
                [
                    'name' => $plan['name'],
                    'monthly_price' => $plan['monthly_price'],
                    'max_clients' => null,
                    'estimated_monthly_rides' => null,
                    'public_visibility' => true,
                    'priority_listing' => false,
                    'verified_badge' => true,
                    'van_trips_enabled' => false,
                    'express_enabled' => true,
                    'max_fleets' => null,
                    'max_drivers_per_fleet' => null,
                    'max_cooperatives' => null,
                    'max_units' => $plan['max_units'],
                    'is_active' => true,
                    'sort_order' => $plan['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('owner_type', 'cooperative')->delete();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['custom_max_cooperatives', 'custom_max_units']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['max_cooperatives', 'max_units']);
        });

        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropForeign(['cooperative_id']);
            $table->dropIndex(['cooperative_id', 'status']);
            $table->dropColumn([
                'cooperative_id',
                'cooperative_assignment_status',
                'cooperative_candidate_ids',
                'cooperative_offer_expires_at',
            ]);
        });

        Schema::dropIfExists('client_cooperatives');
        Schema::dropIfExists('cooperative_driver_memberships');
        Schema::dropIfExists('cooperative_documents');
        Schema::dropIfExists('cooperatives');

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropIndex(['driver_type', 'verification_status']);
            $table->dropColumn(['driver_type', 'identity_document_path', 'police_record_path']);
        });
    }
};
