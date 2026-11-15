<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'knowledge_base_id',
        'original_name',
        'storage_disk',
        'storage_path',
        'mime_type',
        'size_bytes',
        'status',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
{
    return $this->hasMany(DocumentChunk::class);
}
}
