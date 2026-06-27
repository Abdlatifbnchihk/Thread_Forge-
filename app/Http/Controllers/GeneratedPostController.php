<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\UpdatePostStatusRequest;
use App\Http\Resources\Post\PostCollection;
use App\Http\Resources\Post\PostResource;
use App\Models\GeneratedPost;
use App\Models\PostStatusLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GeneratedPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'draft', 'posted', 'archived'])]
        ]);

        $posts = GeneratedPost::whereHas('blueprint', fn($q) => $q->where('user_id', $request->user()->id))
            ->with('blueprint:id,name')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json(new PostCollection($posts));
    }

    public function show(Request $request, GeneratedPost $post): JsonResponse
    {
        $this->authorize('show', $post);

        $post->load('blueprint:id,name', 'rawContent');

        return response()->json(new PostResource($post));
    }

    public function updateStatus(UpdatePostStatusRequest $request, GeneratedPost $post): JsonResponse
    {
        $this->authorize('updateStatus', $post);

        $fromStatus = $post->status;

        $post->update(['status' => $request->validated('status')]);

        PostStatusLog::create([
            'generated_post_id' => $post->id,
            'from_status' => $fromStatus,
            'to_status' => $post->status,
        ]);

        return response()->json([
            'message' => 'Status updated.',
            'data' => new PostResource($post->fresh()),
        ]);
    }
}
