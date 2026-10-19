<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/** Stage 21j: the one shared pagination view, rendered for first, middle, last and single-page lists. */
class PaginationViewTest extends TestCase
{
    private function render(int $page, int $total = 87, array $query = []): string
    {
        $items = array_fill(0, min(12, $total), 'x');
        $p = new LengthAwarePaginator($items, $total, 12, $page, ['path' => '/list', 'query' => $query]);

        return (string) $p->links();
    }

    public function test_default_views_are_the_shared_ones(): void
    {
        $html = $this->render(1);
        $this->assertStringContainsString('lp-pager', $html);
        $this->assertStringNotContainsString('lp-pagination', $html);
        $simple = (string) (new Paginator(['a'], 1, 2, ['path' => '/x']))->links();
        $this->assertStringContainsString('lp-pager', $simple);
    }

    public function test_first_page(): void
    {
        $html = $this->render(1);
        $this->assertStringContainsString('Showing 1 to 12 of 87', $html);
        $this->assertMatchesRegularExpression('/<span class="lp-btn lp-btn--secondary" aria-disabled="true">Previous/', $html);
        $this->assertStringContainsString('rel="next"', $html);
        $this->assertStringContainsString('Page 1 of 8', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('aria-label="Pagination"', $html);
    }

    public function test_middle_page_keeps_query_string(): void
    {
        $html = $this->render(4, 87, ['q' => 'oak']);
        $this->assertStringContainsString('rel="prev"', $html);
        $this->assertStringContainsString('rel="next"', $html);
        $this->assertStringContainsString('q=oak', $html);
        $this->assertStringContainsString('Page 4 of 8', $html);
        $this->assertStringContainsString('Showing 37 to 48 of 87', $html);
    }

    public function test_last_page(): void
    {
        $html = $this->render(8);
        $this->assertMatchesRegularExpression('/<span class="lp-btn lp-btn--secondary" aria-disabled="true">Next/', $html);
        $this->assertStringContainsString('rel="prev"', $html);
    }

    public function test_single_page_renders_nothing(): void
    {
        $this->assertSame('', trim($this->render(1, 5)));
    }
}
