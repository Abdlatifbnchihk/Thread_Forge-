<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\SendChatMessageRequest;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\GeneratedPost;
use App\Services\GhostwriterService;
use Illuminate\Http\JsonResponse;

class GhostwriterController extends Controller
{
    public function __construct(
        private readonly GhostwriterService $ghostwriter
    ) {}

    public function history(GeneratedPost $post): JsonResponse
    {
        $this->authorize('chat', $post);

        $messages = ChatMessage::where('generated_post_id', $post->id)
            ->orderBy('created_at')
            ->get();

        return response()->json(
            ChatMessageResource::collection($messages)
        );
    }

    public function send(SendChatMessageRequest $request, GeneratedPost $post): JsonResponse
    {
        $this->authorize('chat', $post);

        if (!$post->isProcessed()) {
            return response()->json([
                'message' => 'Cannot chat with a post that is still being processed.',
            ], 422);
        }

        $reply = $this->ghostwriter->chat($post, $request->validated('message'));

        $assistantMessage = ChatMessage::where('generated_post_id', $post->id)
            ->where('role', 'assistant')
            ->latest()
            ->first();

        return response()->json([
            'message' => 'Reply generated.',
            'data' => new ChatMessageResource($assistantMessage),
        ]);
    }
}
