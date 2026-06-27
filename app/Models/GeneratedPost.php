<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ChatMessage;
use App\Models\PostStatusLog;
use App\Models\RawContent;

class GeneratedPost extends Model
{
    protected $fillable = [
        'campaign_blueprint_id',
        'raw_content_id',
        'hook_proposed',
        'body_points',
        'technical_readability_score',
        'suggested_hashtags',
        'tone_compliance_justification',
        'status',
    ];

    public function casts(): array
    {
        return [
            'body_points' => 'array',
            'suggested_hashtags' => 'array',
            'technical_readability_score' => 'integer',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(CampaignBlueprint::class, 'campaign_blueprint_id');
    }

    public function rawContent(): BelongsTo
    {
        return $this->belongsTo(RawContent::class, 'raw_content_id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(PostStatusLog::class);
    }

    public function isProcessed(): bool
    {
        return $this->status !== 'pending';
    }
}
