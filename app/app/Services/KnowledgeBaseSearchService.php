<?php

namespace App\Services;

use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Pgvector\Laravel\Distance;

class KnowledgeBaseSearchService
{
    public function __construct(
        private EmbeddingService $embeddings
    ) {
    }

    public function search(
        User $user,
        int $knowledgeBaseId,
        string $question,
        int $limit = 5
    ): Collection {
        $question = trim($question);

        if ($question === '') {
            throw new InvalidArgumentException(
                'Question cannot be empty.'
            );
        }

        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException(
                'Search limit must be between 1 and 20.'
            );
        }

        // Security: only search a Knowledge Base
        // owned by the authenticated user.
        $knowledgeBase = $user->knowledgeBases()
            ->findOrFail($knowledgeBaseId);

        // Generate a query embedding, not a document embedding.
        $vector = $this->embeddings->embedQuery($question);

        $model = config(
            'services.gemini.embedding_model',
            'gemini-embedding-001'
        );

        // Retrieve the nearest document chunks.
        return DocumentChunk::query()
            ->with('document:id,knowledge_base_id,original_name')
            ->whereHas('document', function ($query) use ($knowledgeBase) {
                $query->where(
                    'knowledge_base_id',
                    $knowledgeBase->id
                )->where('status', 'ready');
            })
            ->where('embedding_model', $model)
            ->nearestNeighbors(
                'embedding',
                $vector,
                Distance::Cosine
            )
            ->take($limit)
            ->get();
    }
}
