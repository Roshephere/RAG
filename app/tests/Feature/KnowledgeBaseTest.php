<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;


    public function test_authenticated_user_can_create_knowledge_base(): void
    {
        // Arrange: create a test user
        $user = User::factory()->create();

        // Act: send a POST request as that user
        $response = $this->actingAs($user)
            ->post('/knowledge-bases', [
                'name' => 'Laravel Documentation',
                'description' => 'Learning Laravel and RAG',
            ]);

        // Assert: check successful redirect
        $response->assertRedirect(
            route('knowledge-bases.index')
        );

        // Assert: check database persistence
        $this->assertDatabaseHas('knowledge_bases', [
            'user_id' => $user->id,
            'name' => 'Laravel Documentation',
            'description' => 'Learning Laravel and RAG',
        ]);
    }


public function test_user_cannot_update_another_users_knowledge_base(): void
{
    // Arrange: create two separate users
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    // Create a knowledge base belonging to the owner
    $knowledgeBase = $owner->knowledgeBases()->create([
        'name' => 'Private Knowledge',
        'description' => 'Confidential documents',
    ]);

    // Act: the other user attempts to update it
    $response = $this->actingAs($otherUser)
        ->put("/knowledge-bases/{$knowledgeBase->id}", [
            'name' => 'Hacked Knowledge',
            'description' => 'Unauthorized change',
        ]);

    // Assert: unauthorized ownership returns 404
    $response->assertNotFound();

    // Assert: the original record remains unchanged
    $this->assertDatabaseHas('knowledge_bases', [
        'id' => $knowledgeBase->id,
        'user_id' => $owner->id,
        'name' => 'Private Knowledge',
        'description' => 'Confidential documents',
    ]);
}

public function test_user_cannot_delete_another_users_knowledge_base(): void
{
    // Arrange: create two users
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    // Create a knowledge base owned by User A
    $knowledgeBase = $owner->knowledgeBases()->create([
        'name' => 'Company Documents',
        'description' => 'Private company information',
    ]);

    // Act: User B attempts to delete User A's record
    $response = $this->actingAs($otherUser)
        ->delete("/knowledge-bases/{$knowledgeBase->id}");

    // Assert: access must be denied
    $response->assertNotFound();

    // Assert: the knowledge base must still exist
    $this->assertDatabaseHas('knowledge_bases', [
        'id' => $knowledgeBase->id,
        'user_id' => $owner->id,
        'name' => 'Company Documents',
    ]);
}



public function test_user_can_only_view_their_own_knowledge_bases(): void
{
    // Arrange: create two users
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // User A creates a knowledge base
    $myKnowledgeBase = $userA->knowledgeBases()->create([
        'name' => 'My Laravel Notes',
        'description' => 'Private learning materials',
    ]);

    // User B creates a knowledge base
    $userB->knowledgeBases()->create([
        'name' => 'Company Secrets',
        'description' => 'Confidential information',
    ]);

    // Act: User A visits the knowledge base page
    $response = $this->actingAs($userA)
        ->get('/knowledge-bases');

    // Assert: request succeeds
    $response->assertOk();

    // Assert: only User A's knowledge base is returned
    $response->assertInertia(fn (Assert $page) => $page
        ->component('knowledge-bases/Index')
        ->has('knowledgeBases', 1)
        ->where('knowledgeBases.0.id', $myKnowledgeBase->id)
        ->where('knowledgeBases.0.name', 'My Laravel Notes')
        ->etc()
    );
}


public function test_knowledge_base_name_is_required(): void
{
    // Arrange: create an authenticated user
    $user = User::factory()->create();

    // Act: submit a knowledge base without a name
    $response = $this->actingAs($user)
        ->post('/knowledge-bases', [
            'name' => '',
            'description' => 'My documents',
        ]);

    // Assert: Laravel should return a validation error
    $response->assertSessionHasErrors(['name']);

    // Assert: invalid data must not be stored
    $this->assertDatabaseCount('knowledge_bases', 0);
}

public function test_guest_cannot_create_knowledge_base(): void
{
    // Act: guest attempts to create a knowledge base
    $response = $this->post('/knowledge-bases', [
        'name' => 'Unauthorized Knowledge Base',
        'description' => 'Created by a guest',
    ]);

    // Assert: guest should be redirected to login
    $response->assertRedirect(route('login'));

    // Assert: no record should be created
    $this->assertDatabaseCount('knowledge_bases', 0);
}


}
