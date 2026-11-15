<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\EmbeddingService;
use App\Services\TextChunker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class IndexDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        public int $documentId
    ) {
    }

    public function handle(
        TextChunker $chunker,
        EmbeddingService $embeddings
    ): void {
        $document = Document::find($this->documentId);

        if (!$document || $document->status === 'ready') {
            return;
        }

        if (
            !in_array($document->status, [
                'extracted',
                'indexing',
                'failed'
            ], true)
        ) {
            return;
        }

        if (!$document->extracted_text) {
            throw new RuntimeException('No extracted text available.');
        }

        $document->update([
            'status' => 'indexing',
            'error_message' => null,
        ]);

        $chunks = $chunker->split($document->extracted_text);

        // Temporary MVP limit to bound API cost and processing time.
        if (count($chunks) === 0 || count($chunks) > 200) {
            throw new RuntimeException('Document has an unsupported chunk count.');
        }

        $vectors = [];

        foreach (array_chunk($chunks, 24) as $batch) {
            $vectors = array_merge(
                $vectors,
                $embeddings->embedMany($batch)
            );
        }

        DB::transaction(function () use ($chunks, $vectors) {
            $document = Document::query()
                ->lockForUpdate()
                ->find($this->documentId);

            if (!$document || $document->status === 'ready') {
                return;
            }

            $document->chunks()->delete();

            foreach ($chunks as $index => $content) {
                $document->chunks()->create([
                    'chunk_index' => $index,
                    'content' => $content,
                    'embedding' => $vectors[$index],
                    'embedding_model' => config(
                        'services.gemini.embedding_model',
                        'gemini-embedding-001'
                    ),
                ]);
            }

            $document->update([
                'status' => 'ready',
                'processed_at' => now(),
                'error_message' => null,
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Document::whereKey($this->documentId)
            ->where('status', '!=', 'ready')
            ->update([
                'status' => 'failed',
                'error_message' => substr(
                    $exception->getMessage(),
                    0,
                    1000
                ),
            ]);
    }
}
