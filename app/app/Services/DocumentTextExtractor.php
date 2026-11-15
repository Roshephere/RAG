<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentTextExtractor
{
    public function extract(Document $document): string
    {
        if ($document->storage_disk !== 'local') {
            throw new RuntimeException('Unsupported storage disk.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($document->storage_path)) {
            throw new RuntimeException('Document file not found.');
        }

        $extension = strtolower(
            pathinfo($document->original_name, PATHINFO_EXTENSION)
        );

        if (in_array($extension, ['txt', 'md'], true)) {
            $text = $disk->get($document->storage_path);
        } elseif ($extension === 'pdf') {
            $process = new Process([
                'pdftotext',
                '-layout',
                '-enc',
                'UTF-8',
                $disk->path($document->storage_path),
                '-',
            ]);

            $process->setTimeout(60);
            $process->mustRun();

            $text = $process->getOutput();
        } else {
            throw new RuntimeException('Unsupported document type.');
        }

        if (trim($text) === '') {
            throw new RuntimeException('No extractable text found.');
        }

        return $text;
    }
}
