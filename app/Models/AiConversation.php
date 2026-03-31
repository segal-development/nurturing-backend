<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'messages',
        'current_template',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'current_template' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns this conversation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add a message to the conversation history.
     */
    public function addMessage(string $role, string $content, ?array $template = null): void
    {
        $messages = $this->messages ?? [];
        $messages[] = [
            'role' => $role,
            'content' => $content,
            'template' => $template,
            'timestamp' => now()->toISOString(),
        ];
        $this->messages = $messages;
    }

    /**
     * Clear the conversation history.
     */
    public function clearHistory(): void
    {
        $this->messages = [];
        $this->current_template = null;
    }

    /**
     * Get the message count.
     */
    public function getMessageCountAttribute(): int
    {
        return count($this->messages ?? []);
    }
}
