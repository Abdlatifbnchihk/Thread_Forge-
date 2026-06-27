<?php

namespace App\Http\Resources\RawContent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RawContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'title'                 => $this->title,
            'body'                  => $this->body,
            'source_type'           => $this->source_type,
            'word_count'            => $this->word_count,
            'status'                => $this->status,

            'blueprint' => $this->whenLoaded('campaignBlueprint', fn () => [
                'id'   => $this->campaignBlueprint->id,
                'name' => $this->campaignBlueprint->name,
            ]),

            'generated_post' => $this->whenLoaded('generatedPost', fn () => [
                'id'     => $this->generatedPost->id,
                'status' => $this->generatedPost->status,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
