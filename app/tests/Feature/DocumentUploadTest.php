<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_document_to_own_knowledge_base(): void
    {
        // Arrange: use a fake filesystem
        Storage::fake('local');

        $user = User::factory()->create();

        $kb = $user->knowledgeBases()->create([
            'name' => 'RAG Research',
            'description' => 'Learning document processing',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'notes.txt',
            'ContextIQ is a retrieval augmented generation system.'
        );

        // Act: upload the document
        $response = $this->actingAs($user)
            ->post(
                route('knowledge-bases.documents.store', $kb->id),
                ['file' => $file]
            );

        // Assert: successful redirect
        $response->assertRedirect(
            route('knowledge-bases.index')
        );

        // Assert: database record was created
        $document = $kb->documents()->sole();

        $this->assertSame('notes.txt', $document->original_name);
        $this->assertSame('pending', $document->status);
        $this->assertSame('local', $document->storage_disk);

        // Assert: file exists on the private disk
        Storage::disk('local')
            ->assertExists($document->storage_path);
    }

public function test_user_cannot_upload_to_another_users_knowledge_base(): void
{
    Storage::fake('local');

    // Arrange
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $knowledgeBase = $owner->knowledgeBases()->create([
        'name' => 'Private Research',
        'description' => 'Confidential documents',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'private.txt',
        'This is confidential information.'
    );

    // Act: another user attempts to upload
    $response = $this->actingAs($otherUser)
        ->post(
            route('knowledge-bases.documents.store', $knowledgeBase->id),
            ['file' => $file]
        );

    // Assert: access is denied
    $response->assertNotFound();

    // No document metadata should exist
    $this->assertDatabaseCount('documents', 0);

    // No uploaded files should exist
    $this->assertSame(
        [],
        Storage::disk('local')->allFiles()
    );
}

}
