<?php

namespace App\Providers;

use App\Models\CampaignBlueprint;
use App\Models\GeneratedPost;
use App\Models\ChatMessage;
use App\Models\RawContent;
use App\Policies\CampaignBlueprintPolicy;
use App\Policies\GeneratedPostPolicy;
use App\Policies\ChatMessagePolicy;
use App\Policies\RawContentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        CampaignBlueprint::class => CampaignBlueprintPolicy::class,
        RawContent::class        => RawContentPolicy::class,
        GeneratedPost::class     => GeneratedPostPolicy::class,
        ChatMessage::class       => ChatMessagePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
