<?php

namespace App\Policies;

use App\Models\GeneratedPost;
use App\Models\User;

class GeneratedPostPolicy
{
    public function view(User $user, GeneratedPost $post): bool
    {
        return $user->id === $post->blueprint->user_id;
    }

    public function show(User $user, GeneratedPost $post): bool
    {
        return $user->id === $post->blueprint->user_id;
    }

    public function updateStatus(User $user, GeneratedPost $post): bool
    {
        return $user->id === $post->blueprint->user_id;
    }

    public function chat(User $user, GeneratedPost $post): bool
    {
        return $user->id === $post->blueprint->user_id;
    }
}
