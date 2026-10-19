<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LumaButtonSystemTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/public/assets/style.css');
    }

    private function js(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/public/assets/main.js');
    }

    private function token(string $name): string
    {
        preg_match('/--'.preg_quote($name, '/').':\s*(#[0-9A-Fa-f]{6})/', $this->css(), $m);
        $this->assertNotEmpty($m, "Missing token --$name");

        return $m[1];
    }

    private function luminance(string $hex): float
    {
        $c = array_map(fn ($v) => hexdec($v) / 255, str_split(ltrim($hex, '#'), 2));
        $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function ratio(string $a, string $b): float
    {
        $t = new self('x');
        [$l1, $l2] = [$t->luminance($a), $t->luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    public function test_every_button_state_meets_4_5_to_1(): void
    {
        $pairs = [
            'primary' => ['luma-primary-text', 'luma-primary'],
            'primary hover' => ['luma-primary-hover-text', 'luma-primary-hover'],
            'primary active' => ['luma-primary-text', 'luma-primary-active'],
            'secondary' => ['luma-secondary-text', 'luma-secondary'],
            'secondary hover' => ['luma-secondary-text', 'luma-secondary-hover'],
            'secondary active' => ['luma-secondary-text', 'luma-secondary-active'],
            'disabled' => ['luma-disabled-text', 'luma-disabled'],
        ];
        foreach ($pairs as $state => [$fg, $bg]) {
            $r = self::ratio($this->token($fg), $this->token($bg));
            $this->assertGreaterThanOrEqual(4.5, $r, "$state contrast is $r");
        }
    }

    public function test_buttons_are_scoped_so_the_global_link_reset_cannot_win(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('.chatbot-window .chat-btn--primary:hover', $css);
        $this->assertStringContainsString('.chatbot-window .chat-btn--secondary:hover', $css);
        $this->assertStringContainsString('min-height: 44px', $css);
        $this->assertDoesNotMatchRegularExpression('/^\.chat-link-btn/m', $css);
    }

    public function test_widget_markup_uses_the_shared_classes(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('chat-btn--primary', $js);
        $this->assertStringContainsString('chat-btn--secondary', $js);
        $this->assertStringContainsString('class="chat-chip lp-btn lp-btn--secondary lp-btn--chip"', $js);   // Stage 21i: chips are the shared .lp-btn chip
        $this->assertStringContainsString('lp-btn lp-btn--primary', $js);
        $this->assertStringContainsString('extra.buttons.slice(0, 3)', $js);
        $this->assertStringNotContainsString('chat-link-btn', $js);
    }
}
