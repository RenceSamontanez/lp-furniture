<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use Symfony\Component\HttpFoundation\Response;

/** Streams one reference photo. The caller has already checked who may see this request. */
final class ReferencePhotoResponse
{
    public static function for(CustomDesignRequest $request, bool $download): Response
    {
        $path = ReferencePhoto::absolutePath($request->reference_image);
        $mime = $path ? ReferencePhoto::mimeOf($path) : null;
        abort_unless($path && $mime, 404);

        return response()->file($path, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => ($download ? 'attachment' : 'inline') . '; filename="' . ReferencePhoto::downloadName($request, $mime) . '"',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
