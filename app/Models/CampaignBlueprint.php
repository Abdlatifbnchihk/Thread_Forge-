<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\GeneratedPost;

class CampaignBlueprint extends Model
{
    //
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'target_audience',
        'tone',
        'max_characters',
        'max_hashtags',
        'forbidden_words',
        'style_notes',
    ];

    protected function casts(): array {
        return [
            'forbidden_words' => 'array',
            'max_characters'  => 'integer',
            'max_hashtags'    => 'integer',
        ];
    }

    // Relationships

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function generatedPosts(){
        return $this->hasMany(GeneratedPost::class);
    }

}
