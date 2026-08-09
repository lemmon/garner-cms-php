<?php

declare(strict_types=1);

namespace Garner\Content;

use Garner\Core\Application;
use Garner\Render\PageActions;
use Garner\Render\PageControllers;
use Garner\Render\RenderedResponse;
use Garner\Render\RendererInterface;

final class PublicSite
{
    /**
     * Cache key prefix for a one-time, CLI-issued preview token (see
     * `garner page:preview --open`) — shared with PagePreviewCommand, which
     * writes the matching entry. Never written to `+page.json`: the token
     * lives only in the disposable application cache, so it can't be
     * redeemed anywhere but the machine that generated it.
     */
    public const EPHEMERAL_PREVIEW_CACHE_PREFIX = 'draft-preview:';

    public function __construct(
        private readonly Application $app,
        private readonly Pages $pages,
        private readonly SiteLoader $siteLoader,
        private readonly PageControllers $controllers,
        private readonly PageActions $actions,
        private readonly RendererInterface $renderer,
    ) {}

    /**
     * @param string $query Raw query string of the request (no "?"), preserved on
     *        canonical redirects. Controller-returned redirects are emitted verbatim.
     * @param string $basePath Front-controller base path stripped from $path (e.g.
     *        "/blog"), re-attached to canonical redirects so they stay inside the app.
     */
    public function respond(
        string $path,
        string $query = '',
        string $basePath = '',
    ): RenderedResponse {
        $canonical = RoutePath::normalize($path);
        $isCanonicalRequest = $canonical === $path;
        $page = $this->pages->find($canonical);
        $preview = false;

        if ($page === null) {
            // A non-canonical request (e.g. a trailing slash) only needs to
            // know whether the token *would* grant access, to decide whether
            // to redirect — consuming it here would burn a one-time token
            // before the canonical request it redirects to ever arrives.
            $page = $this->resolvePreview($canonical, consume: $isCanonicalRequest);
            $preview = $page !== null;
        }

        // Trailing-slash (and extra leading-slash) spellings of a routable path
        // redirect permanently to the canonical form instead of serving the same
        // content at many URLs. Non-routable paths fall through to a plain 404 —
        // which also keeps drafts from being revealed through a redirect. A
        // preview-granted page redirects the same way; $query (re-attached
        // verbatim) still carries its `?preview=` value along.
        if ($page !== null && $canonical !== $path) {
            return RenderedResponse::redirect(
                $basePath . $canonical . ($query === '' ? '' : '?' . $query),
            );
        }

        $site = $this->siteLoader->load($this->pages);

        if ($page === null) {
            return RenderedResponse::html($this->renderer->renderNotFound($site, $path), 404);
        }

        $response = $this->respondForPage($page, $site, $path);

        if (!$preview) {
            return $response;
        }

        // A preview link is meant to stay out of search results even if the
        // URL is ever discovered — not a substitute for the public 404, just
        // insurance on top of it. no-store keeps a shared/browser cache from
        // replaying the draft after the token is spent or draft_preview is
        // cleared — the "stops working the moment it's used" promise would
        // otherwise only hold at the origin, not for anything that cached
        // the response along the way. Merged rather than replaced: the
        // page's own controller/action may already have set either header
        // for its own reasons (README shows withHeader('X-Robots-Tag', ...)
        // as a normal pattern), and overwriting would silently drop whatever
        // directives it chose.
        $response = $this->withMergedDirectives($response, 'X-Robots-Tag', 'noindex');

        return $this->withMergedDirectives($response, 'Cache-Control', 'private', 'no-store');
    }

    /**
     * Adds $directives to $header's existing comma-separated value without
     * disturbing whatever is already there and without duplicating a
     * directive that's already present.
     */
    private function withMergedDirectives(
        RenderedResponse $response,
        string $header,
        string ...$directives,
    ): RenderedResponse {
        $existing = $response->header($header);
        $parts =
            $existing !== null && trim($existing) !== ''
                ? array_map(trim(...), explode(',', $existing))
                : [];

        foreach ($directives as $directive) {
            if (in_array($directive, $parts, true)) {
                continue;
            }

            $parts[] = $directive;
        }

        return $response->withHeader($header, implode(', ', $parts));
    }

    /**
     * Grants access to an otherwise-404ing draft page when the request's
     * `?preview=` value matches either that exact page's own `draft_preview`
     * secret (Page::draftPreview()) or a one-time token issued by
     * `garner page:preview --open` — a soft, unlisted-link gate for handing an
     * unpublished page to a client for review or popping it open for a quick
     * local look, not a mechanism for protecting sensitive data. hash_equals()
     * costs nothing extra over `===` here, so there's no reason to skip it
     * even though the stakes are low.
     *
     * Known limitation, accepted rather than fixed (see docs/brainstorming.md):
     * only the canonical-path redirect in respond() re-attaches $query, so a
     * PRG redirect from respondWithAction() or a controller-returned redirect
     * drops `?preview=` — a form submitted on a previewed draft page 404s on
     * its own success redirect.
     *
     * @param bool $consume Whether a matching ephemeral token should actually
     *        be spent. False for a non-canonical request that will redirect
     *        rather than be served — see the $isCanonicalRequest caller.
     */
    private function resolvePreview(string $canonical, bool $consume): ?Page
    {
        $token = $this->app->request()->queryParam('preview');

        if ($token === null || $token === '') {
            return null;
        }

        $page = $this->pages->find($canonical, drafts: true);

        if ($page === null) {
            return null;
        }

        $secret = $page->draftPreview();

        if ($secret !== null && hash_equals($secret, $token)) {
            return $page;
        }

        return $this->matchesEphemeralPreview($canonical, $token, $consume) ? $page : null;
    }

