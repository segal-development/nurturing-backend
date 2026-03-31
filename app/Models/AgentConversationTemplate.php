<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentConversationTemplate extends Model
{
    protected $primaryKey = 'conversation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id',
        'current_template',
    ];

    protected function casts(): array
    {
        return [
            'current_template' => 'array',
        ];
    }

    /**
     * Get or create template record for a conversation.
     */
    public static function forConversation(string $conversationId): self
    {
        return self::firstOrCreate(
            ['conversation_id' => $conversationId],
            ['current_template' => null]
        );
    }

    /**
     * Update the current template.
     */
    public function setTemplate(?array $template): self
    {
        $this->current_template = $template;
        $this->save();

        return $this;
    }
}
