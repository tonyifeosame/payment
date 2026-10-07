<?php

namespace App\Http\Middleware;

use App\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers on every response (M4).
 *
 * The policy is written against what the application actually loads:
 *
 *   scripts   inline only, each carrying this request's nonce (@nonce); no
 *             external script is used anywhere, so nothing else may run.
 *   styles    the compiled stylesheet ('self') plus inline style attributes,
 *             which a few views and scripts use; style injection cannot run code.
 *   fonts     self-hosted.
 *   images    same origin (logos, icons), data: and blob: (previews, the PDF
 *             brand mark).
 *   connect   the bank lookups and student search are same-origin fetches.
 *   forms     same origin, plus Paystack's hosted checkout: the checkout form
 *             posts here and is answered with a redirect to
 *             checkout.paystack.com, and browsers apply form-action to that
 *             redirect. Without it no parent could reach payment.
 *   framing   none: no page of this application is meant to be embedded, which
 *             also stops clickjacking of the admin forms.
 *
 * HSTS is sent only on HTTPS responses in production. It deliberately omits
 * includeSubDomains and preload: the production domain's other subdomains are
 * not known here, and preload is a commitment to make on purpose.
 */
class SecurityHeaders
{
    /** Where Paystack sends a payer to pay; the only third-party form target. */
    public const PAYSTACK_CHECKOUT = 'https://checkout.paystack.com';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('Content-Security-Policy', $this->policy(), false);
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (app()->environment('production') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function policy(): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-".Csp::nonce()."'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self' ".self::PAYSTACK_CHECKOUT,
            "frame-ancestors 'none'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
        ];

        if (app()->environment('production')) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
