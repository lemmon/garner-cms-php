<?php

declare(strict_types=1);

namespace Garner\Core;

/**
 * Stateless CSRF protection for form POSTs, on by default (config:
 * app.csrf.check_origin). A POST carrying a form content type — the three
 * types a cross-site HTML form can send without a CORS preflight — is
 * rejected when the browser-declared origin does not match the request's own
 * origin. Sec-Fetch-Site is consulted first: browsers compute it themselves,
 * it cannot be forged from a page, and it is scheme-independent, so it stays
 * correct behind proxies that hide the original protocol. The Origin header
 * is the fallback for browsers that don't send Sec-Fetch-Site. Requests
 * carrying neither header come from non-browser clients (curl, webhook
 * deliveries), which hold no victim's ambient credentials and pass.
 * Token-based CSRF can complement this once sessions exist.
 */
final class OriginCheck
{
    private const array FORM_CONTENT_TYPES = [
        'application/x-www-form-urlencoded',
        'multipart/form-data',
        'text/plain',
    ];

    public static function rejects(Request $request): bool
    {
        if ($request->method() !== 'POST' || !self::isFormSubmission($request)) {
            return false;
        }

        $fetchSite = $request->header('Sec-Fetch-Site');

        if ($fetchSite !== null) {
            // "same-site" is deliberately not enough: a sibling subdomain is
            // a different origin, matching the Origin comparison below.
            return !in_array(strtolower($fetchSite), ['same-origin', 'none'], true);
        }

        $origin = $request->header('Origin');

        if ($origin !== null) {
            // Also rejects the literal "null" origin (sandboxed iframes,
            // data: URLs) — it never equals a real origin.
            return !self::sameOrigin($origin, $request);
        }

        return false;
    }

    private static function sameOrigin(string $origin, Request $request): bool
    {
        $origin = strtolower(trim($origin));
        $base = strtolower($request->origin());

        if ($origin === $base) {
            return true;
        }

        // A TLS-terminating proxy that doesn't forward the protocol leaves PHP
        // seeing http while the browser saw https. Accept the https spelling
        // of the same host — abusing it would require serving attacker content
        // from the site's own https origin. The reverse (an http origin
        // posting to an https base) stays rejected.
        return (
            str_starts_with($origin, 'https://')
            && str_starts_with($base, 'http://')
            && substr($origin, 8) === substr($base, 7)
        );
    }

    private static function isFormSubmission(Request $request): bool
    {
        $contentType = $request->header('Content-Type');

        if ($contentType === null) {
            return false;
        }

        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return in_array($mediaType, self::FORM_CONTENT_TYPES, true);
    }
}
