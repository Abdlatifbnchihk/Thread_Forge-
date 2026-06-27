<?php

namespace App\Http\Controllers;

use App\Http\Requests\RawContent\StoreRawContentRequest;
use App\Http\Requests\RawContent\UpdateRawContentRequest;
use App\Http\Resources\RawContent\RawContentCollection;
use App\Http\Resources\RawContent\RawContentResource;
use App\Jobs\GeneratePostJob;
use App\Models\CampaignBlueprint;
use App\Models\RawContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RawContentController extends Controller
{
    public function index(Request $request, CampaignBlueprint $blueprint): JsonResponse
    {
        $this->authorize('view', $blueprint);

        $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'processing', 'processed', 'failed'])],
        ]);

        $rawContents = RawContent::where('campaign_blueprint_id', $blueprint->id)
            ->where('user_id', $request->user()->id)
            ->with('generatedPost:id,status')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json(new RawContentCollection($rawContents));
    }

    public function store(StoreRawContentRequest $request, CampaignBlueprint $blueprint): JsonResponse
    {
        $this->authorize('store', $blueprint);

        $rawContent = RawContent::create([
            'user_id'               => $request->user()->id,
            'campaign_blueprint_id' => $blueprint->id,
            'body'                  => $request->validated('body'),
            'source_type'           => $request->validated('source_type', 'manual'),
            'title'                 => $request->validated('title'),
            'status'                => 'pending',
        ]);

        $post = $blueprint->generatedPosts()->create([
            'raw_content_id' => $rawContent->id,
            'status'         => 'pending',
        ]);

        GeneratePostJob::dispatch($post);

        return response()->json([
            'message' => 'Content submitted. Generation is in progress.',
            'data'    => new RawContentResource($rawContent->load('generatedPost')),
        ], 202);
    }

    public function show(Request $request, RawContent $rawContent): JsonResponse
    {
        $this->authorize('view', $rawContent);

        $rawContent->load('generatedPost', 'campaignBlueprint:id,name');

        return response()->json(new RawContentResource($rawContent));
    }

    public function update(UpdateRawContentRequest $request, RawContent $rawContent): JsonResponse
    {
        $this->authorize('update', $rawContent);

        if (!$rawContent->isPending()) {
            return response()->json([
                'message' => 'Cannot edit content that is already being processed.',
            ], 422);
        }

        $rawContent->update($request->validated());

        return response()->json([
            'message' => 'Raw content updated.',
            'data'    => new RawContentResource($rawContent->fresh()),
        ]);
    }

    public function destroy(Request $request, RawContent $rawContent): JsonResponse
    {
        $this->authorize('delete', $rawContent);

        $rawContent->delete();

        return response()->json(['message' => 'Raw content deleted.']);
    }
}
