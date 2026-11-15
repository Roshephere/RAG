<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_belongs_to_a_knowledge_base(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Create a knowledge base
        $knowledgeBase = $user->knowledgeBases()->create([
            'name' => 'AI Research',
            'description' => 'Learning RAG systems',
        ]);

        // Create a document through the relationship
        $document = $knowledgeBase->documents()->create([
            'original_name' => 'research.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'documents/test/research.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        // Verify document belongs to knowledge base
        $this->assertTrue(
            $document->knowledgeBase->is($knowledgeBase)
        );

        // Verify knowledge base contains document
        $this->assertTrue(
            $knowledgeBase->documents()
                ->whereKey($document->id)
                ->exists()
        );

        // Verify foreign key was stored correctly
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'knowledge_base_id' => $knowledgeBase->id,
        ]);
    }
}
