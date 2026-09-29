<?php

namespace App\Providers;

use App\Models\UserSigningCredential;
use App\Policies\SigningCredentialPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(UserSigningCredential::class, SigningCredentialPolicy::class);

        Vite::prefetch(concurrency: 3);

        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers()->symbols());

        // Production sits behind Cloudflare, and bootstrap/app.php's
        // trustProxies() only trusts Cloudflare's published edge IPs. If a
        // local reverse proxy (Nginx/Apache) sits between Cloudflare and
        // PHP, that proxy — not Cloudflare — is the REMOTE_ADDR Laravel
        // sees, so the X-Forwarded-Proto header goes untrusted and every
        // generated URL (asset/Vite/route) falls back to http:// even
        // though the site is only ever served over https, causing the
        // browser to block them as mixed content. Forcing the scheme here
        // sidesteps that proxy-detection chain entirely.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
