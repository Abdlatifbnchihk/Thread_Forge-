<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlueprintRequest;
use App\Http\Resources\Blueprint\BlueprintCollection;
use App\Http\Resources\Blueprint\BlueprintResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\CampaignBlueprint;

class CampaignBlueprintController extends Controller
{
    //
    public function index(Request $request):  JsonResponse {
        $blueprints = $request->user()
            ->campaignBlueprints()
            ->withCount('generatedPosts')
            ->latest()
            ->get();
        
        return response()->json(new BlueprintCollection($blueprints));
    }

    public function store(StoreBlueprintRequest $request): JsonResponse{
        $blueprint = $request->user()
            ->campaignBlueprints()
            ->Create($request->validated());
        
        return response()->json([
            'message' => 'Blueprint created.',
            'data'    => new BlueprintResource($blueprint),
        ], 201);
    }

    public function show(Request $request, CampaignBlueprint $blueprint){
        $this->authorize('view', $blueprint);

        $blueprint->loadCount('generatedPosts');

        return response()->json(new BlueprintResource($blueprint));
    }

    public function update(StoreBlueprintRequest $request, CampaignBlueprint $blueprint) {
        $this->authorize('update', $blueprint);

        $blueprint->update($request->validated());

        return response()->json([
            'message' => 'Blueprint updated.',
            'data'    => new BlueprintResource($blueprint->fresh()),
        ]);
    }

    public function destroy(Request $request, CampaignBlueprint $blueprint){
        $this->authorize('delete', $blueprint);

        $blueprint->delete();

        return response()->json(['message' => 'Blueprint deleted.']);
    }
}
