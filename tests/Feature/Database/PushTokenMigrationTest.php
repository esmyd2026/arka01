<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PushTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_a_production_schema_missing_app_version_before_adding_push_columns(): void
    {
        Schema::table('personal_access_tokens', function ($table) {
            $table->dropColumn(['push_provider', 'push_token', 'app_version']);
        });

        $migration = require database_path('migrations/2026_08_28_120000_add_push_token_to_personal_access_tokens_table.php');

        $migration->up();
        // Una reejecución también debe ser inocua si una operación anterior
        // alcanzó a crear solo parte de las columnas antes de fallar.
        $migration->up();

        $this->assertTrue(Schema::hasColumns('personal_access_tokens', [
            'device_id',
            'platform',
            'app_version',
            'push_token',
            'push_provider',
        ]));
    }
}
