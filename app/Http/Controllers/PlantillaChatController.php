<?php

namespace App\Http\Controllers;

use App\Models\AgentConversationTemplate;
use App\Models\Plantilla;
use App\Services\AI\EmailTemplateAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlantillaChatController extends Controller
{
    /**
     * Send a message to the AI agent and receive SSE stream.
     *
     * The SDK's RemembersConversations trait handles message persistence.
     * We use SSE to provide immediate feedback while the synchronous
     * agent call processes (streaming not supported with structured output).
     *
     * POST /plantilla-chat/message
     */
    public function message(Request $request): StreamedResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
        ]);

        $user = $request->user();
        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id');

        // Build agent with SDK's conversation handling
        $agent = new EmailTemplateAgent;

        if ($conversationId) {
            // Continue existing conversation
            $agent->continue($conversationId, as: $user);
        } else {
            // Start new conversation for user
            $agent->forUser($user);
        }

        return new StreamedResponse(function () use ($agent, $userMessage, $user) {
            // Disable output buffering for real-time SSE
            if (ob_get_level()) {
                ob_end_clean();
            }

            try {
                // Emit thinking event immediately to provide feedback
                $this->emitSSE('thinking', ['content' => 'Analizando tu solicitud...']);

                // Call the agent - SDK handles message persistence automatically
                $response = $agent->prompt($userMessage);

                // Get conversation ID from response (SDK sets this)
                $conversationId = $response->conversationId;

                // Extract structured response data
                $thinking = $response['thinking'] ?? null;
                $message = $response['message'] ?? '';

                // Parse template from JSON string
                $templateJson = $response['template_json'] ?? '';
                $template = null;

                if ($templateJson && is_string($templateJson) && $templateJson !== '') {
                    $rawTemplate = json_decode($templateJson, true);

                    if ($rawTemplate && isset($rawTemplate['componentes'])) {
                        // Transform flat component structure to nested 'contenido' structure
                        $template = [
                            'nombre' => $rawTemplate['nombre'] ?? '',
                            'asunto' => $rawTemplate['asunto'] ?? '',
                            'componentes' => array_map(function ($comp, $index) {
                                $tipo = $comp['tipo'] ?? 'texto';
                                unset($comp['tipo']);

                                return [
                                    'id' => $comp['id'] ?? 'comp-'.uniqid(),
                                    'tipo' => $tipo,
                                    'orden' => $comp['orden'] ?? $index,
                                    'contenido' => $comp,
                                ];
                            }, $rawTemplate['componentes'], array_keys($rawTemplate['componentes'])),
                        ];

                        // Store current template in auxiliary table
                        if ($conversationId) {
                            AgentConversationTemplate::forConversation($conversationId)
                                ->setTemplate($template);
                        }
                    }
                }

                // Emit thinking content if available
                if ($thinking) {
                    $this->emitSSE('thinking', ['content' => $thinking]);
                }

                // Emit the message
                $this->emitSSE('message', ['content' => $message]);

                // Emit template if generated
                if ($template) {
                    $this->emitSSE('template', ['content' => $template]);
                }

                // Emit done event with complete response
                $this->emitSSE('done', [
                    'conversation_id' => $conversationId,
                    'message' => $message,
                    'template' => $template,
                ]);
            } catch (\Throwable $e) {
                Log::error('PlantillaChatController: Agent error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $user->id,
                ]);

                $this->emitSSE('error', [
                    'content' => 'Error procesando tu solicitud. Por favor, intenta de nuevo.',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Save a template generated by the AI.
     *
     * POST /plantilla-chat/save
     */
    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'template' => ['required', 'array'],
            'template.nombre' => ['required', 'string', 'max:100'],
            'template.asunto' => ['required', 'string', 'max:200'],
            'template.componentes' => ['required', 'array', 'min:1'],
            'template.componentes.*.tipo' => ['required', 'in:logo,texto,boton,separador,imagen,footer'],
            'template.componentes.*.id' => ['sometimes', 'string'],
            'template.componentes.*.orden' => ['sometimes', 'integer'],
            'template.componentes.*.url' => ['nullable', 'string'],
            'template.componentes.*.alt' => ['nullable', 'string', 'max:200'],
            'template.componentes.*.altura' => ['nullable', 'integer'],
            'template.componentes.*.ancho' => ['nullable', 'integer', 'min:50', 'max:600'],
            'template.componentes.*.alineacion' => ['nullable', 'string'],
            'template.componentes.*.color_fondo' => ['nullable', 'string'],
            'template.componentes.*.padding' => ['nullable', 'integer'],
            'template.componentes.*.texto' => ['nullable', 'string'],
            'template.componentes.*.tamanio_fuente' => ['nullable', 'integer'],
            'template.componentes.*.color' => ['nullable', 'string'],
            'template.componentes.*.color_texto' => ['nullable', 'string'],
            'template.componentes.*.negrita' => ['nullable', 'boolean'],
            'template.componentes.*.italica' => ['nullable', 'boolean'],
            'template.componentes.*.margen' => ['nullable', 'integer'],
            'template.componentes.*.link_url' => ['nullable', 'string', 'url'],
            'template.componentes.*.link_target' => ['nullable', 'string', 'in:_blank,_self'],
            'template.componentes.*.border_radius' => ['nullable', 'integer', 'min:0', 'max:50'],
            'template.componentes.*.enlaces' => ['nullable', 'array'],
            'existing_id' => ['nullable', 'integer', 'exists:plantillas,id'],
        ]);

        $templateData = $request->input('template');
        $existingId = $request->input('existing_id');

        $componentes = collect($templateData['componentes'])
            ->map(function ($componente, $index) {
                return array_merge($componente, [
                    'id' => $componente['id'] ?? 'comp-'.uniqid(),
                    'orden' => $componente['orden'] ?? $index,
                ]);
            })
            ->toArray();

        if ($existingId) {
            $plantilla = Plantilla::findOrFail($existingId);
            $plantilla->update([
                'nombre' => $templateData['nombre'],
                'asunto' => $templateData['asunto'],
                'componentes' => $componentes,
            ]);

            Log::info('PlantillaChatController: Template updated via AI', [
                'plantilla_id' => $plantilla->id,
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'id' => $plantilla->id,
                'nombre' => $plantilla->nombre,
                'message' => 'Plantilla actualizada exitosamente',
            ]);
        }

        $plantilla = Plantilla::create([
            'nombre' => $templateData['nombre'],
            'descripcion' => 'Creada con asistente de IA',
            'tipo' => 'email',
            'asunto' => $templateData['asunto'],
            'componentes' => $componentes,
            'activo' => true,
        ]);

        Log::info('PlantillaChatController: Template created via AI', [
            'plantilla_id' => $plantilla->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'id' => $plantilla->id,
            'nombre' => $plantilla->nombre,
            'message' => 'Plantilla creada exitosamente',
        ], 201);
    }

    /**
     * Get conversation history for the authenticated user.
     *
     * GET /plantilla-chat/history
     */
    public function history(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Get latest conversation from SDK tables
        $conversation = DB::table('agent_conversations')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->first();

        if (! $conversation) {
            return response()->json([
                'conversation_id' => null,
                'messages' => [],
                'current_template' => null,
            ]);
        }

        // Get messages from SDK table
        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($msg) {
                return [
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'timestamp' => $msg->created_at,
                ];
            })
            ->toArray();

        // Get current template from auxiliary table
        $templateRecord = AgentConversationTemplate::find($conversation->id);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages,
            'current_template' => $templateRecord?->current_template,
        ]);
    }

    /**
     * Clear conversation history for the authenticated user.
     *
     * DELETE /plantilla-chat/history
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Get user's conversations
        $conversationIds = DB::table('agent_conversations')
            ->where('user_id', $userId)
            ->pluck('id');

        if ($conversationIds->isNotEmpty()) {
            // Delete templates (cascade will handle this, but explicit for clarity)
            AgentConversationTemplate::whereIn('conversation_id', $conversationIds)->delete();

            // Delete messages
            DB::table('agent_conversation_messages')
                ->whereIn('conversation_id', $conversationIds)
                ->delete();

            // Delete conversations
            DB::table('agent_conversations')
                ->where('user_id', $userId)
                ->delete();

            Log::info('PlantillaChatController: History cleared', [
                'user_id' => $userId,
                'conversations_deleted' => $conversationIds->count(),
            ]);
        }

        return response()->json([
            'message' => 'Historial de conversacion eliminado',
        ]);
    }

    /**
     * Emit a Server-Sent Event.
     */
    private function emitSSE(string $type, array $data): void
    {
        $payload = array_merge(['type' => $type], $data);
        echo 'data: '.json_encode($payload)."\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
