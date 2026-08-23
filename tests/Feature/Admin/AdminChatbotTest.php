<?php

namespace Tests\Feature\Admin;

use App\Models\ChatbotIntent;
use App\Models\ChatbotIntentKeyword;
use App\Models\ChatbotSetting;
use App\Models\ChatbotUnrecognizedMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administración → Chatbot (pedido explícito del usuario, sección 11):
 * intenciones/vocablos, mensajes generales y consultas no reconocidas.
 * El catálogo real ya viene sembrado por las migraciones (mismo criterio
 * que `faqs`), así que estos tests corren contra datos reales.
 */
class AdminChatbotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_chatbot_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.chatbot.intents.index'))->assertForbidden();
    }

    public function test_an_admin_can_see_the_seeded_intent_catalog(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.chatbot.intents.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Chatbot/Intents')
            // 13 originales + PEDIR_CARRERA (pedido explícito del usuario:
            // "que por allí se pueda pedir también una carrera", ver
            // database/migrations/..._add_pedir_carrera_chatbot_intent.php).
            ->has('intents', 14)
        );
    }

    public function test_an_admin_can_update_an_intent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $intent = ChatbotIntent::query()->where('code', 'SOPORTE')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.chatbot.intents.update', $intent), [
            'label' => 'Hablar con un asesor',
            'role_scope' => 'ambos',
            'is_active' => false,
            'show_in_menu' => true,
            'menu_label' => '💬 Asesor humano',
            'sort_order' => 5,
        ])->assertRedirect();

        $intent->refresh();
        $this->assertSame('Hablar con un asesor', $intent->label);
        $this->assertFalse($intent->is_active);
        $this->assertSame('💬 Asesor humano', $intent->menu_label);
    }

    public function test_an_admin_can_create_a_new_intent_without_a_system_action(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.chatbot.intents.store'), [
            'code' => 'PROBLEMA_VAN',
            'label' => 'Problema con el vehículo VAN',
            'role_scope' => 'conductor',
            'show_in_menu' => false,
            'reply_message' => 'Contame más del problema con tu vehículo.',
        ])->assertRedirect();

        $intent = ChatbotIntent::query()->where('code', 'PROBLEMA_VAN')->firstOrFail();
        $this->assertNull($intent->action);
        $this->assertTrue($intent->is_active);
    }

    public function test_creating_an_intent_with_a_duplicate_code_fails(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.chatbot.intents.store'), [
            'code' => 'SOPORTE',
            'label' => 'Duplicado',
            'role_scope' => 'ambos',
            'reply_message' => 'x',
        ])->assertSessionHasErrors('code');
    }

    public function test_an_admin_can_add_and_remove_a_keyword(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $intent = ChatbotIntent::query()->where('code', 'SOPORTE')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.chatbot.intents.keywords.store', $intent), [
            'phrase' => 'Necesito Ayuda YA',
            'weight' => 3,
        ])->assertRedirect();

        // Se guarda normalizada (mismo criterio que ChatbotIntentKeyword::booted()).
        $this->assertDatabaseHas('chatbot_intent_keywords', [
            'chatbot_intent_id' => $intent->id,
            'phrase' => 'necesito ayuda ya',
            'weight' => 3,
        ]);

        $keyword = ChatbotIntentKeyword::query()->where('phrase', 'necesito ayuda ya')->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.chatbot.intents.keywords.destroy', $keyword))->assertRedirect();
        $this->assertDatabaseMissing('chatbot_intent_keywords', ['id' => $keyword->id]);
    }

    public function test_an_admin_can_update_the_general_chatbot_messages(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.chatbot.settings.update'), [
            'welcome_message' => 'Bienvenido nuevo',
            'help_message' => 'Ayuda nueva',
            'fallback_message' => 'No entendí nuevo',
            'fallback_escalation_message' => 'Necesitás soporte nuevo',
            'farewell_message' => 'Chau nuevo',
            'max_fallback_attempts' => 3,
        ])->assertRedirect();

        $settings = ChatbotSetting::current();
        $this->assertSame('Bienvenido nuevo', $settings->welcome_message);
        $this->assertSame(3, $settings->max_fallback_attempts);
        $this->assertSame($admin->id, $settings->updated_by);
    }

    private function chatbotSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'welcome_message' => 'Bienvenido',
            'help_message' => 'Ayuda',
            'fallback_message' => 'No entendí',
            'fallback_escalation_message' => 'Necesitás soporte',
            'farewell_message' => 'Chau',
            'max_fallback_attempts' => 2,
        ], $overrides);
    }

    public function test_an_admin_can_set_the_support_contact_card(): void
    {
        // Pedido explícito del usuario: "cuando mande a soporte que mande un
        // contacto, ese contacto que se actualice desde el panel admin".
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.chatbot.settings.update'), $this->chatbotSettingsPayload([
            'support_contact_name' => 'Soporte Arka01',
            'support_contact_phone' => '+593991112222',
        ]))->assertRedirect();

        $settings = ChatbotSetting::current();
        $this->assertSame('Soporte Arka01', $settings->support_contact_name);
        $this->assertSame('+593991112222', $settings->support_contact_phone);
    }

    public function test_the_support_contact_phone_needs_a_valid_international_format(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.chatbot.settings.update'), $this->chatbotSettingsPayload([
            'support_contact_name' => 'Soporte Arka01',
            'support_contact_phone' => '0991112222',
        ]))->assertSessionHasErrors('support_contact_phone');
    }

    public function test_the_support_contact_name_is_required_if_the_phone_is_set(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.chatbot.settings.update'), $this->chatbotSettingsPayload([
            'support_contact_phone' => '+593991112222',
        ]))->assertSessionHasErrors('support_contact_name');
    }

    public function test_an_admin_can_list_and_mark_reviewed_unrecognized_messages(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        $message = ChatbotUnrecognizedMessage::query()->create([
            'phone' => '+593991234567',
            'user_id' => $client->id,
            'role' => 'cliente',
            'message' => 'quiero trabajar con mi van',
            'confidence' => 22,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.chatbot.unrecognized.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Chatbot/Unrecognized')
            ->has('messages.data', 1)
            ->where('messages.data.0.message', 'quiero trabajar con mi van')
        );

        $this->actingAs($admin)->post(route('admin.chatbot.unrecognized.review', $message))->assertRedirect();
        $this->assertNotNull($message->fresh()->reviewed_at);
    }

    public function test_reviewed_messages_are_hidden_by_default(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        ChatbotUnrecognizedMessage::query()->create([
            'phone' => '+593991234567',
            'message' => 'algo raro',
            'confidence' => 10,
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.chatbot.unrecognized.index'));

        $response->assertInertia(fn ($page) => $page->has('messages.data', 0));
    }
}
