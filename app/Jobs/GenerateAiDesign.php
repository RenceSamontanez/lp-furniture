<?php

namespace App\Jobs;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateAiDesign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    // Once either flips true, that provider is skipped for every
    // remaining variation in THIS run — set when a provider reports a
    // structural "not available" condition (Gemini's 429/limit:0, a
    // missing Pollinations key, or a Pollinations 429) rather than a
    // one-off failure worth retrying on the next variation.
    private bool $geminiUnavailable = false;
    private bool $pollinationsUnavailable = false;

    private const MAX_VARIATIONS = 4;
    private const TIME_BUDGET_SECONDS = 180;

    // A protected method rather than inlining the constant so a test
    // can subclass this job and return a short budget, to verify the
    // cutoff logic itself without actually waiting out the real
    // 3-minute budget.
    protected function timeBudgetSeconds(): int
    {
        return self::TIME_BUDGET_SECONDS;
    }

    public function __construct(
        public readonly int $requestId
    ) {}

    public function handle(): void
    {
        $request = CustomDesignRequest::findOrFail($this->requestId);

        // The lazy staleness check (CustomDesignController) or
        // design:fail-stale can mark this request 'rejected' while
        // this job is still running (it's allowed to keep running —
        // tries/timeout handle that independently). If that happened,
        // this job's result is stale by definition: don't flip the
        // status back and don't create suggestions for a request the
        // customer has already been told failed.
        if ($request->status === 'rejected') {
            Log::info("Request {$this->requestId} was already marked rejected before this job ran — discarding.");
            return;
        }

        $request->update(['status' => 'pending']);

        $prompt = $this->buildPrompt($request);
        $request->update(['ai_prompt' => $prompt]);

        $generated = 0;
        $startedAt = microtime(true);

        for ($i = 1; $i <= self::MAX_VARIATIONS; $i++) {

            // Re-check on every iteration, not just once at the start —
            // this loop can run long enough on its own (Gemini alone
            // throttles to one call per ~8s) that the lazy check or
            // design:fail-stale could mark this request rejected
            // mid-loop.
            if ($request->fresh()->status === 'rejected') {
                Log::info("Request {$this->requestId} was marked rejected mid-run — stopping without creating more suggestions.");
                return;
            }

            $elapsed = microtime(true) - $startedAt;
            $budget = $this->timeBudgetSeconds();
            if ($elapsed >= $budget) {
                Log::info("Request {$this->requestId}: {$budget}s time budget reached after {$generated} variation(s) — stopping with what we have rather than forcing all " . self::MAX_VARIATIONS . '.');
                break;
            }

            if ($this->pollinationsUnavailable && $this->geminiUnavailable) {
                Log::info("Request {$this->requestId}: both providers unavailable — stopping after {$generated} variation(s) instead of failing the whole request.");
                break;
            }

            // Provider order: Pollinations (authenticated) first, Gemini
            // as the last option — see PART 2 of the provider-chain fix.
            $provider = null;
            $result = null;

            if (!$this->pollinationsUnavailable) {
                $provider = 'pollinations-ai';
                $result = $this->tryPollinations($prompt, $i);
            }

            if (!$result && !$this->geminiUnavailable) {
                $provider = 'gemini';
                $result = $this->tryGemini($prompt, $i);
            }

            if ($result) {
                AiDesignSuggestion::create([
                    'design_id'      => $request->id,
                    'image_path'     => $result,
                    'ai_model_used'  => $provider,
                    'prompt_used'    => $prompt,
                    'is_selected'    => false,
                    'generated_at'   => now(),
                ]);
                $generated++;
                Log::info("Variation {$i} succeeded via {$provider} for request {$this->requestId}.");
            } else {
                Log::warning("No available provider produced an image for variation {$i}, request {$this->requestId}.");
            }

            if ($i < self::MAX_VARIATIONS) {
                sleep(1);
            }
        }

        // One last check before writing the final status — the 4th
        // variation's attempt (all 4 providers, in sequence) is the
        // single longest stretch in this loop with no check in between.
        if ($request->fresh()->status === 'rejected') {
            Log::info("Request {$this->requestId} was marked rejected just as this run finished — discarding its result.");
            return;
        }

        if ($generated > 0) {
            $request->update(['status' => 'ai_generated']);

            Notification::create([
                'user_id' => $request->user_id,
                'title'   => 'Your Design Suggestions Are Ready!',
                'message' => "We've generated {$generated} design suggestion(s) for your {$request->furniture_type_noun} request. Review and select your favorite!",
                'type'    => 'design_ready',
                'url'     => route('design.show', $request->id),
                'is_read' => false,
            ]);
        } else {
            $request->update(['status' => 'rejected', 'failure_reason' => 'provider_error']);

            Notification::create([
                'user_id' => $request->user_id,
                'title'   => 'Design Generation Failed',
                'message' => 'We were unable to generate designs for your request. Please try again or contact us directly.',
                'type'    => 'general',
                'url'     => route('design.index'),
                'is_read' => false,
            ]);
        }
    }

    /* ════════════════════════════════
       PROVIDER (AUTHENTICATED): Pollinations.ai
       Tried first — gen.pollinations.ai is the authenticated
       endpoint (vs. the old unauthenticated image.pollinations.ai),
       which is both more reliable and the one that actually accepts
       POLLINATIONS_API_KEY. No key configured means no account to
       call on behalf of, so this is skipped cleanly rather than
       attempted and failed.
    ════════════════════════════════ */
    private function tryPollinations(string $prompt, int $variation): ?string
    {
        $apiKey = config('services.pollinations.key');

        if (!$apiKey) {
            Log::info("[Pollinations] no API key configured — skipping.");
            $this->pollinationsUnavailable = true;
            return null;
        }

        $seed = crc32($this->requestId . '-' . $variation . '-' . time());
        $encodedPrompt = urlencode($prompt . ", variation {$variation}, professional product photography");
        $imageUrl = "https://gen.pollinations.ai/prompt/{$encodedPrompt}"
            . "?width=1024&height=1024&seed={$seed}&nologo=true";

        Log::info("[Pollinations] attempt, variation {$variation}, request {$this->requestId}");

        try {
            $response = Http::timeout(20)->connectTimeout(5)->withToken($apiKey)->withoutVerifying()->get($imageUrl);
            Log::info("[Pollinations] response variation {$variation}: status=" . $response->status());

            if ($response->status() === 429) {
                Log::info("[Pollinations] rate limited (429) — unavailable for the rest of this run.");
                $this->pollinationsUnavailable = true;
                return null;
            }

            if ($response->successful()) {
                return $this->saveValidatedImage($response->body(), 'jpg', 'Pollinations', $variation);
            }

            Log::warning("[Pollinations] non-success status {$response->status()} for variation {$variation}.");
            return null;

        } catch (\Exception $e) {
            Log::error("[Pollinations] error variation {$variation}: " . $e->getMessage());
            return null;
        }
    }

    /* ════════════════════════════════
       PROVIDER (LAST OPTION): Google Gemini 2.5 Flash Image
       Free: 500/day, no credit card — but this project's key has
       0 free-tier allowance for this model (confirmed via a real
       429: "...free_tier_requests, limit: 0, model:
       gemini-2.5-flash-preview-image"), which is a structural
       unavailability, not a transient rate limit — detected below and
       treated as such rather than retried on later variations.
       Returns base64 image data — saved locally.
    ════════════════════════════════ */
    private function tryGemini(string $prompt, int $variation): ?string
    {
        $apiKey = config('services.gemini.key');

        if (!$apiKey) {
            Log::info("[Gemini] no API key configured — skipping.");
            $this->geminiUnavailable = true;
            return null;
        }

        // Free tier allows only ~10 requests/minute — space calls out
        // so a multi-variation run doesn't trip that on its own.
        static $lastGeminiCall = null;
        if ($lastGeminiCall !== null) {
            $elapsed = microtime(true) - $lastGeminiCall;
            $minGap = 8;
            if ($elapsed < $minGap) {
                sleep((int) ceil($minGap - $elapsed));
            }
        }
        $lastGeminiCall = microtime(true);

        try {
            Log::info("[Gemini] attempt, variation {$variation}, request {$this->requestId}");

            $response = Http::timeout(20)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=' . $apiKey, [
                    'contents' => [
                        ['parts' => [
                            ['text' => $prompt . ", variation {$variation}, professional product photography"],
                        ]],
                    ],
                    'generationConfig' => [
                        'responseModalities' => ['TEXT', 'IMAGE'],
                    ],
                ]);

            Log::info("[Gemini] response variation {$variation}: status=" . $response->status());

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'] ?? [];

                foreach ($parts as $part) {
                    if (isset($part['inlineData']['data'])) {
                        $imageBytes = base64_decode($part['inlineData']['data']);
                        $saved = $this->saveValidatedImage($imageBytes, 'png', 'Gemini', $variation);

                        if ($saved) {
                            return $saved;
                        }
                    }
                }

                return null;
            }

            if ($response->status() === 429) {
                $message = (string) $response->json('error.message', '');

                if (str_contains($message, 'limit: 0')) {
                    Log::info("[Gemini] 429 with limit: 0 — unavailable for the rest of this run, not a transient rate limit.");
                    $this->geminiUnavailable = true;
                } else {
                    Log::info("[Gemini] rate limited (429) — skipping this variation, may retry the next one.");
                }

                return null;
            }

            Log::warning("[Gemini] non-success status {$response->status()} for variation {$variation}.");
            return null;

        } catch (\Exception $e) {
            Log::error("[Gemini] error variation {$variation}: " . $e->getMessage());
            return null;
        }
    }

    /* ════════════════════════════════
       Shared by every provider — writes the downloaded/decoded bytes
       to public/images/ai-designs, then validates it's actually a
       real, non-trivial image before accepting it. On any failure the
       partial file is removed and null is returned so the caller
       falls through to the next provider, exactly like a provider
       that returned nothing at all.

       Returns a RELATIVE path ("images/ai-designs/xyz.jpg"), never an
       asset() URL — this job runs inside a queue worker, a console
       context with no incoming HTTP request, so asset() here has
       nothing to derive a host from and silently falls back to
       APP_URL. That value only happens to be right if the customer's
       browser is talking to the app on that exact same host, which
       isn't guaranteed (and wasn't true in this project's own dev
       setup). Letting AiDesignSuggestion::displayUrl() call asset()
       at render time — inside a real request — resolves the host
       correctly every time instead of baking in a stale one.
    ════════════════════════════════ */
    private function saveValidatedImage(string $bytes, string $extension, string $providerLabel, int $variation): ?string
    {
        // A real photo-style generation is tens of KB at minimum —
        // this mainly catches a provider returning a tiny error/
        // placeholder image (or near-empty body) with a 200 status.
        if (strlen($bytes) < 5000) {
            Log::warning("[{$providerLabel}] image for variation {$variation} is only " . strlen($bytes) . ' bytes — rejecting as invalid.');
            return null;
        }

        $destination = public_path('images/ai-designs');
        if (!file_exists($destination)) {
            mkdir($destination, 0755, true);
        }

        $filename = 'ai-design-' . Str::random(16) . '.' . $extension;
        $fullPath = $destination . '/' . $filename;
        file_put_contents($fullPath, $bytes);

        $info = @getimagesize($fullPath);
        if ($info === false) {
            Log::warning("[{$providerLabel}] variation {$variation}: saved file is not a decodable image — rejecting.");
            @unlink($fullPath);
            return null;
        }

        return 'images/ai-designs/' . $filename;
    }

    private function buildPrompt(CustomDesignRequest $request): string
    {
        $parts = ["Professional product photo of a custom Philippine hardwood furniture piece."];

        // The type the customer chose comes first and is a hard instruction. It is
        // never worked out from the description. Requests made before the type was
        // required keep the older wording for whatever free text they stored.
        $instruction = \App\Support\FurnitureTypes::isValid($request->furniture_type)
            ? \App\Support\FurnitureTypes::promptInstruction($request->furniture_type, $request->furniture_type_other)
            : null;

        if ($instruction) {
            $parts[] = $instruction;
        }

        $parts[] = $request->description;

        if (!$instruction && $request->furniture_type) {
            $parts[] = "Furniture type: {$request->furniture_type}.";
        }

        // A special request wood is the team's to settle, so it is not put into the picture prompt.
        if ($request->preferred_material && !\App\Support\WoodChoices::isSpecial($request->preferred_material)) {
            $parts[] = "Wood: {$request->preferred_material}.";
        }

        if (\App\Support\StyleChoices::isValid($request->style_preference)) {
            $parts[] = "Style: {$request->style_preference}.";
        }

        if ($request->dimensions) {
            $parts[] = "Approximate size (length x width x height): {$request->dimensions}.";
        }

        $parts[] = "Clean white background, studio lighting, high quality, realistic wood texture, Filipino craftsmanship style.";

        return implode(' ', $parts);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("GenerateAiDesign job failed for request {$this->requestId}: " . $exception->getMessage());

        $request = CustomDesignRequest::find($this->requestId);

        // Already rejected (by the lazy check, design:fail-stale, or a
        // previous attempt of this same job) — leave it as-is, and
        // don't send a second failure notification for the same request.
        if (!$request || $request->status === 'rejected') {
            return;
        }

        $reason = $exception instanceof \Illuminate\Queue\MaxAttemptsExceededException
            || str_contains($exception->getMessage(), 'timed out')
            ? 'timeout'
            : 'provider_error';

        $request->update(['status' => 'rejected', 'failure_reason' => $reason]);

        Notification::create([
            'user_id' => $request->user_id,
            'title'   => 'Design Generation Failed',
            'message' => 'We were unable to generate designs for your request. Please try again or contact us directly.',
            'type'    => 'general',
            'url'     => route('design.index'),
            'is_read' => false,
        ]);
    }
}