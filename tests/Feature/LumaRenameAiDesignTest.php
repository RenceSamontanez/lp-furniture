<?php

namespace Tests\Feature;

use App\Models\ChatbotIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** luma:rename-ai-design: dry-run by default, --apply writes, safe to repeat, exact phrase only. Runs on the scratch database. */
class LumaRenameAiDesignTest extends TestCase
{
    use RefreshDatabase;

    private function seedRows(): void
    {
        $rows = require base_path('tests/Fixtures/LegacyIntents.php');
        foreach ([14, 20, 28] as $id) {
            if (!isset($rows[$id])) {
                continue;
            }
            (new ChatbotIntent())->forceFill($rows[$id] + ['id' => 9000 + $id, 'is_active' => true, 'category' => null])->save();
        }
        (new ChatbotIntent())->forceFill([
            'id' => 9100, 'keywords' => 'delivery, ai design', 'response' => 'We deliver within Batangas. Design your own with Customize.',
            'answer_tl' => 'Our AI DESIGN tool, ai design, at AI Designer is not a match. Subukan ang AI design.',
            'suggestions' => null, 'is_active' => true, 'category' => null,
        ])->save();
    }

    private function snapshot(): array
    {
        return DB::table('chatbot_intents')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_dry_run_prints_the_changes_and_writes_nothing(): void
    {
        $this->seedRows();
        $before = $this->snapshot();

        $this->artisan('luma:rename-ai-design')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Try AI Design tool')
            ->expectsOutputToContain('Try Customize tool')
            ->expectsOutputToContain('Run again with --apply')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_changes_only_the_exact_phrase_and_never_the_keywords(): void
    {
        $this->seedRows();
        $before = collect($this->snapshot())->keyBy('id');

        $this->artisan('luma:rename-ai-design', ['--apply' => true])->expectsOutputToContain('APPLY')->assertSuccessful();

        $after = collect($this->snapshot())->keyBy('id');
        foreach ($after as $id => $row) {
            $this->assertSame($before[$id]['keywords'], $row['keywords'], "keywords of $id untouched");
            foreach (['response', 'answer_tl', 'suggestions'] as $col) {
                $this->assertDoesNotMatchRegularExpression('/\bAI design\b/i', (string) $row[$col], "$id $col");
            }
        }
        $this->assertStringContainsString('Try Customize tool', $after[9014]['suggestions']);
        $this->assertStringContainsString('Our Customize tool lets you describe', $after[9028]['response']);
        $this->assertSame(
            'Our CUSTOMIZE tool, customize, at AI Designer is not a match. Subukan ang Customize.',
            $after[9100]['answer_tl']
        );
        // a row with no match in a field is byte-for-byte the same
        $this->assertSame($before[9100]['response'], $after[9100]['response']);
        $this->assertSame('delivery, ai design', $after[9100]['keywords']);
    }

    public function test_a_second_apply_finds_nothing_and_changes_nothing(): void
    {
        $this->seedRows();
        $this->artisan('luma:rename-ai-design', ['--apply' => true])->assertSuccessful();
        $once = $this->snapshot();

        $this->artisan('luma:rename-ai-design', ['--apply' => true])->expectsOutputToContain('Nothing to change.')->assertSuccessful();
        $this->artisan('luma:rename-ai-design')->expectsOutputToContain('Nothing to change.')->assertSuccessful();

        $this->assertSame($once, $this->snapshot());
    }
}
