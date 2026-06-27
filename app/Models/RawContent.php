<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\User;
use App\Models\CampaignBlueprint;
use App\Models\GeneratedPost;

class RawContent extends Model
{
    protected $fillable = [
        'user_id',
        'campaign_blueprint_id',
        'body',
        'source_type',
        'title',
        'status',
        'word_count',
    ];

    protected function casts(): array
    {
        return [
            'word_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RawContent $rawContent) {
            $rawContent->word_count = str_word_count(strip_tags($rawContent->body ?? ''));
        });
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaignBlueprint(): BelongsTo
    {
        return $this->belongsTo(CampaignBlueprint::class, 'campaign_blueprint_id');
    }

    public function generatedPost(): HasOne
    {
        return $this->hasOne(GeneratedPost::class, 'raw_content_id');
    }

    // Helpers

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
