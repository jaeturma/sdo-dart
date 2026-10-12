<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Make the browser re-check a served document file with the server on
     * every view instead of silently reusing a cached copy.
     *
     * response()->file() defaults to `Cache-Control: public` plus an
     * automatic Last-Modified and no max-age, which lets browsers apply
     * heuristic freshness and serve a cached copy without asking the server
     * at all. These files live at fixed URLs whose content can change
     * underneath them — a resubmission replaces documents/{id}/file, and an
     * undone-then-redone signature replaces documents/{id}/signed-pdf — so
     * users kept seeing the superseded PDF after a resubmission (a real
     * reported bug). `public` was also wrong for access-controlled files on
     * its own: it permits shared caches (e.g. Cloudflare in production) to
     * store them.
     *
     * The stored SHA-256 doubles as a strong ETag, so an unchanged file
     * still revalidates to a cheap 304 rather than a full re-download.
     */
    protected function revalidateOnEveryView(BinaryFileResponse $response, Request $request, ?string $fileHash): BinaryFileResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');

        if ($fileHash) {
            $response->setEtag($fileHash);
        }

        $response->isNotModified($request);

        return $response;
    }
}
