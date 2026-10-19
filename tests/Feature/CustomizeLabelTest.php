<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** Stage 21j: the feature is called "Customize" wherever customers (and staff) read it. */
class CustomizeLabelTest extends TestCase
{
    public function test_no_blade_view_says_ai_design(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (preg_match('/AI design/i', preg_replace('/(class|id|for)="[^"]*"/', '', file_get_contents($file->getPathname())))) {
                $hits[] = $file->getPathname();
            }
        }
        $this->assertSame([], $hits, 'Views still say "AI Design"');
    }

    public function test_notification_templates_and_jobs_do_not_say_ai_design(): void
    {
        foreach (['app/Support/NotificationTemplates.php', 'app/Jobs/GenerateAiDesign.php', 'app/Support/Luma/DefaultIntents.php'] as $f) {
            $text = file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            // 'ai design' as a trained keyword and the luma_ai_design_steps key are matching data, not visible text
            $text = preg_replace("/'keywords' => '[^']*'/", '', $text);
            $text = str_replace('luma_ai_design_steps', '', $text);
            $this->assertDoesNotMatchRegularExpression('/AI designs?\b/i', $text, $f);
        }
    }
}
