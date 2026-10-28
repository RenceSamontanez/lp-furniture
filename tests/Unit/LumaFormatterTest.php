<?php

namespace Tests\Unit;

use App\Support\Luma\Formatter;
use PHPUnit\Framework\TestCase;

/** The safe formatter for Luma's replies: escape everything first, then build only the markup it knows. */
class LumaFormatterTest extends TestCase
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'a', 'span'];

    private function html(string $text): string
    {
        return Formatter::toHtml($text);
    }

    private function assertOnlyAllowedTags(string $html): void
    {
        preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)/', $html, $m);
        foreach ($m[1] as $tag) {
            $this->assertContains(strtolower($tag), self::ALLOWED_TAGS, "unexpected <{$tag}> in {$html}");
        }
        $this->assertDoesNotMatchRegularExpression('/<[a-z0-9]+[^>]*\son[a-z]+\s*=/i', $html, 'an event handler attribute got through');
    }

    public function test_html_is_escaped_before_anything_else(): void
    {
        $html = $this->html('<script>alert(1)</script> & "quoted" \'single\'');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quoted&quot; &#039;single&#039;</p>', $html);
    }

    public function test_paragraphs_and_line_breaks(): void
    {
        $this->assertSame('<p>One.</p><p>Two<br>still two.</p>', $this->html("One.\n\nTwo\nstill two."));
        $this->assertSame('', $this->html("  \n\n "));
    }

    public function test_bold(): void
    {
        $this->assertSame('<p>This is <strong>important</strong> now.</p>', $this->html('This is **important** now.'));
        $this->assertSame('<p>**not closed</p>', $this->html('**not closed'));
        $this->assertSame('<p><strong>&lt;b&gt;</strong></p>', $this->html('**<b>**'));
    }

    public function test_bullet_lists_and_numbered_lists(): void
    {
        $this->assertSame('<p>Options:</p><ul class="lf-list"><li><strong>Pickup</strong> at the shop.</li><li>Delivery.</li></ul>', $this->html("Options:\n- **Pickup** at the shop.\n* Delivery."));
        $this->assertSame('<ol class="lf-list"><li>First</li><li>Second</li><li>Third</li></ol>', $this->html("1. First\n2) Second\n3. Third"));
        $this->assertSame('<ul class="lf-list"><li>Dot</li></ul>', $this->html('• Dot'));
    }

    public function test_label_value_lines_make_a_block(): void
    {
        $html = $this->html("Email: shop@example.com\nPhone: 0991 239 4770");

        $this->assertStringStartsWith('<dl class="lf-kv"><dt>Email</dt><dd><a href="mailto:shop@example.com">shop@example.com</a></dd><dt>Phone</dt>', $html);
        $this->assertStringContainsString('<a href="tel:09912394770" class="lf-num">0991 239 4770</a>', $html);

        // one lone line is a block only with a known contact label
        $this->assertStringStartsWith('<dl class="lf-kv"><dt>Open</dt>', $this->html('Open: 8:00 AM to 5:00 PM'));
        $this->assertSame('<p>Note: this is just a sentence.</p>', $this->html('Note: this is just a sentence.'));
        $this->assertSame('<p>Custom design works like this: choose a type.</p>', $this->html('Custom design works like this: choose a type.'));
    }

    public function test_a_note_line_is_small_and_muted(): void
    {
        $this->assertSame('<p>Body.</p><p class="lf-note">You get a notification.</p>', $this->html("Body.\n\n> You get a notification."));
    }

    public function test_markdown_links_only_for_safe_schemes(): void
    {
        $this->assertSame(
            '<p><a href="https://facebook.com/lp" target="_blank" rel="noopener noreferrer nofollow">LP Furniture on Facebook</a></p>',
            $this->html('[LP Furniture on Facebook](https://facebook.com/lp)')
        );
        $this->assertSame('<p><a href="/catalog">Catalog</a></p>', $this->html('[Catalog](/catalog)'));
        $this->assertSame('<p><a href="mailto:a@b.ph">Mail</a></p>', $this->html('[Mail](mailto:a@b.ph)'));

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,AAAA', 'vbscript:x', '//evil.example/x', 'file:///etc/passwd', 'ftp://x.test/a'] as $bad) {
            $html = $this->html("[click me]({$bad})");
            $this->assertStringNotContainsString('<a ', $html, $bad);
            $this->assertStringNotContainsString('href', $html, $bad);
            $this->assertStringContainsString('click me', $html, $bad);
        }
    }

    public function test_auto_links(): void
    {
        $html = $this->html('Write to team@lp.test or call +63 917 123 4567 or 09171234567. See https://lp.test/page?a=1&b=2.');

        $this->assertStringContainsString('<a href="mailto:team@lp.test">team@lp.test</a>', $html);
        $this->assertStringContainsString('<a href="tel:+639171234567" class="lf-num">+63 917 123 4567</a>', $html);
        $this->assertStringContainsString('<a href="tel:09171234567" class="lf-num">09171234567</a>', $html);
        // external links open in a new tab, with the full rel, and the closing full stop is not part of the address
        $this->assertStringContainsString('<a href="https://lp.test/page?a=1&amp;b=2" target="_blank" rel="noopener noreferrer nofollow">https://lp.test/page?a=1&amp;b=2</a>.', $html);
    }

    public function test_ordinary_numbers_and_dates_are_not_phone_links(): void
    {
        $html = $this->html('Order 2026-10-28 has 15 to 30 days and 5,000 pieces.');

        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('<a', $html);
    }

    public function test_prices_get_the_number_class(): void
    {
        $this->assertSame('<p>It costs <span class="lf-num">₱42,500</span> or <span class="lf-num">PHP 5,000</span>.</p>', $this->html('It costs ₱42,500 or PHP 5,000.'));
    }

    public function test_nothing_raw_ever_reaches_the_page(): void
    {
        $payloads = [
            '<img src=x onerror=alert(1)>',
            '<a href="javascript:alert(1)">x</a>',
            '"><svg/onload=alert(1)>',
            "- <script>alert(1)</script>\n- ok",
            "Email: <b>x</b>\nPhone: <i>y</i>",
            '[x](https://a.test/"onmouseover="alert(1))',
            'https://a.test/"onmouseover="alert(1)',
            '**<iframe src=//evil>**',
            "> <style>*{display:none}</style>",
            'user"@evil.test onmouseover=alert(1)',
        ];

        foreach ($payloads as $p) {
            $html = $this->html($p);
            $this->assertOnlyAllowedTags($html);
            $this->assertStringNotContainsString('<script', $html, $p);
            $this->assertStringNotContainsString('<img', $html, $p);
            $this->assertStringNotContainsString('<iframe', $html, $p);
            // the only attributes are the ones the formatter writes itself
            preg_match_all('/<[a-z0-9]+\s([^>]*)>/i', $html, $attrs);
            foreach ($attrs[1] as $a) {
                $this->assertMatchesRegularExpression('/^(href="[^"]*"( target="_blank" rel="noopener noreferrer nofollow")?( class="lf-num")?|class="[a-z\-]+")$/', $a, $p);
            }
        }
    }
}
