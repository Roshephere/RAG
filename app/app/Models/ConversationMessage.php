<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    use HasFactory;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'sources',
    ];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
        ];
    }

    public function conversation():BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }
}
