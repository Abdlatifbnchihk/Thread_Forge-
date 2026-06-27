<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostStatusLog extends Model
{
    protected $fillable = [
        'generated_post_id',
        'from_status',
        'to_status',
    ];

    public function generatedPost(): BelongsTo
    {
        return $this->belongsTo(GeneratedPost::class);
    }
}
