<?php

declare(strict_types=1);

namespace Garner\Render;

use Garner\Content\Page;
use Garner\Content\Site;
use Garner\Core\Application;
use RuntimeException;

/**
 * Runs a page's co-located +action.php: the write-side POST handler, kept
 * separate from the read-side +controller.php. The file returns a callable
 * with the controller contract plus the request prepended —
 * `(Request, Page, Site, Application)` — and must produce an ActionResult
 * (failure re-render or Post/Redirect/Get) or a full RenderedResponse
 * (JSON, fragments, custom headers).
 */
final class PageActions
{
    public function dispatch(
        Page $page,
        Site $site,
        Application $app,
    ): ActionResult|RenderedResponse {
        $actionFile = $page->actionFile();

        if ($actionFile === null) {
            throw new RuntimeException(sprintf(
                'Page "%s" has no +action.php to dispatch',
                $page->path(),
            ));
        }

        $action = require $actionFile;

        if (!is_callable($action)) {
            throw new RuntimeException(sprintf('Action "%s" must return a callable', $actionFile));
        }

        $result = $action($app->request(), $page, $site, $app);

        if ($result instanceof ActionResult || $result instanceof RenderedResponse) {
            return $result;
        }

        throw new RuntimeException(sprintf(
            'Action "%s" must return an ActionResult or RenderedResponse',
            $actionFile,
        ));
    }
}
