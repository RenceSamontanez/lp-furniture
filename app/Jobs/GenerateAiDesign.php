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

    public function __construct(
        public readonly int $requestId
    ) {}

    public function handle(): void
    {
        $request = CustomDesignRequest::findOrFail($this->requestId);
        $request->update(['status' => 'pending']);

        $prompt = $this->buildPrompt($request);
        $request->update(['ai_prompt' => $prompt]);

        $generated = 0;

        for ($i = 1; $i <= 4; $i++) {

            // Provider order: Gemini (primary) → Hugging Face → DeepAI → Pollinations
            $provider = 'gemini';
            $result = $this->tryGemini($prompt, $i);

            if (!$result) {
                $provider = 'huggingface-sd';
                $result = $this->tryHuggingFace($prompt, $i);
            }

            if (!$result) {
                $provider = 'deepai';
                $result = $this->tryDeepAi($prompt, $i);
            }

            if (!$result) {
                $provider = 'pollinations-ai';
                $result = $this->tryPollinations($prompt, $i);
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
                Log::warning("All 4 providers failed for variation {$i}, request {$this->requestId}.");
            }

            if ($i < 4) {
                sleep(1);
            }
        }

        if ($generated > 0) {
            $request->update(['status' => 'ai_generated']);

            Notification::create([
                'user_id' => $request->user_id,
                'title'   => 'Your AI Design Suggestions Are Ready!',
                'message' => "We've generated {$generated} design suggestion(s) for your {$request->furniture_type} request. Review and select your favorite!",
                'type'    => 'design_ready',
                'url'     => route('design.show', $request->id),
                'is_read' => false,
            ]);
        } else {
            $request->update(['status' => 'rejected']);

            Notification::create([
                'user_id' => $request->user_id,
                'title'   => 'Design Generation Failed',
                'message' => 'We were unable to generate AI designs for your request. Please try again or contact us directly.',
                'type'    => 'general',
                'url'     => route('design.index'),
                'is_read' => false,
            ]);
        }
    }

    /* ════════════════════════════════
       PROVIDER 1 (PRIMARY): Google Gemini
       2.5 Flash Image ("Nano Banana")
       Free: 500/day, no credit card.
       Returns base64 image data — saved locally.
    ════════════════════════════════ */
    private function tryGemini(string $prompt, int $variation): ?string
    {
        $apiKey = config('services.gemini.key');

        if (!$apiKey) {
            Log::info("[Gemini] no API key configured — skipping.");
            return null;
        }

        // Gemini's free tier allows only 10 requests per minute.
        // Spacing calls out by ~8 seconds keeps us safely under
        // that limit across 4 variations in a single job run.
        static $lastGeminiCall = null;
        if ($lastGeminiCall !== null) {
            $elapsed = microtime(true) - $lastGeminiCall;
            $minGap = 8;
            if ($elapsed < $minGap) {
                sleep((int) ceil($minGap - $elapsed));
            }
        }

        for ($attempt = 1; $attempt <= 1; $attempt++) {
            $lastGeminiCall = microtime(true);

            try {
                Log::info("[Gemini] attempt {$attempt}, variation {$variation}, request {$this->requestId}");

                $response = Http::timeout(45)
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

                Log::info("[Gemini] response variation {$variation} attempt {$attempt}: status=" . $response->status());

                if ($response->successful()) {
                    $data = $response->json();
                    $parts = $data['candidates'][0]['content']['parts'] ?? [];

                    foreach ($parts as $part) {
                        if (isset($part['inlineData']['data'])) {
                            $imageBytes = base64_decode($part['inlineData']['data']);

                            $filename = 'ai-design-' . Str::random(16) . '.png';
                            $destination = public_path('images/ai-designs');

                            if (!file_exists($destination)) {
                                mkdir($destination, 0755, true);
                            }

                            file_put_contents($destination . '/' . $filename, $imageBytes);
                            return asset('images/ai-designs/' . $filename);
                        }
                    }
                }

                // Rate limited — wait using Google's suggested retry delay, then try once more
                if ($response->status() === 429) {
                    Log::info("[Gemini] rate limited (quota likely exhausted) — moving to next provider immediately.");
                    break;
                }

                break;

            } catch (\Exception $e) {
                Log::error("[Gemini] error variation {$variation} attempt {$attempt}: " . $e->getMessage());
                sleep(3);
            }
        }

        Log::warning("[Gemini] failed for variation {$variation}, request {$this->requestId}. Falling back.");
        return null;
    }

    /* ════════════════════════════════
       PROVIDER 2: Hugging Face Inference API
    ════════════════════════════════ */
    private function tryHuggingFace(string $prompt, int $variation): ?string
    {
        $apiKey = config('services.huggingface.key');

        if (!$apiKey) {
            Log::info("[Hugging Face] no API key configured — skipping.");
            return null;
        }

        try {
            Log::info("[Hugging Face] attempt, variation {$variation}, request {$this->requestId}");

            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://router.huggingface.co/hf-inference/models/black-forest-labs/FLUX.1-schnell', [
                    'inputs' => $prompt . ", variation {$variation}",
                ]);

            Log::info("[Hugging Face] response variation {$variation}: status=" . $response->status());

            if ($response->successful() && str_starts_with($response->header('Content-Type'), 'image/')) {
                $filename = 'ai-design-' . Str::random(16) . '.jpg';
                $destination = public_path('images/ai-designs');

                if (!file_exists($destination)) {
                    mkdir($destination, 0755, true);
                }

                file_put_contents($destination . '/' . $filename, $response->body());
                return asset('images/ai-designs/' . $filename);
            }

            Log::warning("[Hugging Face] no image returned for variation {$variation}: " . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error("[Hugging Face] error variation {$variation}: " . $e->getMessage());
            return null;
        }
    }

    /* ════════════════════════════════
       PROVIDER 3: DeepAI Text2Img
    ════════════════════════════════ */
    private function tryDeepAi(string $prompt, int $variation): ?string
    {
        $apiKey = config('services.deepai.key');

        if (!$apiKey) {
            Log::info("[DeepAI] no API key configured — skipping.");
            return null;
        }

        try {
            Log::info("[DeepAI] attempt, variation {$variation}, request {$this->requestId}");

            $response = Http::timeout(60)
                ->withHeaders(['api-key' => $apiKey])
                ->asForm()
                ->post('https://api.deepai.org/api/text2img', [
                    'text' => $prompt . ", variation {$variation}",
                ]);

            Log::info("[DeepAI] response variation {$variation}: status=" . $response->status());

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['output_url'])) {
                    return $data['output_url'];
                }
            }

            Log::warning("[DeepAI] no image returned for variation {$variation}: " . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error("[DeepAI] error variation {$variation}: " . $e->getMessage());
            return null;
        }
    }

    /* ════════════════════════════════
       PROVIDER 4 (LAST RESORT): Pollinations.ai
       Demoted — free/no-key, but has shown
       consistently poor reliability in testing.
    ════════════════════════════════ */
    private function tryPollinations(string $prompt, int $variation): ?string
    {
        $seed = crc32($this->requestId . '-' . $variation . '-' . time());
        $encodedPrompt = urlencode($prompt . ", variation {$variation}, professional product photography");

        $imageUrl = "https://image.pollinations.ai/prompt/{$encodedPrompt}"
            . "?width=1024&height=1024&seed={$seed}&nologo=true";

        Log::info("[Pollinations] attempt (last resort), variation {$variation}, request {$this->requestId}");

        try {
            $response = Http::timeout(30)->connectTimeout(8)->withoutVerifying()->get($imageUrl);
            Log::info("[Pollinations] response variation {$variation}: status=" . $response->status());

            if ($response->successful()) {
                return $imageUrl;
            }

        } catch (\Exception $e) {
            Log::error("[Pollinations] error variation {$variation}: " . $e->getMessage());
        }

        return null;
    }

    private function buildPrompt(CustomDesignRequest $request): string
    {
        $parts = [
            "Professional product photo of a custom Philippine hardwood furniture piece.",
            $request->description,
        ];

        if ($request->furniture_type) {
            $parts[] = "Furniture type: {$request->furniture_type}.";
        }

        if ($request->preferred_material) {
            $parts[] = "Wood: {$request->preferred_material}.";
        }

        if ($request->dimensions) {
            $parts[] = "Approximate dimensions: {$request->dimensions}.";
        }

        $parts[] = "Clean white background, studio lighting, high quality, realistic wood texture, Filipino craftsmanship style.";

        return implode(' ', $parts);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("GenerateAiDesign job failed for request {$this->requestId}: " . $exception->getMessage());

        $request = CustomDesignRequest::find($this->requestId);
        if ($request) {
            $request->update(['status' => 'rejected']);
        }
    }
}