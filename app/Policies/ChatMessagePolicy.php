<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

class ChatMessagePolicy
{
    public function view(User $user, ChatMessage $message): bool
    {
        return $user->id === $message->generatedPost->blueprint->user_id;
    }
}