    /**
     * Checks a `--open`-issued token and, on a match, deletes it when
     * $consume is true — one-time use, the same way the CLI command frames
     * it: a quick local look, not a standing credential. $consume is false
     * for the redirect-decision pass so the token survives to the canonical
     * request that's actually served.
     */
    private function matchesEphemeralPreview(
        string $canonical,
        #[\SensitiveParameter]
        string $token,
        bool $consume,
    ): bool {
        $key = self::EPHEMERAL_PREVIEW_CACHE_PREFIX . $canonical;
        $stored = $this->app->cache()->get($key);

        if (!is_string($stored) || !hash_equals($stored, $token)) {
            return false;
        }

        if ($consume) {
            $this->app->cache()->remove($key);
        }

        return true;
    }

    /**
     * Dispatch a resolved page: route endpoints keep full method freedom
     * (their controller answers every verb itself); tree pages are
     * method-aware — GET/HEAD render, POST goes to the page's +action.php,
     * and everything else must come from a controller-returned response or
     * it is a 405.
     */
    private function respondForPage(Page $page, Site $site, string $path): RenderedResponse
    {
        $method = $this->app->request()->method();

        if (!$page->isEndpoint() && $method !== 'GET' && $method !== 'HEAD') {
            if ($method === 'POST' && $page->actionFile() !== null) {
                return $this->respondWithAction($page, $site);
            }

            $result = $this->controllers->dispatch($page, $site, $this->app);

            // A controller may still answer the verb with a full response
            // (pre-action POST branching keeps working). A plain page render
            // is a GET concern, so a context array means the verb is not
            // handled here.
            if ($result instanceof RenderedResponse) {
                return $result;
            }

            return $this->methodNotAllowed($page, $site, $path);
        }

        $result = $this->controllers->dispatch($page, $site, $this->app);

        if ($result instanceof RenderedResponse) {
            return $result;
        }

        // Set after the spread: `form` belongs to the action layer (null
        // outside a failure re-render), so a controller key of the same name
        // cannot break that guarantee.
        return RenderedResponse::html($this->renderer->renderPage($page, $site, [
            ...$result,
            'form' => null,
        ]));
    }

    /**
     * Dispatch the page's +action.php. A failure re-renders the page with the
     * failure data exposed to the template as `form` (for htmx, optionally
     * just a named template fragment of it); a redirect answers
     * Post/Redirect/Get; a full RenderedResponse passes through verbatim.
     */
    private function respondWithAction(Page $page, Site $site): RenderedResponse
    {
        $result = $this->actions->dispatch($page, $site, $this->app);

        if ($result instanceof RenderedResponse) {
            return $result;
        }

        $location = $result->location();

        if ($location !== null) {
            // htmx follows a 3xx inside its XHR and would swap the redirect
            // target into the form's hx-target; HX-Redirect tells it to
            // navigate the whole page instead — which is what an action
            // redirect (Post/Redirect/Get) means.
            if ($this->app->request()->isHtmx()) {
                return RenderedResponse::html('', 204)->withHeader('HX-Redirect', $location);
            }

            return RenderedResponse::redirect($location, $result->status());
        }

        // Failure: rebuild the read-side render context, then let the action's
        // data win the `form` key. Controllers see the request as a GET, so
        // the re-render behaves exactly like the page's GET render — a
        // controller branching on the method (pre-action POST handling) still
        // contributes its normal context instead of reacting to the POST the
        // action already handled. One that answers its GET render with a full
        // response keeps that authority here too.
        $context = $this->app->withRequest(
            $this->app->request()->asGet(),
            fn(): array|RenderedResponse => $this->controllers->dispatch($page, $site, $this->app),
        );

        if ($context instanceof RenderedResponse) {
            return $context;
        }

        $renderContext = [...$context, 'form' => $result->data()];
        $fragment = $result->fragment();

        // An htmx form swaps its hx-target with the response, so a full-page
        // failure re-render is useless to it (worse: htmx ignores 4xx bodies
        // by default, making the submit a silent no-op). A failure carrying a
        // fragment name answers htmx with just that template block — same
        // context, same status — so the form can swap in place. The site opts
        // htmx into swapping 422s via the documented htmx-config meta tag.
        if ($fragment !== null && $this->app->request()->isHtmx()) {
            return RenderedResponse::html(
                $this->renderer->renderPageFragment($page, $site, $fragment, $renderContext),
                $result->status(),
            );
        }

        return RenderedResponse::html(
            $this->renderer->renderPage($page, $site, $renderContext),
            $result->status(),
        );
    }

    private function methodNotAllowed(Page $page, Site $site, string $path): RenderedResponse
    {
        $allow = 'GET, HEAD' . ($page->actionFile() !== null ? ', POST' : '');

        return RenderedResponse::html($this->renderer->renderError(
            $site,
            405,
            'method_not_allowed',
            ['path' => $path],
        ), 405)->withHeader('Allow', $allow);
    }
}
