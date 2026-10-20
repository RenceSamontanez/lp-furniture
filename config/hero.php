<?php

/*
 * Home page hero: the four AI Showcase tiles. Put the image files in public/images/hero-ai/ (see the README there).
 * A tile whose file is missing shows a neutral "AI sample" placeholder instead; nothing is invented.
 */
return [
    'ai_images' => [
        ['file' => 'ai-1.jpg', 'alt' => 'AI sample: sofa'],
        ['file' => 'ai-2.jpg', 'alt' => 'AI sample: dining table'],
        ['file' => 'ai-3.jpg', 'alt' => 'AI sample: cabinet'],
        ['file' => 'ai-4.jpg', 'alt' => 'AI sample: bed'],
    ],
    // Placeholder glyph per tile when its image is missing: chair, sofa, table, bed, cab
    'ai_glyphs' => ['sofa', 'table', 'cab', 'bed'],
];
