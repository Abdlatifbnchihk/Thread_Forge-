<?php

namespace App\Policies;

use App\Models\CampaignBlueprint;
use App\Models\User;

class CampaignBlueprintPolicy
{
    // Can the user see this blueprint?
    public function view(User $user, CampaignBlueprint $blueprint): bool
    {
        return $user->id === $blueprint->user_id;
    }

    // Can the user update this blueprint?
    public function update(User $user, CampaignBlueprint $blueprint): bool
    {
        return $user->id === $blueprint->user_id;
    }

    // Can the user create a generated post for this blueprint?
    public function store(User $user, CampaignBlueprint $blueprint): bool
    {
        return $user->id === $blueprint->user_id;
    }

    // Can the user delete this blueprint?
    public function delete(User $user, CampaignBlueprint $blueprint): bool
    {
        return $user->id === $blueprint->user_id;
    }
}