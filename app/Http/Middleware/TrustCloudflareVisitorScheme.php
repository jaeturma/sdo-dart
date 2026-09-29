<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustCloudflareVisitorScheme
{
    /**
     * Production sits behind Cloudflare, reverse-proxied through a local
     * Nginx/Apache on the same box before reaching PHP-FPM. That extra hop
     * means the REMOTE_ADDR TrustProxies checks against Cloudflare's IP
     * ranges (see bootstrap/app.php) is the local web server, not
     * Cloudflare's edge — so X-Forwarded-Proto never gets trusted, and
     * every scheme-dependent feature (pagination links, session/CSRF
     * cookie Secure flags, etc.) falls back to plain http even though the
     * site is https-only. Cloudflare's CF-Visitor header survives that
     * hop unaffected either way (it's a distinct header from
     * X-Forwarded-Proto, so a misconfigured local proxy that clobbers the
     * latter doesn't touch this one), so we read it directly instead of
     * depending on IP-based proxy trust. This only ever flips a request
     * to "secure" (never the reverse) and never touches client-IP
     * resolution, so it can't be used to spoof $request->ip() the way
     * trusting X-Forwarded-For from an untrusted hop could.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $visitor = $request->header('CF-Visitor');

        if ($visitor && str_contains($visitor, '"scheme":"https"')) {
            $request->server->set('HTTPS', 'on');
        }

        return $next($request);
    }
}
