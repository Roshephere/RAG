<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ProcessDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    public function store(
        StoreDocumentRequest $request,
        int $knowledgeBase
    ): RedirectResponse {

        // 1. Find a knowledge base owned by the user
        $kb = $request->user()
            ->knowledgeBases()
            ->findOrFail($knowledgeBase);

        // 2. Retrieve the validated uploaded file
        $file = $request->file('file');

        // 3. Store the file on the private local disk
        $path = $file->store(
            "knowledge-bases/{$kb->id}/documents",
            'local'
        );

        if ($path === false) {
            throw new RuntimeException(
                'Failed to store the uploaded file.'
            );
        }

        try {
            // 4. Save document metadata
            $document = $kb->documents()->create([
                'original_name' => $file->getClientOriginalName(),
                'storage_disk' => 'local',
                'storage_path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'status' => 'pending',
            ]);

        } catch (Throwable $e) {

            // Clean up the file if database insertion fails
            Storage::disk('local')->delete($path);

            throw $e;
        }

        ProcessDocument::dispatch($document->id)->afterCommit();

        // 5. Redirect after successful upload
        return redirect()
            ->route('knowledge-bases.index')
            ->with('success', 'Document uploaded successfully.');
    }

    public function destroy(
        Request $request,
        int $knowledgeBase,
        int $document
    ):RedirectResponse{
        $kb = $request->user()
            ->knowledgeBases()
            ->findOrFail($knowledgeBase);

            // Find the document within the knowledge base
        $doc = $kb->documents()->findOrFail($document);

        $disk = Storage::disk($doc->storage_disk);

        // remove the physical file

        if($disk->exists($doc->storage_path)){
            if(!$disk->delete($doc->storage_path)){
                throw new RuntimeException(
                    'Failed to delete the document file from storage.'
                );
            };
        }

        // remove the database record
        $doc->delete();

        return redirect()
            ->route('knowledge-bases.index')
            ->with('success', 'Document deleted successfully.');
    }


    public function download(
        Request $request,
        int $knowledgeBase,
        int $document
    ): StreamedResponse{

    $kb = $request->user()
        ->knowledgeBases()
        ->findOrFail($knowledgeBase);

        // Find the document within the knowledge base
    $doc = $kb->documents()->findOrFail($document);

    $disk = Storage::disk($doc->storage_disk);

   abort_unless($disk->exists($doc->storage_path), 404, 'Document File not found.');
   return $disk->download(
       $doc->storage_path,
       $doc->original_name,
   );
    }

}
