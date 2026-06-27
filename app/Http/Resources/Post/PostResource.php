<?php

namespace App\Http\Resources\Post;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'status'        => $this->status,

            // AI-generated structured output fields
            'hook_proposed'                  => $this->hook_proposed,
            'body_points'                    => $this->body_points ?? [],
            'technical_readability_score'    => $this->technical_readability_score,
            'suggested_hashtags'             => $this->suggested_hashtags ?? [],
            'tone_compliance_justification'  => $this->tone_compliance_justification,

            // Relationship summary
            'blueprint' => $this->whenLoaded('blueprint', fn () => [
                'id'   => $this->blueprint->id,
                'name' => $this->blueprint->name,
            ]),

            'raw_content' => $this->whenLoaded('rawContent', fn () => [
                'id'         => $this->rawContent->id,
                'body'       => $this->rawContent->body,
                'title'      => $this->rawContent->title,
                'word_count' => $this->rawContent->word_count,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
