<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\StoreConversationMessageRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\KnowledgeBase;
use App\Services\RagAnswerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class ConversationController extends Controller
{
    public function index(
        Request $request,
        int $knowledgeBase
    ): JsonResponse {
        $kb = $this->findKnowledgeBase(
            $request,
            $knowledgeBase
        );

        $conversations = $kb->conversations()
            ->where('user_id', $request->user()->id)
            ->orderByRaw(
                'COALESCE(last_message_at, created_at) DESC'
            )
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($conversations);
    }

    public function store(
        StoreConversationRequest $request,
        int $knowledgeBase
    ): JsonResponse {
        $kb = $this->findKnowledgeBase(
            $request,
            $knowledgeBase
        );

        $conversation = $kb->conversations()->create([
            'user_id' => $request->user()->id,
            'title' => $request->validated('title') ?? 'New Chat',
        ]);

        return response()->json([
            'message' => 'Conversation created successfully.',
            'data' => $conversation,
        ], 201);
    }

    public function show(
        Request $request,
        int $knowledgeBase,
        int $conversation
    ): JsonResponse {
        $kb = $this->findKnowledgeBase(
            $request,
            $knowledgeBase
        );

        $chat = $this->findConversation(
            $request,
            $kb,
            $conversation
        );

        $chat->load([
            'messages' => fn($query) => $query
                ->orderBy('id'),
        ]);

        return response()->json([
            'data' => $chat,
        ]);
    }

    public function storeMessage(
        StoreConversationMessageRequest $request,
        int $knowledgeBase,
        int $conversation,
        RagAnswerService $ragAnswerService
    ): JsonResponse {
        $kb = $this->findKnowledgeBase(
            $request,
            $knowledgeBase
        );

        $chat = $this->findConversation(
            $request,
            $kb,
            $conversation
        );

        $user = $request->user();

        $content = trim(
            $request->validated('content')
        );

        // Retrieve recent conversation history.
        $history = $chat->messages()
            ->whereIn('role', [
                ConversationMessage::ROLE_USER,
                ConversationMessage::ROLE_ASSISTANT,
            ])
            ->orderByDesc('id')
            ->limit(8)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();

        try {
            $result = $ragAnswerService->answer(
                $user,
                $kb->id,
                $content,
                $history
            );
        } catch (Exception $exception) {
            Log::warning('RAG answer generation failed.', [
                'conversation_id' => $chat->id,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'Unable to generate a response. Please try again.',
            ], 502);
        }

        $data = DB::transaction(
            function () use ($kb, $chat, $user, $content, $result) {
                $chat = $kb->conversations()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->findOrFail($chat->id);

                $isFirstMessage = !$chat->messages()->exists();

                $userMessage = $chat->messages()->create([
                    'role' => ConversationMessage::ROLE_USER,
                    'content' => $content,
                    'sources' => null,
                ]);

                $assistantMessage = $chat->messages()->create([
                    'role' => ConversationMessage::ROLE_ASSISTANT,
                    'content' => $result['answer'],
                    'sources' => $result['sources'] ?? [],
                ]);

                if (
                    $isFirstMessage &&
                    (blank($chat->title) || $chat->title === 'New Chat')
                ) {
                    $chat->title = Str::limit($content, 80);
                }

                $chat->last_message_at = now();

                $chat->save();

                return [
                    'conversation' => $chat,
                    'user_message' => $userMessage,
                    'assistant_message' => $assistantMessage,
                ];
            }
        );

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => $data,
        ], 201);
    }

    public function destroy(
        Request $request,
        int $knowledgeBase,
        int $conversation
    ): Response {
        $kb = $this->findKnowledgeBase(
            $request,
            $knowledgeBase
        );

        $chat = $this->findConversation(
            $request,
            $kb,
            $conversation
        );

        $chat->delete();

        return response()->noContent();
    }

    private function findKnowledgeBase(
        Request $request,
        int $knowledgeBase
    ): KnowledgeBase {
        return $request->user()
            ->knowledgeBases()
            ->findOrFail($knowledgeBase);
    }

    private function findConversation(
        Request $request,
        KnowledgeBase $knowledgeBase,
        int $conversation
    ): Conversation {
        return $knowledgeBase->conversations()
            ->where('user_id', $request->user()->id)
            ->findOrFail($conversation);
    }
}
