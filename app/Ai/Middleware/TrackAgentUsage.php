<?php

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class TrackAgentUsage
{
    /**
     * Handle the incoming prompt.
     *
     * Tracks usage metrics for analytics and rate limiting awareness.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $userId = $prompt->agent->conversationParticipant()?->id;

        return $next($prompt)->then(function (AgentResponse $response) use ($userId) {
            $this->trackMetrics($userId, $response);
        });
    }

    /**
     * Track usage metrics.
     */
    protected function trackMetrics(?int $userId, AgentResponse $response): void
    {
        $today = now()->toDateString();
        $provider = $response->meta->provider ?? 'unknown';
        $model = $response->meta->model ?? 'unknown';

        // Increment daily counters in cache (for quick access)
        $cacheKey = "ai_usage:{$today}";
        $usage = Cache::get($cacheKey, [
            'total_requests' => 0,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'by_provider' => [],
            'by_user' => [],
        ]);

        $inputTokens = $response->usage->inputTokens ?? 0;
        $outputTokens = $response->usage->outputTokens ?? 0;

        $usage['total_requests']++;
        $usage['total_input_tokens'] += $inputTokens;
        $usage['total_output_tokens'] += $outputTokens;

        // Track by provider
        if (! isset($usage['by_provider'][$provider])) {
            $usage['by_provider'][$provider] = ['requests' => 0, 'tokens' => 0];
        }
        $usage['by_provider'][$provider]['requests']++;
        $usage['by_provider'][$provider]['tokens'] += $inputTokens + $outputTokens;

        // Track by user (top users)
        if ($userId) {
            if (! isset($usage['by_user'][$userId])) {
                $usage['by_user'][$userId] = ['requests' => 0, 'tokens' => 0];
            }
            $usage['by_user'][$userId]['requests']++;
            $usage['by_user'][$userId]['tokens'] += $inputTokens + $outputTokens;
        }

        // Store in cache for 48 hours (to cover timezone differences)
        Cache::put($cacheKey, $usage, now()->addHours(48));

        // Also store individual request for detailed analytics (optional - can be async)
        $this->storeDetailedRecord($userId, $response, $provider, $model);
    }

    /**
     * Store detailed record for analytics.
     */
    protected function storeDetailedRecord(?int $userId, AgentResponse $response, string $provider, string $model): void
    {
        // Only store if table exists (optional feature)
        try {
            if (DB::getSchemaBuilder()->hasTable('ai_usage_logs')) {
                DB::table('ai_usage_logs')->insert([
                    'user_id' => $userId,
                    'conversation_id' => $response->conversationId,
                    'provider' => $provider,
                    'model' => $model,
                    'input_tokens' => $response->usage->inputTokens ?? 0,
                    'output_tokens' => $response->usage->outputTokens ?? 0,
                    'tool_calls' => count($response->toolCalls ?? []),
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Silently fail - analytics shouldn't break the main flow
        }
    }
}
