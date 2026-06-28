<?php

namespace App\Services;

use App\Models\CampaignBlueprint;
use App\Models\ChatMessage;
use App\Models\GeneratedPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GhostwriterService
{
    private const MAX_ITERATIONS = 5;

    private const TOOLS = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'getCampaignRules',
                'description' => 'Retrieve the full style configuration for a campaign blueprint — tone, character limits, hashtags, forbidden words, and style notes.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'campaign_id' => [
                            'type' => 'integer',
                            'description' => 'The ID of the campaign blueprint to look up',
                        ],
                    ],
                    'required' => ['campaign_id'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'getPostHistory',
                'description' => 'Retrieve the current generated post data including hook, body points, hashtags, and readability score.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => [
                            'type' => 'integer',
                            'description' => 'The ID of the generated post to look up',
                        ],
                    ],
                    'required' => ['post_id'],
                ],
            ],
        ],
    ];

    public function chat(GeneratedPost $post, string $userMessage): string
    {
        // Persist the user message
        ChatMessage::create([
            'generated_post_id' => $post->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        // Build conversation history from DB
        $messages = $this->buildMessageHistory($post);

        // Run the agentic loop
        return $this->runAgentLoop($post, $messages);
    }

    public function buildMessageHistory(GeneratedPost $post): array
    {
        $systemPrompt = $this->buildSystemPrompt($post);

        $history = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Load all chat messages in chronological order
        $chatMessages = ChatMessage::where('generated_post_id', $post->id)
            ->orderBy('created_at')
            ->get();

        foreach ($chatMessages as $msg) {
            if ($msg->role === 'tool') {
                // Tool results are not directly in the messages array
                // They're matched by tool_call_id in the API call
                continue;
            }

            $entry = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];

            $history[] = $entry;
        }

        return $history;
    }

    public function buildSystemPrompt(GeneratedPost $post): string
    {
        $blueprint = $post->blueprint;

        return <<<PROMPT
You are the Ghostwriter Agent for ThreadForge — an expert X (Twitter) content strategist.

You are helping the user refine a generated post. You have access to the campaign rules and the current post data via your tools.

Campaign: {$blueprint->name}
Post ID: {$post->id}

When the user asks about campaign rules, use the getCampaignRules tool.
When the user asks about the current post or wants to improve it, use the getPostHistory tool.

Be concise, actionable, and focused on X (Twitter) best practices.
PROMPT;
    }

    private function runAgentLoop(GeneratedPost $post, array $messages): string
    {
        $provider = $this->getProviderConfig();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = Http::withToken(config('services.grok.api_key'))
                ->timeout(60)
                ->post($provider['url'], [
                    'model' => $provider['model'],
                    'temperature' => 0.7,
                    'messages' => $messages,
                    'tools' => self::TOOLS,
                    'tool_choice' => 'auto',
                ]);

            if ($response->failed()) {
                Log::error('Ghostwriter API call failed', [
                    'post_id' => $post->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('Ghostwriter API returned ' . $response->status());
            }

            $choice = $response->json('choices.0');
            $message = $choice['message'];
            $finishReason = $choice['finish_reason'];

            // If the model wants to call tools
            if ($finishReason === 'tool_calls' && !empty($message['tool_calls'])) {
                // Add the assistant message with tool_calls to history
                $messages[] = $message;

                // Execute each tool call
                foreach ($message['tool_calls'] as $toolCall) {
                    $toolName = $toolCall['function']['name'];
                    $toolId = $toolCall['id'];
                    $arguments = json_decode($toolCall['function']['arguments'], true);

                    $result = $this->executeTool($toolName, $arguments);

                    // Persist tool result to DB
                    ChatMessage::create([
                        'generated_post_id' => $post->id,
                        'role' => 'tool',
                        'content' => json_encode($result),
                        'tool_name' => $toolName,
                    ]);

                    // Add tool result to conversation history
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolId,
                        'content' => json_encode($result),
                    ];
                }

                // Loop again with updated history
                continue;
            }

            // If the model returned a final text response
            $content = $message['content'] ?? '';

            // Persist the assistant reply
            ChatMessage::create([
                'generated_post_id' => $post->id,
                'role' => 'assistant',
                'content' => $content,
            ]);

            return $content;
        }

        // Safety cap reached — return a graceful message
        $fallback = 'I apologize, but I reached the maximum number of tool calls for this conversation. Please try rephrasing your question or start a new conversation.';

        ChatMessage::create([
            'generated_post_id' => $post->id,
            'role' => 'assistant',
            'content' => $fallback,
        ]);

        return $fallback;
    }

    private function executeTool(string $name, array $args): array
    {
        return match ($name) {
            'getCampaignRules' => $this->getCampaignRules($args['campaign_id'] ?? 0),
            'getPostHistory' => $this->getPostHistory($args['post_id'] ?? 0),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function getCampaignRules(int $campaignId): array
    {
        $blueprint = CampaignBlueprint::find($campaignId);

        if (!$blueprint) {
            return ['error' => "Campaign blueprint #{$campaignId} not found."];
        }

        return [
            'name' => $blueprint->name,
            'target_audience' => $blueprint->target_audience,
            'tone' => $blueprint->tone,
            'max_characters' => $blueprint->max_characters,
            'max_hashtags' => $blueprint->max_hashtags,
            'forbidden_words' => $blueprint->forbidden_words ?? [],
            'style_notes' => $blueprint->style_notes,
        ];
    }

    private function getPostHistory(int $postId): array
    {
        $post = GeneratedPost::with('blueprint')->find($postId);

        if (!$post) {
            return ['error' => "Generated post #{$postId} not found."];
        }

        return [
            'id' => $post->id,
            'status' => $post->status,
            'hook_proposed' => $post->hook_proposed,
            'body_points' => $post->body_points ?? [],
            'technical_readability_score' => $post->technical_readability_score,
            'suggested_hashtags' => $post->suggested_hashtags ?? [],
            'tone_compliance_justification' => $post->tone_compliance_justification,
            'campaign' => [
                'name' => $post->blueprint->name,
                'tone' => $post->blueprint->tone,
                'max_characters' => $post->blueprint->max_characters,
            ],
        ];
    }

    private function getProviderConfig(): array
    {
        $baseUrl = rtrim(config('services.grok.base_url', 'https://api.groq.com/openai/v1'), '/');

        return [
            'url' => $baseUrl . '/chat/completions',
            'model' => config('services.grok.model', 'llama-3.1-8b-instant'),
        ];
    }
}
