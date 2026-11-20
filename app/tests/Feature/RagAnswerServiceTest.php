<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\KnowledgeBase;
use App\Services\RagAnswerService;
use App\Services\KnowledgeBaseSearchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagAnswerServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private KnowledgeBase $kb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->kb = $this->user->knowledgeBases()->create([
            'name' => 'ContextIQ Test',
        ]);

        config([
            'services.gemini.key' => 'fake-test-key',
            'services.gemini.generation_model' => 'test-model',
        ]);

        Http::preventStrayRequests();
    }

    private function geminiResponse(string $text): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function mockSearch(string $expectedQuestion): void
    {
        $document = new Document();

        $document->forceFill([
            'id' => 1,
            'original_name' => 'contextiq.txt',
        ]);

        $chunk = new DocumentChunk();

        $chunk->forceFill([
            'id' => 1,
            'document_id' => 1,
            'chunk_index' => 0,
            'content' => 'ContextIQ is a personal AI knowledge assistant. It uses Laravel, Vue, PostgreSQL and Gemini.',
            'neighbor_distance' => 0.21,
        ]);

        $chunk->setRelation('document', $document);

        $this->mock(
            KnowledgeBaseSearchService::class,
            function ($mock) use ($expectedQuestion, $chunk) {
                $mock->shouldReceive('search')
                    ->once()
                    ->with(
                        $this->user,
                        $this->kb->id,
                        $expectedQuestion,
                        5
                    )
                    ->andReturn(new Collection([$chunk]));
            }
        );
    }

    private function recordedPrompt(int $index): array
    {
        [$request] = Http::recorded()->get($index);

        return json_decode(
            $request->data()['contents'][0]['parts'][0]['text'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function test_first_question_skips_question_rewriting(): void
    {
        $question = 'What is ContextIQ?';

        $this->mockSearch($question);

        Http::fakeSequence()
            ->push($this->geminiResponse(
                'ContextIQ is a personal AI knowledge assistant. [1]'
            ));

        $result = app(RagAnswerService::class)->answer(
            $this->user,
            $this->kb->id,
            $question
        );

        $this->assertSame(
            'ContextIQ is a personal AI knowledge assistant. [1]',
            $result['answer']
        );

        $this->assertCount(1, $result['sources']);

        $this->assertSame(
            'contextiq.txt',
            $result['sources'][0]['document']
        );

        Http::assertSentCount(1);
    }

    public function test_follow_up_question_is_rewritten(): void
    {
        $question = 'What technologies does it use?';

        $rewrittenQuestion = 'What technologies does ContextIQ use?';

        $history = [
            [
                'role' => 'user',
                'content' => 'What is ContextIQ?',
            ],
            [
                'role' => 'assistant',
                'content' => 'ContextIQ is a personal AI knowledge assistant.',
            ],
        ];

        $this->mockSearch($rewrittenQuestion);

        Http::fakeSequence()
            ->push($this->geminiResponse($rewrittenQuestion))
            ->push($this->geminiResponse(
                'ContextIQ uses Laravel, Vue, PostgreSQL and Gemini. [1]'
            ));

        $result = app(RagAnswerService::class)->answer(
            $this->user,
            $this->kb->id,
            $question,
            $history
        );

        $this->assertSame(
            'ContextIQ uses Laravel, Vue, PostgreSQL and Gemini. [1]',
            $result['answer']
        );

        $rewritePrompt = $this->recordedPrompt(0);
        $answerPrompt = $this->recordedPrompt(1);

        $this->assertSame(
            $question,
            $rewritePrompt['latest_question']
        );

        $this->assertSame(
            $history,
            $rewritePrompt['conversation_history']
        );

        $this->assertSame(
            $question,
            $answerPrompt['original_question']
        );

        $this->assertSame(
            $rewrittenQuestion,
            $answerPrompt['rewritten_question']
        );

        $this->assertSame(
            $history,
            $answerPrompt['conversation_history']
        );

        Http::assertSentCount(2);
    }

    public function test_question_rewriting_failure_uses_original_question(): void
    {
        $question = 'What technologies does it use?';

        $history = [
            [
                'role' => 'user',
                'content' => 'What is ContextIQ?',
            ],
            [
                'role' => 'assistant',
                'content' => 'ContextIQ is an AI knowledge assistant.',
            ],
        ];

        $this->mockSearch($question);

        Http::fakeSequence()
            ->push([
                'error' => [
                    'message' => 'Service unavailable',
                ],
            ], 503)
            ->push($this->geminiResponse(
                'ContextIQ uses Laravel and Vue. [1]'
            ));

        $result = app(RagAnswerService::class)->answer(
            $this->user,
            $this->kb->id,
            $question,
            $history
        );

        $this->assertSame(
            'ContextIQ uses Laravel and Vue. [1]',
            $result['answer']
        );

        $answerPrompt = $this->recordedPrompt(1);

        $this->assertSame(
            $question,
            $answerPrompt['rewritten_question']
        );

        Http::assertSentCount(2);
    }

    public function test_conversation_history_is_limited(): void
    {
        $question = 'Tell me more about ContextIQ.';

        $history = [];

        for ($i = 1; $i <= 10; $i++) {
            $history[] = [
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'content' => "Message {$i}",
            ];
        }

        // Test maximum individual message length.
        $history[9]['content'] = str_repeat('x', 2000);

        $this->mockSearch($question);

        Http::fakeSequence()
            ->push($this->geminiResponse($question))
            ->push($this->geminiResponse(
                'ContextIQ is an AI knowledge assistant. [1]'
            ));

        app(RagAnswerService::class)->answer(
            $this->user,
            $this->kb->id,
            $question,
            $history
        );

        $rewritePrompt = $this->recordedPrompt(0);
        $answerPrompt = $this->recordedPrompt(1);

        $sentHistory = $rewritePrompt['conversation_history'];

        $this->assertCount(8, $sentHistory);

        $this->assertSame(
            'Message 3',
            $sentHistory[0]['content']
        );

        $this->assertSame(
            1500,
            mb_strlen($sentHistory[7]['content'])
        );

        $this->assertSame(
            $sentHistory,
            $answerPrompt['conversation_history']
        );

        Http::assertSentCount(2);
    }
}
