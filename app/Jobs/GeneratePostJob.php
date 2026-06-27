<?php

namespace App\Jobs;

use App\Models\GeneratedPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeneratePostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry up to 3 times on failure, with exponential backoff.
     */
    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(private readonly GeneratedPost $post)
    {
    }

    public function handle(): void
    {
        $post = $this->post;
        $blueprint = $post->blueprint;
        $rawContent = $post->rawContent;

        // Mark raw content as processing
        if ($rawContent) {
            $rawContent->update(['status' => 'processing']);
        }

        // ── Build the system prompt from Blueprint rules ───────────────────
        $systemPrompt = <<<PROMPT
You are a content transformation engine for X (Twitter).
Your task is to take raw developer notes and transform them into an optimized X post.

Campaign rules you MUST follow:
- Target audience: {$blueprint->target_audience}
- Tone: {$blueprint->tone}
- Maximum characters: {$blueprint->max_characters}
- Maximum hashtags: {$blueprint->max_hashtags}
- Forbidden words: {$this->formatForbiddenWords($blueprint->forbidden_words)}
- Additional style notes: {$blueprint->style_notes}

You MUST respond ONLY with a valid JSON object matching this exact schema. No explanation, no markdown, no extra text:
{
  "hook_proposed": "string — a punchy opening hook, max {$blueprint->max_characters} characters",
  "body_points": ["array of strings — the key supporting points"],
  "technical_readability_score": "integer 0-100 — how readable it is for the tech audience",
  "suggested_hashtags": ["array of hashtag strings, max {$blueprint->max_hashtags} items"],
  "tone_compliance_justification": "string — brief explanation of how the tone was applied"
}
PROMPT;

        // ── Call the AI provider API ───────────────────────────────────────
        $provider = $this->getProviderConfig();
        $rawBody = $post->rawContent?->body ?? '';

        $response = Http::withToken(config('services.grok.api_key'))
            ->timeout(60)
            ->post($provider['url'], [
                'model' => $provider['model'],
                'temperature' => 0.7,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Transform this raw content:\n\n{$rawBody}"],
                ],
                // Enforce JSON output at the API level
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            Log::error('Grok API call failed', [
                'post_id' => $post->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            // Let the job fail so the retry mechanism picks it up
            $this->fail(new \RuntimeException('Grok API returned ' . $response->status()));
            return;
        }

        // ── Parse and validate the structured output ───────────────────────
        $raw = $response->json('choices.0.message.content');
        $data = json_decode($raw, true);

        if (!$this->isValidStructuredOutput($data)) {
            Log::error('Invalid structured output from Grok', [
                'post_id' => $post->id,
                'raw' => $raw,
            ]);
            $this->fail(new \RuntimeException('Grok returned an invalid JSON schema.'));
            return;
        }

        // ── Persist the result ─────────────────────────────────────────────
        $post->update([
            'hook_proposed' => $data['hook_proposed'],
            'body_points' => $data['body_points'],
            'technical_readability_score' => (int) $data['technical_readability_score'],
            'suggested_hashtags' => $data['suggested_hashtags'],
            'tone_compliance_justification' => $data['tone_compliance_justification'],
            'status' => 'draft',
        ]);

        $rawContent?->update(['status' => 'processed']);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GeneratePostJob permanently failed', [
            'post_id' => $this->post->id,
            'exception' => $exception->getMessage(),
        ]);

        // Mark the post as archived and raw content as failed
        $this->post->update(['status' => 'archived']);
        $this->post->rawContent?->update(['status' => 'failed']);
    }

    public function getProviderConfig(): array
    {
        $baseUrl = rtrim(config('services.grok.base_url', 'https://api.groq.com/openai/v1'), '/');

        return [
            'url' => $baseUrl . '/chat/completions',
            'model' => config('services.grok.model', 'llama-3.1-8b-instant'),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function formatForbiddenWords(?array $words): string
    {
        if (empty($words)) {
            return 'none';
        }
        return implode(', ', $words);
    }

    private function isValidStructuredOutput(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        $required = [
            'hook_proposed',
            'body_points',
            'technical_readability_score',
            'suggested_hashtags',
            'tone_compliance_justification',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }

        return is_array($data['body_points'])
            && is_array($data['suggested_hashtags'])
            && is_numeric($data['technical_readability_score']);
    }
}
