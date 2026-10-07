<?php

namespace App\Providers;

use App\Support\AppUrl;
use Illuminate\Mail\Markdown;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useTailwind();

        // M5: the rule every school admin password must meet when it is set.
        Password::defaults(function () {
            $rule = Password::min(max(8, (int) config('auth.school_passwords.min_length', 10)))->max(255);

            return config('auth.school_passwords.breach_check', true) ? $rule->uncompromised() : $rule;
        });

        // L8: in Markdown mail (the receipt) every {{ }} value is encoded so it
        // can never form a Markdown link, image or raw HTML.
        Markdown::withSecuredEncoding();

        // M4: <script @nonce> prints this request's CSP nonce attribute.
        Blade::directive('nonce', fn () => '<?php echo \'nonce="\'.e(\App\Support\Csp::nonce()).\'"\'; ?>');

        // H1: in production every generated URL — route(), url(), asset(), signed
        // receipt links, the links in emails — is rooted at APP_URL, never at the
        // request's Host header. This provider used to live outside app/, where it
        // could not be autoloaded, and Laravel silently skips a provider listed in
        // bootstrap/providers.php whose class does not exist; so none of this ran.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
            URL::forceRootUrl(AppUrl::root());
        }
    }
}
