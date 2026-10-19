<?php

/*
 * Inline link audit (Stage 21h standing UI rule). Static check, no browser needed:
 *
 *     php tests/UiLinks/audit.php
 *
 * Lists every <a href> in the Other Services, refund, invoice and notification pages (customer and admin) that is
 * neither a button (.lp-btn / .admin-btn), nor an allowed card-as-link or navigation element, nor an .lp-link, and exits
 * with status 1 when any remain. Links inside notices are covered too (a notice link must be .lp-link or .lp-btn).
 * The measured contrast of the link states comes from tests/UiLinks/measure-links.js, run in the browser.
 */

$root = dirname(__DIR__, 2);

$files = array_merge(
    glob("$root/resources/views/services/*.blade.php"),
    glob("$root/resources/views/services/refunds/*.blade.php"),
    glob("$root/resources/views/service-invoices/*.blade.php"),
    glob("$root/resources/views/notifications/*.blade.php"),
    glob("$root/resources/views/admin/service-inquiries/*.blade.php"),
    glob("$root/resources/views/admin/service-invoices/*.blade.php"),
    glob("$root/resources/views/admin/refunds/*.blade.php"),
);

// Elements that are deliberately not text links: buttons, filter tiles, card-as-link rows and the notification list's own CTA.
$allowed = ['lp-btn', 'lp-link', 'admin-btn', 'co-count', 'si-invoice', 'notif-action-cta', 'notif-history-btn', 'btn btn-gold', 'btn '];

$bad = [];
foreach ($files as $file) {
    $lines = file($file);
    foreach ($lines as $i => $line) {
        if (!preg_match_all('/<a\s(?:[^>]|(?<=[-=])>)*>/i', $line, $m)) {
            continue;
        }
        foreach ($m[0] as $tag) {
            $ok = false;
            foreach ($allowed as $cls) {
                if (stripos($tag, $cls) !== false) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $bad[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ':' . ($i + 1) . '  ' . trim($tag);
            }
        }
    }
}

if ($bad) {
    echo "Inline links without the .lp-link style (" . count($bad) . "):\n";
    foreach ($bad as $b) {
        echo "  $b\n";
    }
    exit(1);
}

echo "OK: every link in " . count($files) . " Other Services / refund / invoice / notification views is a button or an .lp-link.\n";
exit(0);
