<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Plantilla;
use App\Models\User;
use App\Services\AI\EmailTemplateAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
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
                'messages' => [],
                'current_template' => null,
            ]);
    }

    /** @test */
    public function history_returns_existing_conversation(): void
    {
        $conversation = AiConversation::create([
            'user_id' => $this->user->id,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Creame una plantilla',
                    'timestamp' => now()->toISOString(),
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Aqui tienes tu plantilla',
                    'template' => [
                        'nombre' => 'Test',
                        'asunto' => 'Test Subject',
                        'componentes' => [],
                    ],
                    'timestamp' => now()->toISOString(),
                ],
            ],
            'current_template' => [
                'nombre' => 'Test',
                'asunto' => 'Test Subject',
                'componentes' => [],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
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
        AiConversation::create([
            'user_id' => $otherUser->id,
            'messages' => [
                ['role' => 'user', 'content' => 'Other user message', 'timestamp' => now()->toISOString()],
            ],
            'current_template' => null,
        ]);

        // Create conversation for current user
        AiConversation::create([
            'user_id' => $this->user->id,
            'messages' => [
                ['role' => 'user', 'content' => 'My message', 'timestamp' => now()->toISOString()],
            ],
            'current_template' => null,
        ]);

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
        $conversation = AiConversation::create([
            'user_id' => $this->user->id,
            'messages' => [
                ['role' => 'user', 'content' => 'Test', 'timestamp' => now()->toISOString()],
            ],
            'current_template' => ['nombre' => 'Test', 'asunto' => 'Test', 'componentes' => []],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Historial de conversación eliminado',
            ]);

        // Verify conversation is cleared
        $conversation->refresh();
        $this->assertEmpty($conversation->messages);
        $this->assertNull($conversation->current_template);
    }

    /** @test */
    public function clear_history_succeeds_even_without_existing_conversation(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/plantilla-chat/history');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Historial de conversación eliminado',
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
        $otherConversation = AiConversation::create([
            'user_id' => $otherUser->id,
            'messages' => [
                ['role' => 'user', 'content' => 'Other user message', 'timestamp' => now()->toISOString()],
            ],
            'current_template' => null,
        ]);

        AiConversation::create([
            'user_id' => $this->user->id,
            'messages' => [
                ['role' => 'user', 'content' => 'My message', 'timestamp' => now()->toISOString()],
            ],
            'current_template' => null,
        ]);

        // Clear current user's history
        $this->actingAs($this->user)
            ->deleteJson('/api/plantilla-chat/history');

        // Verify other user's conversation is untouched
        $otherConversation->refresh();
        $this->assertNotEmpty($otherConversation->messages);
        $this->assertEquals('Other user message', $otherConversation->messages[0]['content']);
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
        // Mock the agent to avoid real AI calls
        $mockAgent = Mockery::mock(EmailTemplateAgent::class);
        $mockAgent->shouldReceive('forUser')->andReturnSelf();
        $mockAgent->shouldReceive('getConversation')->andReturn(
            AiConversation::create([
                'user_id' => $this->user->id,
                'messages' => [],
                'current_template' => null,
            ])
        );
        
        // Mock AgentResponse properly - it returns an object with text property
        $mockResponse = Mockery::mock(AgentResponse::class);
        $mockResponse->shouldReceive('toArray')->andReturn([
            'thinking' => 'Analyzing request...',
            'message' => 'Here is your template',
            'template' => null,
        ]);
        $mockResponse->text = json_encode([
            'thinking' => 'Analyzing request...',
            'message' => 'Here is your template',
            'template' => null,
        ]);
        
        $mockAgent->shouldReceive('chat')->andReturn($mockResponse);

        $this->app->instance(EmailTemplateAgent::class, $mockAgent);

        $response = $this->actingAs($this->user)
            ->post('/api/plantilla-chat/message', [
                'message' => 'Create a template',
            ]);

        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
    }
}
