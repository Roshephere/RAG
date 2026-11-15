<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;
    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $documentId
    ) {

    }

    /**
     * Execute the job.
     */
    public function handle(DocumentTextExtractor $extractor): void
    {

        $document = Document::find($this->documentId);

        if (!$document) {
            return;
        }

        if (in_array($document->status, ['ready', 'indexing'], true)) {
            return;
        }

        // Extraction already succeeded: resume indexing

        if ($document->status === 'extracted') {
            IndexDocument::dispatch($this->documentId)->afterCommit();
            return;
        }

        $document->forceFill([
            'status' => 'processing',
            'error_message' => null,
        ])->save();

        $text = $extractor->extract($document);

        if (trim($text) === '') {
            throw new \RuntimeException(
                'No text could be extracted from the document.'
            );
        }
        $document->forceFill([
            'extracted_text' => $text,
            'extracted_at' => now(),
            'status' => 'extracted',
            'error_message' => null,
        ])->save();
        IndexDocument::dispatchSync($this->documentId);

    }

    public function failed(Throwable $exception): void
    {
        Document::whereKey($this->documentId)->update([
            'status' => 'failed',
            'error_message' => substr(
                $exception->getMessage(),
                0,
                1000
            ),
        ]);
    }
}
