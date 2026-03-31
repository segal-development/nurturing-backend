<?php

namespace App\Services\AI;

use App\Ai\Tools\GetBrandGuidelines;
use App\Ai\Tools\ListTemplates;
use App\Ai\Tools\LoadTemplate;
use App\Models\AiConversation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;

class EmailTemplateAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    private ?AiConversation $conversation = null;

    private Collection $conversationMessages;

    public function __construct()
    {
        $this->conversationMessages = new Collection;
    }

    /**
     * Load an existing conversation for the user.
     */
    public function forConversation(AiConversation $conversation): static
    {
        $this->conversation = $conversation;
        $this->loadConversationHistory();

        return $this;
    }

    /**
     * Create or get conversation for a user.
     */
    public function forUser(int|\App\Models\User $user): static
    {
        $userId = $user instanceof \App\Models\User ? $user->id : $user;
        
        $this->conversation = AiConversation::firstOrCreate(
            ['user_id' => $userId],
            ['messages' => [], 'current_template' => null]
        );

        $this->loadConversationHistory();

        return $this;
    }

    /**
     * Load conversation history into messages collection.
     */
    private function loadConversationHistory(): void
    {
        $this->conversationMessages = new Collection;

        if (! $this->conversation || empty($this->conversation->messages)) {
            return;
        }

        foreach ($this->conversation->messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if ($role === 'user') {
                $this->conversationMessages->push(new UserMessage($content));
            } else {
                $this->conversationMessages->push(new AssistantMessage($content));
            }
        }
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
Eres un experto en email marketing especializado en cobranza y gestion de deudas para Grupo Segal (Chile).

## Tu Rol
Ayudas a crear y modificar plantillas de email profesionales, empaticas y efectivas para campanas de nurturing de deudores. Tu objetivo es aumentar las tasas de apertura, click y conversion.

## Conocimiento de la Marca
- **Empresa:** Grupo Segal / Defensoria del Deudor
- **Color primario:** #1e3a8a (azul corporativo)
- **Color secundario:** #ffffff (blanco)
- **Color de texto:** #333333
- **Tono:** Profesional, empatico, orientado a soluciones
- **Logo URL:** https://sysgal.segal.cl/defensoria/assets/img/logo_defensoria.png

## Sistema de Componentes
Los emails se construyen con estos componentes (en orden de aparicion):

1. **logo** - Header con logo
   - url: URL de la imagen del logo
   - alt: Texto alternativo
   - altura: Altura en pixeles (recomendado: 80)
   - alineacion: center | left | right
   - color_fondo: Color hexadecimal del fondo
   - padding: Padding en pixeles

2. **texto** - Bloque de texto
   - texto: El contenido del texto
   - alineacion: left | center | right
   - tamanio_fuente: Tamano en pixeles (recomendado: 16)
   - color: Color hexadecimal del texto
   - negrita: true | false
   - italica: true | false

3. **boton** - Call to action
   - texto: Texto del boton
   - url: URL de destino
   - color_fondo: Color hexadecimal del fondo
   - color_texto: Color hexadecimal del texto
   - alineacion: center | left | right

4. **separador** - Linea divisoria
   - color: Color hexadecimal
   - altura: Altura en pixeles
   - margen: Margen vertical en pixeles

5. **imagen** - Imagen en el cuerpo
   - url: URL de la imagen
   - alt: Texto alternativo
   - ancho: Ancho maximo en pixeles
   - altura: Altura maxima en pixeles
   - alineacion: center | left | right
   - link_url: URL opcional al hacer click
   - border_radius: Radio del borde en pixeles

6. **footer** - Pie del email
   - texto: Texto del footer
   - color_fondo: Color hexadecimal del fondo
   - color_texto: Color hexadecimal del texto
   - padding: Padding en pixeles
   - enlaces: Array de {url, etiqueta} para links adicionales

## Variables Disponibles
El usuario puede usar estas variables que seran reemplazadas:
- {{nombre}} - Nombre del destinatario
- {{monto_deuda}} - Monto de la deuda formateado
- {{email}} - Email del destinatario
- {{rut}} - RUT del destinatario
- {{telefono}} - Telefono del destinatario

## Herramientas Disponibles
- **ListTemplates**: Buscar plantillas existentes para inspiracion
- **LoadTemplate**: Cargar una plantilla completa por ID
- **GetBrandGuidelines**: Obtener la guia de marca completa

## Reglas de Respuesta
1. SIEMPRE responde en espanol
2. Cuando crees o modifiques una plantilla, incluye el template completo en tu respuesta
3. Explica brevemente tus decisiones de diseno en el campo "thinking"
4. El campo "message" debe ser tu respuesta conversacional al usuario
5. El campo "template" solo debe incluirse cuando generes/modifiques una plantilla
6. Sigue SIEMPRE la guia de marca (colores, tono, estructura)
7. Asegurate de que los asuntos sean atractivos y no activen filtros de spam
8. Mantén los emails concisos y con un solo CTA principal
INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new ListTemplates,
            new LoadTemplate,
            new GetBrandGuidelines,
        ];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'thinking' => $schema->string()
                ->description('Tu razonamiento interno sobre la solicitud del usuario y tus decisiones de diseno. No se mostrara al usuario.')
                ->required(),

            'message' => $schema->string()
                ->description('Tu respuesta conversacional al usuario, explicando que hiciste o preguntando por mas detalles.')
                ->required(),

            'template' => $schema->object([
                'nombre' => $schema->string()
                    ->description('Nombre identificador de la plantilla (ej: CAMP-EMAIL-D001-Bienvenida)')
                    ->required(),

                'asunto' => $schema->string()
                    ->description('Linea de asunto del email (max 60 caracteres)')
                    ->required(),

                'componentes' => $schema->array()
                    ->items($schema->object([
                        'tipo' => $schema->string()
                            ->enum(['logo', 'texto', 'boton', 'separador', 'imagen', 'footer'])
                            ->description('Tipo de componente')
                            ->required(),

                        // Logo properties
                        'url' => $schema->string()
                            ->description('URL de la imagen (para logo e imagen)')
                            ->nullable(),

                        'alt' => $schema->string()
                            ->description('Texto alternativo para la imagen')
                            ->nullable(),

                        'altura' => $schema->integer()
                            ->description('Altura en pixeles')
                            ->nullable(),

                        'ancho' => $schema->integer()
                            ->description('Ancho en pixeles')
                            ->nullable(),

                        'alineacion' => $schema->string()
                            ->enum(['left', 'center', 'right'])
                            ->description('Alineacion del componente')
                            ->nullable(),

                        'color_fondo' => $schema->string()
                            ->description('Color de fondo hexadecimal (ej: #1e3a8a)')
                            ->nullable(),

                        'padding' => $schema->integer()
                            ->description('Padding en pixeles')
                            ->nullable(),

                        // Text properties
                        'texto' => $schema->string()
                            ->description('Contenido de texto')
                            ->nullable(),

                        'tamanio_fuente' => $schema->integer()
                            ->description('Tamano de fuente en pixeles')
                            ->nullable(),

                        'color' => $schema->string()
                            ->description('Color del texto hexadecimal')
                            ->nullable(),

                        'color_texto' => $schema->string()
                            ->description('Color del texto para botones y footer')
                            ->nullable(),

                        'negrita' => $schema->boolean()
                            ->description('Si el texto es negrita')
                            ->nullable(),

                        'italica' => $schema->boolean()
                            ->description('Si el texto es italica')
                            ->nullable(),

                        // Separator properties
                        'margen' => $schema->integer()
                            ->description('Margen vertical para separadores')
                            ->nullable(),

                        // Image properties
                        'link_url' => $schema->string()
                            ->description('URL de destino al hacer click en la imagen')
                            ->nullable(),

                        'border_radius' => $schema->integer()
                            ->description('Radio del borde en pixeles')
                            ->nullable(),

                        // Footer properties
                        'enlaces' => $schema->array()
                            ->items($schema->object([
                                'url' => $schema->string()->required(),
                                'etiqueta' => $schema->string()->required(),
                            ]))
                            ->description('Enlaces adicionales para el footer')
                            ->nullable(),
                    ]))
                    ->description('Lista de componentes del email en orden')
                    ->required(),
            ])
                ->description('La plantilla generada o modificada. Solo incluir cuando se crea/modifica una plantilla.')
                ->nullable(),
        ];
    }

    /**
     * Get the conversation messages for context.
     */
    public function messages(): iterable
    {
        return $this->conversationMessages;
    }

    /**
     * Send a message and get a response.
     */
    public function chat(string $message): AgentResponse
    {
        // Add user message to conversation
        if ($this->conversation) {
            $this->conversation->addMessage('user', $message);
            $this->conversationMessages->push(new UserMessage($message));
        }

        // Use the default provider configured in config/ai.php
        $response = $this->prompt(prompt: $message);

        // Save assistant response to conversation
        if ($this->conversation) {
            // Get response data - AgentResponse has a text property with JSON
            $responseData = json_decode($response->text, true) ?? [];
            $template = $responseData['template'] ?? null;
            $assistantMessage = $responseData['message'] ?? $response->text;
            
            $this->conversation->addMessage('assistant', $assistantMessage, $template);

            if ($template) {
                $this->conversation->current_template = $template;
            }

            $this->conversation->save();
        }

        return $response;
    }

    /**
     * Get the current template from the conversation.
     */
    public function getCurrentTemplate(): ?array
    {
        return $this->conversation?->current_template;
    }

    /**
     * Clear the conversation history.
     */
    public function clearHistory(): void
    {
        if ($this->conversation) {
            $this->conversation->clearHistory();
            $this->conversation->save();
        }

        $this->conversationMessages = new Collection;
    }

    /**
     * Get the conversation model.
     */
    public function getConversation(): ?AiConversation
    {
        return $this->conversation;
    }
}
