<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\RagAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private function createKnowledgeBase(User $user): KnowledgeBase
    {
        return $user->knowledgeBases()->create([
            'name' => 'Test Knowledge Base',
        ]);
    }

    private function createConversation(
        User $user,
        KnowledgeBase $knowledgeBase
    ): Conversation {
        return $knowledgeBase->conversations()->create([
            'user_id' => $user->id,
            'title' => 'Test Conversation',
        ]);
    }

    public function test_user_can_create_conversation(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $response = $this->actingAs($user)->postJson(
            "/knowledge-bases/{$kb->id}/conversations",
            [
                'title' => 'My First Conversation',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.title', 'My First Conversation');

        $this->assertDatabaseHas('conversations', [
            'user_id' => $user->id,
            'knowledge_base_id' => $kb->id,
            'title' => 'My First Conversation',
        ]);
    }

    public function test_user_can_create_conversation_without_title(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $response = $this->actingAs($user)->postJson(
            "/knowledge-bases/{$kb->id}/conversations",
            []
        );

        $response->assertCreated()
            ->assertJsonPath('data.title', 'New Chat');

        $this->assertDatabaseHas('conversations', [
            'user_id' => $user->id,
            'knowledge_base_id' => $kb->id,
            'title' => 'New Chat',
        ]);
    }

    public function test_user_can_list_conversations(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);
        $otherKb = $this->createKnowledgeBase($user);

        $conversation = $this->createConversation($user, $kb);

        $this->createConversation($user, $otherKb);

        $response = $this->actingAs($user)->getJson(
            "/knowledge-bases/{$kb->id}/conversations"
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id);
    }

    public function test_user_can_view_conversation_with_messages(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $conversation = $this->createConversation($user, $kb);

        $conversation->messages()->create([
            'role' => ConversationMessage::ROLE_USER,
            'content' => 'What is ContextIQ?',
        ]);

        $conversation->messages()->create([
            'role' => ConversationMessage::ROLE_ASSISTANT,
            'content' => 'ContextIQ is an AI knowledge assistant.',
            'sources' => [],
        ]);

        $response = $this->actingAs($user)->getJson(
            "/knowledge-bases/{$kb->id}/conversations/{$conversation->id}"
        );

        $response->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.1.role', 'assistant');
    }

    public function test_user_cannot_access_another_users_conversations(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $kb = $this->createKnowledgeBase($owner);

        $conversation = $this->createConversation($owner, $kb);

        $url = "/knowledge-bases/{$kb->id}/conversations";

        $this->mock(RagAnswerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('answer');
        });

        $this->actingAs($otherUser);

        $this->getJson($url)->assertNotFound();

        $this->postJson($url, [
            'title' => 'Unauthorized Conversation',
        ])->assertNotFound();

        $this->getJson(
            "{$url}/{$conversation->id}"
        )->assertNotFound();

        $this->postJson(
            "{$url}/{$conversation->id}/messages",
            ['content' => 'Unauthorized question']
        )->assertNotFound();

        $this->deleteJson(
            "{$url}/{$conversation->id}"
        )->assertNotFound();

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
        ]);
    }

    public function test_conversation_cannot_be_accessed_from_another_knowledge_base(): void
    {
        $user = User::factory()->create();

        $firstKb = $this->createKnowledgeBase($user);
        $secondKb = $this->createKnowledgeBase($user);

        $conversation = $this->createConversation($user, $firstKb);

        $this->mock(RagAnswerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('answer');
        });

        $url = "/knowledge-bases/{$secondKb->id}/conversations/{$conversation->id}";

        $this->actingAs($user);

        $this->getJson($url)->assertNotFound();

        $this->postJson("{$url}/messages", [
            'content' => 'What is ContextIQ?',
        ])->assertNotFound();

        $this->deleteJson($url)->assertNotFound();
    }

    public function test_user_can_send_message_and_receive_ai_answer(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $conversation = $kb->conversations()->create([
            'user_id' => $user->id,
        ]);

        $question = 'What is ContextIQ?';

        $answer = 'ContextIQ is an AI-powered knowledge assistant. [1]';

        $sources = [
            [
                'number' => 1,
                'document_id' => 1,
                'chunk_id' => 1,
                'chunk_index' => 0,
                'document' => 'contextiq.txt',
                'distance' => 0.21,
            ],
        ];

        $this->mock(
            RagAnswerService::class,
            function (MockInterface $mock) use ($user, $kb, $question, $answer, $sources) {
                $mock->shouldReceive('answer')
                    ->once()
                    ->withArgs(
                        fn($actualUser, $knowledgeBaseId, $content) =>
                            $actualUser->is($user)
                            && $knowledgeBaseId === $kb->id
                            && $content === $question
                    )
                    ->andReturn([
                        'answer' => $answer,
                        'sources' => $sources,
                    ]);
            }
        );

        $response = $this->actingAs($user)->postJson(
            "/knowledge-bases/{$kb->id}/conversations/{$conversation->id}/messages",
            [
                'content' => $question,
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.user_message.content', $question)
            ->assertJsonPath('data.assistant_message.content', $answer)
            ->assertJsonPath(
                'data.assistant_message.sources.0.document',
                'contextiq.txt'
            );

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => ConversationMessage::ROLE_USER,
            'content' => $question,
        ]);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => ConversationMessage::ROLE_ASSISTANT,
            'content' => $answer,
        ]);

        $assistantMessage = $conversation->messages()
            ->where('role', ConversationMessage::ROLE_ASSISTANT)
            ->firstOrFail();

        $this->assertEquals($sources, $assistantMessage->sources);

        $this->assertCount(2, $conversation->messages()->get());

        $conversation->refresh();

        $this->assertEquals($question, $conversation->title);

        $this->assertNotNull($conversation->last_message_at);
    }

    public function test_message_content_is_required(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $conversation = $this->createConversation($user, $kb);

        $this->mock(RagAnswerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('answer');
        });

        $this->actingAs($user)->postJson(
            "/knowledge-bases/{$kb->id}/conversations/{$conversation->id}/messages",
            []
        )->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_message_cannot_exceed_maximum_length(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $conversation = $this->createConversation($user, $kb);

        $this->mock(RagAnswerService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('answer');
        });

        $this->actingAs($user)->postJson(
            "/knowledge-bases/{$kb->id}/conversations/{$conversation->id}/messages",
            [
                'content' => str_repeat('a', 1001),
            ]
        )->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_messages_are_not_saved_when_ai_generation_fails(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $conversation = $this->createConversation($user, $kb);

        $this->mock(RagAnswerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('answer')
                ->once()
                ->andThrow(
                    new RuntimeException('Gemini API unavailable.')
                );
        });

        $response = $this->actingAs($user)->postJson(
            "/knowledge-bases/{$kb->id}/conversations/{$conversation->id}/messages",
            [
                'content' => 'What is ContextIQ?',
            ]
        );

        $response->assertStatus(502);

        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_id' => $conversation->id,
        ]);

        $this->assertNull($conversation->fresh()->last_message_at);
    }

    public function test_user_can_delete_conversation_and_its_messages(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $conversation = $this->createConversation($user, $kb);

        $message = $conversation->messages()->create([
            'role' => ConversationMessage::ROLE_USER,
            'content' => 'Test question',
        ]);

        $this->actingAs($user)->deleteJson(
            "/knowledge-bases/{$kb->id}/conversations/{$conversation->id}"
        )->assertNoContent();

        $this->assertDatabaseMissing('conversations', [
            'id' => $conversation->id,
        ]);

        $this->assertDatabaseMissing('conversation_messages', [
            'id' => $message->id,
        ]);
    }

    public function test_guest_cannot_access_conversations(): void
    {
        $user = User::factory()->create();

        $kb = $this->createKnowledgeBase($user);

        $this->getJson(
            "/knowledge-bases/{$kb->id}/conversations"
        )->assertUnauthorized();
    }

    public function test_follow_up_question_receives_conversation_history(): void
    {
        $user = \App\Models\User::factory()->create();

        $kb = $user->knowledgeBases()->create([
            'name' => 'ContextIQ Test',
        ]);

        $conversation = $kb->conversations()->create([
            'user_id' => $user->id,
            'title' => 'What is ContextIQ?',
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'What is ContextIQ?',
            'sources' => null,
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'ContextIQ is a personal AI knowledge assistant.',
            'sources' => [],
        ]);

        $question = 'What technologies does it use?';

        $expectedHistory = [
            [
                'role' => 'user',
                'content' => 'What is ContextIQ?',
            ],
            [
                'role' => 'assistant',
                'content' => 'ContextIQ is a personal AI knowledge assistant.',
            ],
        ];

        $answer = 'ContextIQ uses Laravel, Vue, PostgreSQL and Gemini.';

        $this->mock(
            \App\Services\RagAnswerService::class,
            function ($mock) use ($user, $kb, $question, $expectedHistory, $answer) {
                $mock->shouldReceive('answer')
                    ->once()
                    ->withArgs(function ($actualUser, $knowledgeBaseId, $actualQuestion, $history) use ($user, $kb, $question, $expectedHistory) {
                        return $actualUser->id === $user->id
                            && $knowledgeBaseId === $kb->id
                            && $actualQuestion === $question
                            && $history === $expectedHistory;
                    })
                    ->andReturn([
                        'answer' => $answer,
                        'sources' => [],
                    ]);
            }
        );

        $response = $this->actingAs($user)->postJson(
            "/knowledge-bases/{$kb->id}/conversations/{$conversation->id}/messages",
            [
                'content' => $question,
            ]
        );

        $response->assertCreated()
            ->assertJsonPath(
                'data.assistant_message.content',
                $answer
            )
            ->assertJsonPath(
                'data.conversation.title',
                'What is ContextIQ?'
            );

        $this->assertCount(
            4,
            $conversation->messages()->get()
        );

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $question,
        ]);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $answer,
        ]);
    }
    public function test_conversation_history_is_isolated(): void
{
    $user = \App\Models\User::factory()->create();

    $kb = $user->knowledgeBases()->create([
        'name' => 'ContextIQ Test',
    ]);

    $firstConversation = $kb->conversations()->create([
        'user_id' => $user->id,
        'title' => 'First Conversation',
    ]);

    $secondConversation = $kb->conversations()->create([
        'user_id' => $user->id,
        'title' => 'Second Conversation',
    ]);

    $firstConversation->messages()->create([
        'role' => 'user',
        'content' => 'This belongs to the first conversation.',
    ]);

    $secondConversation->messages()->create([
        'role' => 'user',
        'content' => 'This belongs to the second conversation.',
    ]);

    $expectedHistory = [
        [
            'role' => 'user',
            'content' => 'This belongs to the second conversation.',
        ],
    ];

    $this->mock(
        \App\Services\RagAnswerService::class,
        function ($mock) use ($expectedHistory) {
            $mock->shouldReceive('answer')
                ->once()
                ->withArgs(function (
                    $user,
                    $knowledgeBaseId,
                    $question,
                    $history
                ) use ($expectedHistory) {
                    return $history === $expectedHistory;
                })
                ->andReturn([
                    'answer' => 'This is a test answer.',
                    'sources' => [],
                ]);
        }
    );

    $response = $this->actingAs($user)->postJson(
        "/knowledge-bases/{$kb->id}/conversations/{$secondConversation->id}/messages",
        [
            'content' => 'Tell me more.',
        ]
    );

    $response->assertCreated();

    $this->assertCount(
        1,
        $firstConversation->messages()->get()
    );

    $this->assertCount(
        3,
        $secondConversation->messages()->get()
    );
}
}
