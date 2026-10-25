<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/** The History button and the Recent strip on the Customize page. */
class CustomizeRecentTest extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownFlow();
        parent::tearDown();
    }

    private function ready(User $u, string $words, string $when): CustomDesignRequest
    {
        [$r] = $this->generatedRequest($u);
        $r->forceFill(['description' => $words . ' for my living room', 'created_at' => $when, 'updated_at' => $when])->save();

        return $r;
    }

    private function failed(User $u, string $words): CustomDesignRequest
    {
        return CustomDesignRequest::create([
            'user_id' => $u->id, 'title' => 'x', 'furniture_type' => 'Table', 'description' => $words . ' failed one',
            'status' => 'rejected', 'failure_reason' => 'timeout',
        ]);
    }

    private function strip(User $u): array
    {
        $html = $this->asCustomer($u)->get(route('design.index'))->assertOk()->getContent();
        preg_match_all('/<button type="button" class="cz-f js-open-design" data-design="([^"]*)"/', $html, $m);

        return ['html' => $html, 'designs' => array_map(fn ($j) => json_decode(html_entity_decode($j), true), $m[1])];
    }

    public function test_guests_see_neither_the_history_button_nor_the_strip(): void
    {
        $this->get(route('design.index'))->assertRedirect();   // the page itself needs an account

        // and the view hides both for a guest even if it were rendered
        $blade = file_get_contents(resource_path('views/design/index.blade.php'));
        $this->assertMatchesRegularExpression('/@auth\s*<a href="\{\{ route\(\'design\.gallery\', \[\'tab\' => \'history\'\]\) \}\}" class="lp-btn lp-btn--secondary lp-btn--chip cz-history"/', $blade);
        $this->assertStringContainsString('@auth' . "\n" . '      <div class="cz-film"', str_replace("\r\n", "\n", $blade));
    }

    public function test_history_button_opens_the_history_tab_and_view_all_the_gallery_tab(): void
    {
        $u = $this->flowCustomer();
        $this->ready($u, 'oak table', now()->subDay()->toDateTimeString());
        $html = $this->strip($u)['html'];

        $this->assertMatchesRegularExpression('/<a href="' . preg_quote(route('design.gallery', ['tab' => 'history']), '/') . '" class="lp-btn lp-btn--secondary lp-btn--chip cz-history">\s*<svg[^>]*>.*?<\/svg>\s*<span class="cz-history-t">History<\/span>/s', $html);
        $this->assertMatchesRegularExpression('/<a href="' . preg_quote(route('design.gallery', ['tab' => 'gallery']), '/') . '" class="lp-btn lp-btn--glass-quiet lp-btn--chip cz-viewall">View all<\/a>/', $html);
        $this->assertStringContainsString('id="md-modal"', $html);   // the shared glass preview box
    }

    public function test_zero_two_and_five_successes_show_zero_two_and_three_thumbnails(): void
    {
        $none = $this->flowCustomer();
        $r = $this->strip($none);
        $this->assertCount(0, $r['designs']);
        $this->assertStringContainsString('Your first designs will appear here', $r['html']);
        $this->assertStringContainsString('RECENT', $r['html'], 'the strip is still there with no designs');
        $this->assertStringNotContainsString('cz-viewall', preg_replace('/\.cz-viewall[^"]*/', '', $r['html']));

        $two = $this->flowCustomer();
        foreach ([1, 2] as $i) { $this->ready($two, "table $i", now()->subDays($i)->toDateTimeString()); }
        $this->assertCount(2, $this->strip($two)['designs']);

        $five = $this->flowCustomer();
        foreach ([1, 2, 3, 4, 5] as $i) { $this->ready($five, "table $i", now()->subDays($i)->toDateTimeString()); }
        $this->assertCount(3, $this->strip($five)['designs']);
    }

    public function test_newest_first_and_failed_designs_never_appear(): void
    {
        $u = $this->flowCustomer();
        $old = $this->ready($u, 'oldest', now()->subDays(9)->toDateTimeString());
        $mid = $this->ready($u, 'middle', now()->subDays(5)->toDateTimeString());
        $new = $this->ready($u, 'newest', now()->subDays(1)->toDateTimeString());
        $this->failed($u, 'newestfail');   // newer than all of them, but failed

        // a request whose stored image file is gone is not a success either
        $gone = $this->ready($u, 'missingfile', now()->subHours(2)->toDateTimeString());
        AiDesignSuggestion::where('design_id', $gone->id)->update(['image_path' => 'images/ai-designs/phpunit-does-not-exist.png']);

        $ids = array_column($this->strip($u)['designs'], 'requestId');
        $this->assertSame([$new->id, $mid->id, $old->id], $ids);
    }

    public function test_another_customers_designs_never_appear(): void
    {
        $me = $this->flowCustomer();
        $other = $this->flowCustomer('Ben');
        $theirs = $this->ready($other, 'theirs', now()->toDateTimeString());
        $mine = $this->ready($me, 'mine', now()->subDay()->toDateTimeString());

        $r = $this->strip($me);
        $this->assertSame([$mine->id], array_column($r['designs'], 'requestId'));
        $this->assertStringNotContainsString('theirs', $r['html']);
    }

    public function test_each_thumbnail_has_a_name_and_a_dated_title_and_uses_the_real_image(): void
    {
        $u = $this->flowCustomer();
        $r = $this->ready($u, 'walnut desk', '2026-10-20 14:30:00');
        $html = $this->strip($u)['html'];

        $this->assertStringContainsString('title="Oct 20, 2026, 2:30 PM"', $html);
        $this->assertStringContainsString('aria-label="Open design: walnut desk for my living room, made Oct 20, 2026, 2:30 PM"', $html);
        $this->assertStringContainsString(basename($this->flowImage), $html);
    }

    public function test_the_strip_asks_the_database_a_fixed_number_of_times(): void
    {
        $u = $this->flowCustomer();
        foreach ([1, 2, 3, 4, 5] as $i) { $this->ready($u, "table $i", now()->subDays($i)->toDateTimeString()); }

        DB::flushQueryLog();
        DB::enableQueryLog();
        CustomDesignRequest::recentReadyFor($u->id, 3);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $queries, 'one for the requests, one for all their suggestions');
    }

    public function test_the_preview_payload_follows_the_sent_to_team_rule(): void
    {
        $u = $this->flowCustomer();
        $r = $this->ready($u, 'sent one', now()->toDateTimeString());
        $this->assertFalse($this->strip($u)['designs'][0]['ordered']);

        $r->forceFill(['pipeline_status' => 'submitted'])->save();
        $this->assertTrue($this->strip($u)['designs'][0]['ordered']);
    }

    public function test_no_ai_design_label_in_the_view(): void
    {
        $this->assertDoesNotMatchRegularExpression('/AI designs?\b/i', file_get_contents(resource_path('views/design/index.blade.php')));
    }
}
