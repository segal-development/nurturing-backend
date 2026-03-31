<?php

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class LogAgentActivity
{
    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $startTime = microtime(true);

        Log::info('Agent prompt started', [
            'agent' => $prompt->agent::class,
            'user_id' => $prompt->agent->conversationParticipant()?->id ?? null,
            'prompt_length' => strlen($prompt->prompt),
            'provider' => $prompt->provider,
            'model' => $prompt->model,
        ]);

        return $next($prompt)->then(function (AgentResponse $response) use ($prompt, $startTime) {
            $duration = round((microtime(true) - $startTime) * 1000);

            Log::info('Agent prompt completed', [
                'agent' => $prompt->agent::class,
                'user_id' => $prompt->agent->conversationParticipant()?->id ?? null,
                'duration_ms' => $duration,
                'provider' => $response->meta->provider ?? 'unknown',
                'model' => $response->meta->model ?? 'unknown',
                'input_tokens' => $response->usage->inputTokens ?? 0,
                'output_tokens' => $response->usage->outputTokens ?? 0,
                'total_tokens' => ($response->usage->inputTokens ?? 0) + ($response->usage->outputTokens ?? 0),
                'tool_calls_count' => count($response->toolCalls ?? []),
                'conversation_id' => $response->conversationId ?? null,
            ]);
        });
    }
}
