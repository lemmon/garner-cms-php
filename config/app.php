<?php

declare(strict_types=1);

use Garner\Support\Env;
use Garner\Support\IdGeneratorType;

$debug = (static function (): bool {
    $configured = Env::get('APP_DEBUG');

    if ($configured !== null) {
        return filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    $host = (string) ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '');
    $normalizedHost = strtolower(trim(explode(':', $host)[0]));

    return in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true);
})();

return [
    'name' => 'Garner',
    'environment' => Env::get('APP_ENV') ?? ($debug ? 'development' : 'production'),
    'debug' => $debug,
    // Site base URL (scheme://host, no trailing slash) exposed as `site.url`. When
    // null it is inferred from each request; pin it (or set APP_URL) for a canonical
    // origin in CLI builds, sitemaps, and absolute links.
    'url' => Env::get('APP_URL'),
    'paths' => [
        'app' => 'app',
        'routes' => 'routes',
        'runtime' => 'runtime',
        'storage' => 'storage',
        'public' => 'public',
    ],
    'csrf' => [
        // Reject cross-site form POSTs (mismatched Origin / Sec-Fetch-Site)
        // with a 403. Stateless — no sessions or tokens involved. Disable only
        // for sites that must accept form-encoded POSTs from other origins
        // (e.g. third-party webhook deliveries that send Origin headers).
        'check_origin' => true,
    ],
    'session' => [
        // Persistence for Session data. null uses the built-in FileSessionStore
        // (one file per session under `path`, PHP-serialized). Pass a
        // SessionStore instance, a class-string, or a callable returning one
        // for a custom backend (e.g. Redis) — same shape as `ids.generator`.
        'store' => null,
        // Where FileSessionStore keeps session files. null defaults to
        // storage/sessions. Relative paths resolve against the project root.
        'path' => null,
        'cookie' => 'garner_session',
        // Seconds a session (and its cookie) stays alive after its last write.
        'lifetime' => 7200,
    ],
    'markdown' => [
        'allow_unsafe_links' => false,
        'html_input' => 'strip',
    ],
    'ids' => [
        // Generator for scaffolded ids. Use an IdGeneratorType case for a built-in,
        // or an IdGenerator instance / callable / class-string for a custom one.
        // (Any unique string is a valid id regardless of generator.) Session ids
        // are unaffected — they always come from a dedicated CSPRNG generator.
        'generator' => IdGeneratorType::Cuid2,
    ],
    'rendering' => [
        'default_template' => 'default',
        'engine' => 'twig',
    ],
    'index' => [
        // Derived SQLite route index freshness policy.
        // null inherits app.debug: "scan" for changes in development,
        // "locked" cached index in production. Override with "scan" or "locked".
        'mode' => null,
    ],
    'twig' => [
        'cache' => null,
        'debug' => null,
        'auto_reload' => null,
        'strict_variables' => false,
    ],
];
