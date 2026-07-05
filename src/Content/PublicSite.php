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
        $page = $this->pages->find($canonical);

        // Trailing-slash (and extra leading-slash) spellings of a routable path
        // redirect permanently to the canonical form instead of serving the same
        // content at many URLs. Non-routable paths fall through to a plain 404 —
        // which also keeps drafts from being revealed through a redirect.
        if ($page !== null && $canonical !== $path) {
            return RenderedResponse::redirect(
                $basePath . $canonical . ($query === '' ? '' : '?' . $query),
            );
        }

        $site = $this->siteLoader->load($this->pages);

        if ($page === null) {
            return RenderedResponse::html($this->renderer->renderNotFound($site, $path), 404);
        }

        // Route endpoints keep full method freedom: their controller answers
        // every verb itself. Tree pages are method-aware — HEAD routes like
        // GET, POST goes to the page's +action.php, and everything else must
        // come from a controller-returned response or it is a 405.
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
     * failure data exposed to the template as `form`; a redirect answers
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

        return RenderedResponse::html($this->renderer->renderPage($page, $site, [
            ...$context,
            'form' => $result->data(),
        ]), $result->status());
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
