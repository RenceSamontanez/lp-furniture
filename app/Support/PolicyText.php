<?php

namespace App\Support;

use App\Models\BusinessSetting;

/**
 * Turns a stored policy text into sections for display.
 *
 * The stored text stays a plain string (that is what the agreement snapshot
 * and its SHA-256 cover). Format: "## Heading" starts a section, a blank line
 * separates paragraphs, lines starting with "- " form a list. Text with no
 * headings (version 1) is one section without a heading. {contact_phone} and
 * {contact_email} are filled in from Contact Settings at display time only,
 * so the stored text and its hash never change when a phone number does.
 */
final class PolicyText
{
    /**
     * @return list<array{heading: ?string, blocks: list<array{type: string, text?: string, items?: list<string>}>}>
     */
    public static function sections(?string $body, ?BusinessSetting $business = null): array
    {
        $body = self::fillContact((string) $body, $business);
        $body = str_replace("\r\n", "\n", trim($body));

        if ($body === '') {
            return [];
        }

        $sections = [];
        $current = ['heading' => null, 'blocks' => []];

        foreach (preg_split('/\n{2,}/', $body) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            // A heading may sit directly above its first paragraph.
            while (str_starts_with($chunk, '## ')) {
                [$line, $rest] = array_pad(explode("\n", $chunk, 2), 2, '');

                if ($current['heading'] !== null || $current['blocks'] !== []) {
                    $sections[] = $current;
                }
                $current = ['heading' => trim(substr($line, 3)), 'blocks' => []];
                $chunk = trim($rest);
            }

            if ($chunk === '') {
                continue;
            }

            $lines = explode("\n", $chunk);
            if (count(array_filter($lines, fn ($l) => str_starts_with(ltrim($l), '- '))) === count($lines)) {
                $current['blocks'][] = ['type' => 'list', 'items' => array_map(fn ($l) => trim(substr(ltrim($l), 2)), $lines)];
            } else {
                $current['blocks'][] = ['type' => 'p', 'text' => implode(' ', array_map('trim', $lines))];
            }
        }

        if ($current['heading'] !== null || $current['blocks'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    public static function fillContact(string $body, ?BusinessSetting $business = null): string
    {
        return strtr($body, [
            '{contact_phone}' => NotificationTemplates::phone($business) ?: 'the phone number on our website',
            '{contact_email}' => NotificationTemplates::email($business) ?: 'the email address on our website',
        ]);
    }
}
