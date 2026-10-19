<?php

namespace Tests\Feature;

use App\Models\ChatbotIntent;
use App\Support\Luma\DefaultIntents;
use App\Support\Luma\IntentMatcher;
use App\Support\Luma\OtherServicesKnowledge as K;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 21i: docs/luma-knowledge-map.md is generated from OtherServicesKnowledge. Every example phrasing in it (English,
 * Tagalog and Taglish, with typos and short forms) must reach its own node, and the knowledge must follow the tone rules.
 */
class LumaKnowledgeMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
    }

    public function test_every_node_has_six_phrasings_per_language_and_both_answers(): void
    {
        $this->assertGreaterThanOrEqual(50, count(K::nodes()));

        foreach (K::nodes() as $id => $node) {
            $this->assertStringStartsWith('luma_os_', $id);
            foreach (['en', 'tl', 'mix'] as $k) {
                $this->assertGreaterThanOrEqual(6, count($node['say'][$k] ?? []), "{$id} {$k}");
            }
            $this->assertGreaterThan(40, strlen($node['en']), $id);
            $this->assertGreaterThan(40, strlen($node['tl']), $id);
            $this->assertStringContainsStringIgnoringCase('po', $node['tl'], $id);
            foreach ($node['next'] ?? [] as $child) {
                $this->assertArrayHasKey($child, K::nodes(), "{$id} -> {$child}");
            }
        }
    }

    public function test_the_knowledge_follows_the_tone_rules(): void
    {
        foreach (K::nodes() as $id => $node) {
            $text = $node['en'] . ' ' . $node['tl'];
            $this->assertDoesNotMatchRegularExpression('/\bapprov|guarant|you will get a refund|will be refunded|legal|hereby|pursuant/i', $text, $id);
            $this->assertDoesNotMatchRegularExpression('/(PHP|₱)\s?\d/', $text, $id);   // numbers come from {{tokens}}
            $this->assertLessThanOrEqual(1, preg_match_all("/I'm sorry|Pasensya na/", $node['en'] . "\n", $m) + preg_match_all('/Pasensya na/', $node['tl'], $n) - preg_match_all('/Pasensya na/', $node['tl'], $n2) , $id);
        }
    }

    public function test_every_example_phrasing_reaches_its_own_node(): void
    {
        $intents  = ChatbotIntent::query()->where('is_active', true)->get();
        $failures = [];
        $total    = 0;

        foreach (K::nodes() as $id => $node) {
            foreach (['en', 'tl', 'mix'] as $k) {
                foreach ($node['say'][$k] as $phrase) {
                    $total++;
                    $hit  = IntentMatcher::best($phrase, $intents);
                    $got  = $hit['intent']->category ?? '(none)';
                    if ($got !== $id) {
                        $failures[] = "[{$k}] \"{$phrase}\" wanted {$id}, got {$got}";
                    }
                }
            }
        }

        $this->assertGreaterThan(900, $total);
        $this->assertSame([], $failures, count($failures) . " of {$total} phrasings missed:\n" . implode("\n", $failures));
    }

    public function test_the_chips_of_a_node_reach_the_child_node(): void
    {
        $intents = ChatbotIntent::query()->where('is_active', true)->get();

        foreach (K::nodes() as $id => $node) {
            foreach ($node['next'] ?? [] as $child) {
                foreach (['title', 'title_tl'] as $field) {
                    $hit = IntentMatcher::best(K::nodes()[$child][$field], $intents);
                    $this->assertSame($child, $hit['intent']->category ?? null, "chip {$field} of {$child} (from {$id})");
                }
            }
        }
    }

    public function test_sync_adds_the_new_intents_and_never_overwrites_an_edited_one(): void
    {
        $this->assertSame(count(K::nodes()), ChatbotIntent::where('category', 'like', 'luma_os_%')->count());
        $this->assertTrue(DefaultIntents::isCard('luma_os_paid_twice'));
        $this->assertFalse(DefaultIntents::isCard('luma_os_what_is'));

        ChatbotIntent::where('category', 'luma_os_what_is')->update(['response' => 'EDITED BY ADMIN']);
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
        $this->assertSame('EDITED BY ADMIN', ChatbotIntent::where('category', 'luma_os_what_is')->value('response'));
    }

    public function test_the_generated_document_is_up_to_date(): void
    {
        $path = base_path('docs/luma-knowledge-map.md');
        $old  = file_get_contents($path);
        $this->artisan('luma:knowledge-map')->assertSuccessful();
        $new = file_get_contents($path);
        file_put_contents($path, $old);

        $this->assertSame($old, $new, 'Run: php artisan luma:knowledge-map');
    }
}
