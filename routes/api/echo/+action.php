<?php

declare(strict_types=1);

use Garner\Core\Request;
use Garner\Render\RenderedResponse;

// Action-only endpoint: +action.php with neither a +page.json nor a
// +controller.php alongside it — POST-only, GET/HEAD (and every other verb)
// get 405. There is no page here to re-render a failure into, so unlike a
// page's own +action.php this may only return a RenderedResponse or
// ActionResult::redirect() — failure()/invalid() would throw a
// RuntimeException instead.
return static function (Request $request): RenderedResponse {
    // form() reads urlencoded/multipart bodies; json() reads a JSON body —
    // they're mutually exclusive per request, so try json() and fall back to
    // an empty array rather than 500ing when the body isn't JSON at all
    // (e.g. a plain form post, whose raw body is never valid JSON).
    try {
        $json = $request->json();
    } catch (JsonException) {
        $json = [];
    }

    return RenderedResponse::json([
        'form' => $request->form(),
        'json' => $json,
        'now' => gmdate('c'),
    ]);
};
