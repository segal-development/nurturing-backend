<?php

namespace Tests\Feature;

use App\Models\AgentConversationTemplate;
use App\Models\Plantilla;
use App\Models\User;
use App\Services\AI\EmailTemplateAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Mockery;
use Tests\TestCase;

class PlantillaChatControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to create a conversation in the SDK tables.
     */
    protected function createConversation(int $userId, array $messages = [], ?array $currentTemplate = null): string
    {
        $conversationId = (string) Str::uuid7();

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $userId,
            'title' => 'Email Template Chat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($messages as $message) {
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'agent' => EmailTemplateAgent::class,
                'role' => $message['role'],
                'content' => $message['content'],
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'created_at' => $message['timestamp'] ?? now(),
                'updated_at' => $message['timestamp'] ?? now(),
            ]);
        }

        if ($currentTemplate !== null) {
            AgentConversationTemplate::create([
                'conversation_id' => $conversationId,
                'current_template' => $currentTemplate,
            ]);
        }

        return $conversationId;
    }

    // ============================================
    // TESTS: POST /plantilla-chat/save
    // ============================================

    /** @test */
    public function save_creates_new_template_with_valid_data(): void
    {
        $templateData = [
            'template' => [
                'nombre' => 'Recordatorio Pago Test',
                'asunto' => 'Tienes un pago pendiente',
                'componentes' => [
                    [
                        'tipo' => 'logo',
                        'url' => 'https://example.com/logo.png',
                        'alt' => 'Logo',
                        'altura' => 80,
                    ],
                    [
                        'tipo' => 'texto',
                        'texto' => 'Hola {{nombre}}, tu deuda es {{monto_deuda}}',
                        'alineacion' => 'left',
                    ],
                    [
                        'tipo' => 'boton',
                        'texto' => 'Pagar ahora',
                        'url' => 'https://example.com/pagar',
                        'color_fondo' => '#1e3a8a',
                        'color_texto' => '#ffffff',
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/save', $templateData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'nombre',
                'message',
            ])
            ->assertJson([
                'nombre' => 'Recordatorio Pago Test',
                'message' => 'Plantilla creada exitosamente',
            ]);

        $this->assertDatabaseHas('plantillas', [
            'nombre' => 'Recordatorio Pago Test',
            'asunto' => 'Tienes un pago pendiente',
            'tipo' => 'email',
            'activo' => true,
        ]);
    }

    /** @test */
    public function save_updates_existing_template_when_existing_id_provided(): void
    {
        $existingTemplate = Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Template Original',
            'asunto' => 'Asunto Original',
        ]);

        $templateData = [
            'template' => [
                'nombre' => 'Template Actualizado',
                'asunto' => 'Asunto Actualizado',
                'componentes' => [
                    [
                        'tipo' => 'texto',
                        'texto' => 'Contenido actualizado',
                    ],
                ],
            ],
            'existing_id' => $existingTemplate->id,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/save', $templateData);

        $response->assertStatus(200)
            ->assertJson([
                'id' => $existingTemplate->id,
                'nombre' => 'Template Actualizado',
                'message' => 'Plantilla actualizada exitosamente',
            ]);

        $this->assertDatabaseHas('plantillas', [
            'id' => $existingTemplate->id,
            'nombre' => 'Template Actualizado',
            'asunto' => 'Asunto Actualizado',
        ]);
    }

    /** @test */
    public function save_fails_validation_without_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/save', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template']);
    }

    /** @test */
    public function save_fails_validation_with_empty_componentes(): void
    {
        $templateData = [
            'template' => [
                'nombre' => 'Test',
                'asunto' => 'Test',
                'componentes' => [],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/save', $templateData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template.componentes']);
    }

    /** @test */
    public function save_fails_validation_with_invalid_component_type(): void
    {
        $templateData = [
            'template' => [
                'nombre' => 'Test',
                'asunto' => 'Test',
                'componentes' => [
                    [
                        'tipo' => 'invalid_type',
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/save', $templateData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template.componentes.0.tipo']);
    }

    /** @test */
    public function save_fails_when_existing_id_does_not_exist(): void
    {
        $templateData = [
            'template' => [
                'nombre' => 'Test',
                'asunto' => 'Test',
                'componentes' => [
                    ['tipo' => 'texto', 'texto' => 'Test'],
                ],
            ],
            'existing_id' => 99999,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/save', $templateData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['existing_id']);
    }

    /** @test */
    public function save_requires_authentication(): void
    {
        $response = $this->postJson('/api/plantilla-chat/save', []);

        $response->assertStatus(401);
    }

    /** @test */
    public function save_generates_component_ids_if_not_provided(): void
    {
        $templateData = [
            'template' => [
                'nombre' => 'Template Sin IDs',
                'asunto' => 'Asunto',
                'componentes' => [
                    ['tipo' => 'texto', 'texto' => 'Contenido'],
                    ['tipo' => 'boton', 'texto' => 'Click', 'url' => '#'],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/save', $templateData);

        $response->assertStatus(201);

        $plantilla = Plantilla::find($response->json('id'));
        $componentes = $plantilla->componentes;

        // Verify each component has an ID
        foreach ($componentes as $componente) {
            $this->assertArrayHasKey('id', $componente);
            $this->assertStringStartsWith('comp-', $componente['id']);
        }
    }

    // ============================================
    // TESTS: GET /plantilla-chat/history
    // ============================================

    /** @test */
    public function history_returns_empty_when_no_conversation_exists(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
            ->assertJson([
                'conversation_id' => null,
                'messages' => [],
                'current_template' => null,
            ]);
    }

    /** @test */
    public function history_returns_existing_conversation(): void
    {
        $conversationId = $this->createConversation(
            $this->user->id,
            [
                [
                    'role' => 'user',
                    'content' => 'Creame una plantilla',
                    'timestamp' => now(),
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Aqui tienes tu plantilla',
                    'timestamp' => now(),
                ],
            ],
            [
                'nombre' => 'Test',
                'asunto' => 'Test Subject',
                'componentes' => [],
            ]
        );

        $response = $this->actingAs($this->user)
            ->getJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
            ->assertJsonPath('conversation_id', $conversationId)
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'Creame una plantilla')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('current_template.nombre', 'Test');
    }

    /** @test */
    public function history_requires_authentication(): void
    {
        $response = $this->getJson('/api/plantilla-chat/history');

        $response->assertStatus(401);
    }

    /** @test */
    public function history_returns_only_current_user_conversation(): void
    {
        $otherUser = User::factory()->create();

        // Create conversation for other user
        $this->createConversation(
            $otherUser->id,
            [['role' => 'user', 'content' => 'Other user message', 'timestamp' => now()]]
        );

        // Create conversation for current user
        $this->createConversation(
            $this->user->id,
            [['role' => 'user', 'content' => 'My message', 'timestamp' => now()]]
        );

        $response = $this->actingAs($this->user)
            ->getJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.content', 'My message');
    }

    // ============================================
    // TESTS: DELETE /plantilla-chat/history
    // ============================================

    /** @test */
    public function clear_history_clears_existing_conversation(): void
    {
        $conversationId = $this->createConversation(
            $this->user->id,
            [['role' => 'user', 'content' => 'Test', 'timestamp' => now()]],
            ['nombre' => 'Test', 'asunto' => 'Test', 'componentes' => []]
        );

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Historial de conversacion eliminado',
            ]);

        // Verify conversation is deleted
        $this->assertDatabaseMissing('agent_conversations', ['id' => $conversationId]);
        $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $conversationId]);
        $this->assertDatabaseMissing('agent_conversation_templates', ['conversation_id' => $conversationId]);
    }

    /** @test */
    public function clear_history_succeeds_even_without_existing_conversation(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Historial de conversacion eliminado',
            ]);
    }

    /** @test */
    public function clear_history_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/plantilla-chat/history');

        $response->assertStatus(401);
    }

    /** @test */
    public function clear_history_only_affects_current_user(): void
    {
        $otherUser = User::factory()->create();

        // Create conversations for both users
        $otherConversationId = $this->createConversation(
            $otherUser->id,
            [['role' => 'user', 'content' => 'Other user message', 'timestamp' => now()]]
        );

        $this->createConversation(
            $this->user->id,
            [['role' => 'user', 'content' => 'My message', 'timestamp' => now()]]
        );

        // Clear current user's history
        $this->actingAs($this->user)
            ->deleteJson('/api/plantilla-chat/history');

        // Verify other user's conversation is untouched
        $this->assertDatabaseHas('agent_conversations', ['id' => $otherConversationId]);
        $this->assertDatabaseHas('agent_conversation_messages', [
            'conversation_id' => $otherConversationId,
            'content' => 'Other user message',
        ]);
    }

    // ============================================
    // TESTS: POST /plantilla-chat/message (SSE)
    // ============================================

    /** @test */
    public function message_requires_authentication(): void
    {
        $response = $this->postJson('/api/plantilla-chat/message', [
            'message' => 'Test message',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function message_fails_validation_without_message(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/message', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    /** @test */
    public function message_fails_validation_with_too_long_message(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/plantilla-chat/message', [
                'message' => str_repeat('a', 2001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    /** @test */
    public function message_returns_sse_stream_headers(): void
    {
        // For this test we just verify the endpoint accepts the request
        // and returns SSE headers. Full integration test would require
        // mocking the AI SDK which is complex.
        $response = $this->actingAs($this->user)
            ->post('/api/plantilla-chat/message', [
                'message' => 'Create a template',
            ]);

        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
    }
}
