<?php

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class KnowledgeBaseController extends Controller
{
public function index(Request $request): Response
{
    $knowledgeBases = $request->user()
        ->knowledgeBases()
        ->with([
            'documents' => function ($query) {
                $query->select([
                    'id',
                    'knowledge_base_id',
                    'original_name',
                    'mime_type',
                    'size_bytes',
                    'status',
                    'created_at',
                ])->latest();
            },
        ])
        ->latest()
        ->get(['id', 'name', 'description']);
    // dd($knowledgeBases);
    return Inertia::render('knowledge-bases/Index', [
        'knowledgeBases' => $knowledgeBases,
    ]);
}

    public function store(KnowledgeRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->knowledgeBases()
            ->create($validated);

        return redirect()->route('knowledge-bases.index');
    }

    public function update(KnowledgeRequest $request, int $id): RedirectResponse
    {
        $validated = $request->validated();

        $knowledgeBase = $request->user()->knowledgeBases()->findOrFail($id);
        $knowledgeBase->update($validated);

        return redirect()->route('knowledge-bases.index');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $knowledgeBase = $request->user()->knowledgeBases()->findOrFail($id);
        foreach( $knowledgeBase->documents as $document){
            $disk = Storage::disk($document->storage_disk);

            if($disk->exists($document->storage_path)){
                if(!$disk->delete($document->storage_path)){
                    throw new RuntimeException(
                        'Failed to clean up knowledge base files.'
                    );
                }
            }
        }
        $knowledgeBase->delete();

        return redirect()->route('knowledge-bases.index');
    }
}
