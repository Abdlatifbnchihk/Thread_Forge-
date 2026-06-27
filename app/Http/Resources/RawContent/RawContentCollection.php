<?php

namespace App\Http\Resources\RawContent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class RawContentCollection extends ResourceCollection
{
    public $collects = RawContentResource::class;

    public function toArray(Request $request): array
    {
        return $this->collection->toArray();
    }

    public function with(Request $request): array
    {
        return [
            'meta' => [
                'current_page' => $this->currentPage(),
                'last_page'    => $this->lastPage(),
                'per_page'     => $this->perPage(),
                'total'        => $this->total(),
            ],
        ];
    }
}
