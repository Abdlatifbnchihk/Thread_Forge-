<?php

namespace App\Policies;

use App\Models\RawContent;
use App\Models\User;

class RawContentPolicy
{
    public function view(User $user, RawContent $rawContent): bool
    {
        return $user->id === $rawContent->user_id;
    }

    public function update(User $user, RawContent $rawContent): bool
    {
        return $user->id === $rawContent->user_id;
    }

    public function delete(User $user, RawContent $rawContent): bool
    {
        return $user->id === $rawContent->user_id;
    }
}
