<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Core\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Page actions: +action.php POST dispatch, failure re-render, Post/Redirect/Get,
 * 405 + Allow for unhandled verbs, HEAD-routes-like-GET, and the always-defined
 * `form` template variable.
 */
final class ActionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-action-test-' . bin2hex(random_bytes(6));
        $this->writeTemplates();
        $this->writeEntry('', [
            'template' => 'default',
            'created' => '2026-07-05',
            'title' => 'Home',
        ]);
        $this->writeEntry('subscribe', [
            'template' => 'default',
            'created' => '2026-07-05',
            'title' => 'Subscribe',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSuccessfulActionRedirectsWith303(): void
    {
        $this->writeSubscribeAction();

        $response = $this->respond($this->formPost('/subscribe', ['email' => 'a@example.test']));

        self::assertSame(303, $response->status());
        self::assertSame('/subscribe/thanks', $response->location());
    }

    public function testActionRedirectAnswersHtmxWithHxRedirect(): void
    {
        // htmx would follow the 303 inside its XHR and swap the target page
        // into the form's hx-target; the framework translates an action
        // redirect into HX-Redirect so the whole page navigates.
        $this->writeFile('routes/subscribe/+action.php', <<<'PHP'
            <?php

            use Garner\Render\ActionResult;

            return static fn(): ActionResult => ActionResult::redirect('/subscribe/thanks');
            PHP);

        $response = $this->respond($this->formPost(
            '/subscribe',
            ['email' => 'a@example.test'],
            ['HTTP_HX_REQUEST' => 'true'],
        ));

        self::assertSame(204, $response->status());
        self::assertSame('/subscribe/thanks', $response->header('HX-Redirect'));
        self::assertNull($response->location());
        self::assertSame('', $response->body());
    }

    public function testFailureReRendersPageWithFormDataAnd422(): void
    {
        $this->writeSubscribeAction();
        // Read-side context must be rebuilt for the failure re-render.
        $this->writeFile(
            'routes/subscribe/+controller.php',
            '<?php return static fn(): array => ["extra" => "READ-SIDE"];',
        );

        $response = $this->respond($this->formPost('/subscribe', ['email' => 'not-an-email']));

        self::assertSame(422, $response->status());
        self::assertStringContainsString(
            'FORM:Enter a valid email.|not-an-email',
            $response->body(),
        );
        self::assertStringContainsString('READ-SIDE', $response->body());
    }

    public function testInvalidSugarBuildsFormFromLemmonValidatorResult(): void
    {
        $this->writeEntry('newsletter', [
            'template' => 'default',
            'created' => '2026-07-05',
            'title' => 'Newsletter',
        ]);
        $this->writeFile(
            'routes/newsletter/+template.twig',
            '{% if form is not null %}'
            . 'CODE:{{ form.errors[0].code }}|'
            . 'MESSAGE:{{ form.errors[0].message }}|'
            . 'VALUE:{{ form.values.email }}'
            . '{% else %}NOFORM{% endif %}',
        );
        $this->writeFile('routes/newsletter/+action.php', <<<'PHP'
            <?php

            use Garner\Core\Request;
            use Garner\Render\ActionResult;
            use Lemmon\Validator\Validator;

            return static function (Request $request): ActionResult {
                $email = trim((string) ($request->form()['email'] ?? ''));
                [$valid, $data, $errors] = Validator::isString()
                    ->required()
                    ->email()
                    ->tryValidate($email);

                if (!$valid) {
                    return ActionResult::invalid($errors, values: ['email' => $email]);
                }

                return ActionResult::redirect('/newsletter/thanks');
            };
            PHP);

        $response = $this->respond($this->formPost('/newsletter', ['email' => 'not-an-email']));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('CODE:EMAIL', $response->body());
        self::assertStringContainsString(
            'MESSAGE:Value must be a valid email address',
            $response->body(),
        );
        self::assertStringContainsString('VALUE:not-an-email', $response->body());
    }

    public function testFailureReRenderPresentsGetToMethodBranchingControllers(): void
    {
        $this->writeSubscribeAction();
        // A controller is only ever dispatched for GET/HEAD (see
        // respondForPage()) — the failure re-render's internal dispatch must
        // uphold that invariant too, presenting a true GET even though the
        // real request was the POST the action already handled, so context
        // built from form()/body() cannot react to the submission and a
        // method check can never see anything but GET.
        $this->writeFile('routes/subscribe/+controller.php', <<<'PHP'
            <?php

            use Garner\Content\Page;
            use Garner\Content\Site;
            use Garner\Core\Application;
            use Garner\Render\RenderedResponse;

            return static function (Page $page, Site $site, Application $app): array|RenderedResponse {
                $request = $app->request();

                if ($request->method() === 'POST') {
                    return RenderedResponse::json(['hijacked' => true]);
                }

                return [
                    'extra' => sprintf(
                        'GET-CONTEXT fields:%d body:%d',
                        count($request->form()),
                        strlen($request->body()),
                    ),
                ];
            };
            PHP);

        $response = $this->respond($this->formPost('/subscribe', ['email' => 'not-an-email']));

        self::assertSame(422, $response->status());
        self::assertStringContainsString(
            'FORM:Enter a valid email.|not-an-email',
            $response->body(),
        );
        self::assertStringContainsString('GET-CONTEXT fields:0 body:0', $response->body());
        self::assertStringNotContainsString('hijacked', $response->body());
    }

    public function testFailureWithFragmentAnswersHtmxWithJustTheBlock(): void
    {
        $this->writeSubscribeActionWithFragment();
        // The fragment renders with the same rebuilt read-side context as a
        // full failure re-render.
        $this->writeFile(
            'routes/subscribe/+controller.php',
            '<?php return static fn(): array => ["extra" => "READ-SIDE"];',
        );

        $response = $this->respond($this->formPost(
            '/subscribe',
            ['email' => 'not-an-email'],
            ['HTTP_HX_REQUEST' => 'true'],
        ));

        self::assertSame(422, $response->status());
        self::assertSame(
            'FRAGMENT[Enter a valid email.|not-an-email:READ-SIDE]',
            $response->body(),
        );
    }

    public function testFailureWithFragmentStillReRendersFullPageForPlainForms(): void
    {
        $this->writeSubscribeActionWithFragment();

        $response = $this->respond($this->formPost('/subscribe', ['email' => 'not-an-email']));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('<h1>Subscribe</h1>', $response->body());
        self::assertStringContainsString(
            'FORM:Enter a valid email.|not-an-email',
            $response->body(),
        );
    }

    public function testFailureWithoutFragmentReRendersFullPageEvenForHtmx(): void
    {
        $this->writeSubscribeAction();

        $response = $this->respond($this->formPost(
            '/subscribe',
            ['email' => 'not-an-email'],
            ['HTTP_HX_REQUEST' => 'true'],
        ));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('<h1>Subscribe</h1>', $response->body());
    }

    public function testFailureWithUnknownFragmentThrows(): void
    {
        $this->writeSubscribeActionWithFragment('no_such_block');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no block "no_such_block"');
        $this->respond($this->formPost(
            '/subscribe',
            ['email' => 'not-an-email'],
            ['HTTP_HX_REQUEST' => 'true'],
        ));
    }

    public function testControllerCannotOverrideTheNullFormOnGet(): void
    {
        $this->writeSubscribeAction();
        $this->writeFile(
            'routes/subscribe/+controller.php',
            '<?php return static fn(): array => ["form" => ["error" => "bogus"]];',
        );

        $response = $this->respond(Request::create('http://localhost/subscribe'), '/subscribe');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('NOFORM', $response->body());
    }

    public function testFormIsNullOnPlainGet(): void
    {
        $this->writeSubscribeAction();

        $response = $this->respond(Request::create('http://localhost/subscribe'), '/subscribe');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('NOFORM', $response->body());
    }

    public function testHeadRoutesLikeGet(): void
    {
        $this->writeSubscribeAction();

        $response = $this->respond(
            Request::create('http://localhost/subscribe', 'HEAD'),
            '/subscribe',
        );

        self::assertSame(200, $response->status());
    }

    public function testPostWithoutActionIs405WithAllow(): void
    {
        $response = $this->respond($this->formPost('/subscribe', ['email' => 'a@example.test']));

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->header('Allow'));
        self::assertStringContainsString('Method Not Allowed', $response->body());
    }

    public function testOtherVerbsAre405WithPostInAllowWhenActionExists(): void
    {
        $this->writeSubscribeAction();

        $response = $this->respond(
            Request::create('http://localhost/subscribe', 'PUT'),
            '/subscribe',
        );

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD, POST', $response->header('Allow'));
    }

    public function testPostWithoutActionIs405EvenWhenTheControllerBranchesOnMethod(): void
    {
        // A controller is GET/HEAD only, period — it is never dispatched for
        // POST, so it gets no chance to "rescue" an unhandled verb even if
        // it explicitly checks for one. Only +action.php can answer POST.
        $this->writeFile('routes/subscribe/+controller.php', <<<'PHP'
            <?php

            use Garner\Content\Page;
            use Garner\Content\Site;
            use Garner\Core\Application;
            use Garner\Render\RenderedResponse;

            return static function (Page $page, Site $site, Application $app): array|RenderedResponse {
                if ($app->request()->method() === 'POST') {
                    return RenderedResponse::json(['handled' => 'by-controller']);
                }

                return [];
            };
            PHP);

        $response = $this->respond($this->formPost('/subscribe', ['email' => 'a@example.test']));

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->header('Allow'));
        self::assertStringNotContainsString('by-controller', $response->body());
    }

    public function testControllerOnlyEndpointAnswersGet(): void
    {
        $this->writeFile('routes/api/+controller.php', <<<'PHP'
            <?php

            use Garner\Content\Page;
            use Garner\Content\Site;
            use Garner\Core\Application;
            use Garner\Render\RenderedResponse;

            return static fn(Page $page, Site $site, Application $app): RenderedResponse
                => RenderedResponse::json(['method' => $app->request()->method()]);
            PHP);

        $response = $this->respond(Request::create('http://localhost/api'), '/api');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('GET', $response->body());
    }

    public function testControllerOnlyEndpointOtherVerbsAre405WithGetHeadInAllow(): void
    {
        // No +action.php, so a controller-only endpoint answers exactly
        // GET/HEAD — no more "full method freedom": a controller is a GET
        // pre-processor, never a router for arbitrary verbs.
        $this->writeFile('routes/api/+controller.php', <<<'PHP'
            <?php

            use Garner\Content\Page;
            use Garner\Content\Site;
            use Garner\Core\Application;
            use Garner\Render\RenderedResponse;

            return static fn(Page $page, Site $site, Application $app): RenderedResponse
                => RenderedResponse::json(['method' => $app->request()->method()]);
            PHP);

        $delete = $this->respond(Request::create('http://localhost/api', 'DELETE'), '/api');
        $post = $this->respond(Request::create('http://localhost/api', 'POST'), '/api');

        self::assertSame(405, $delete->status());
        self::assertSame('GET, HEAD', $delete->header('Allow'));
        self::assertSame(405, $post->status());
        self::assertSame('GET, HEAD', $post->header('Allow'));
    }

    public function testControllerOnlyEndpointMustReturnARenderedResponse(): void
    {
        $this->writeFile(
            'routes/api/+controller.php',
            '<?php return static fn(): array => ["method" => "GET"];',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a RenderedResponse');
        $this->respond(Request::create('http://localhost/api'), '/api');
    }

    /**
     * An endpoint (no +page.json) with a co-located +template.twig is not
     * templateless — resolveTemplate() picks up +template.twig regardless of
     * isEndpoint(), so an array result from the controller must render into
     * it exactly like a tree page would, not be forced into a
     * RenderedResponse just because the directory has no +page.json.
     */
    public function testControllerOnlyEndpointWithATemplateRendersAnArrayResultIntoIt(): void
    {
        $this->writeFile(
            'routes/api/+controller.php',
            '<?php return static fn(): array => ["message" => "hello from controller"];',
        );
        $this->writeFile('routes/api/+template.twig', 'Custom: {{ message }}');

        $response = $this->respond(Request::create('http://localhost/api'), '/api');

        self::assertSame(200, $response->status());
        self::assertSame('Custom: hello from controller', $response->body());
    }

    public function testActionMayReturnAFullResponseForHtmx(): void
    {
        $this->writeSubscribeAction();

        $response = $this->respond($this->formPost(
            '/subscribe',
            ['email' => 'a@example.test'],
            ['HTTP_HX_REQUEST' => 'true'],
        ));

        self::assertSame(200, $response->status());
        self::assertSame('<li>subscribed</li>', $response->body());
    }

    public function testActionMustReturnActionResultOrResponse(): void
    {
        $this->writeFile(
            'routes/subscribe/+action.php',
            '<?php return static fn(): string => "nope";',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return an ActionResult or RenderedResponse');
        $this->respond($this->formPost('/subscribe', ['email' => 'a@example.test']));
    }

    public function testActionOnlyEndpointDispatchesOnPost(): void
    {
        $this->writeFile('routes/notify/+action.php', <<<'PHP'
            <?php

            use Garner\Core\Request;
            use Garner\Render\RenderedResponse;

            return static fn(Request $request): RenderedResponse
                => RenderedResponse::json(['email' => $request->form()['email'] ?? null]);
            PHP);

        $response = $this->respond($this->formPost('/notify', ['email' => 'a@example.test']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('a@example.test', $response->body());
    }

    public function testActionOnlyEndpointRedirectsOnPost(): void
    {
        $this->writeFile('routes/notify/+action.php', <<<'PHP'
            <?php

            use Garner\Render\ActionResult;

            return static fn(): ActionResult => ActionResult::redirect('/subscribe/thanks');
            PHP);

        $response = $this->respond($this->formPost('/notify', ['email' => 'a@example.test']));

        self::assertSame(303, $response->status());
        self::assertSame('/subscribe/thanks', $response->location());
    }

    public function testActionOnlyEndpointGetIs405WithPostOnlyAllow(): void
    {
        $this->writeFile(
            'routes/notify/+action.php',
            '<?php return static fn() => \Garner\Render\RenderedResponse::json([]);',
        );

        $response = $this->respond(Request::create('http://localhost/notify'), '/notify');

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->header('Allow'));
    }

    public function testActionOnlyEndpointFailureThrowsBecauseThereIsNoPageToReRender(): void
    {
        $this->writeFile('routes/notify/+action.php', <<<'PHP'
            <?php

            use Garner\Render\ActionResult;

            return static fn(): ActionResult => ActionResult::failure(['error' => 'nope']);
            PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Action-only route "/notify" has no page to re-render');
        $this->respond($this->formPost('/notify', ['email' => 'a@example.test']));
    }

    /**
     * A +template.twig co-located with a controllerless +action.php exists
     * only to give failure()/invalid() something to re-render as this POST's
     * response body — it does not thereby make the route GET-navigable.
     * GET/HEAD dispatch is gated on controllerFile() alone (see
     * answersGet()), deliberately unaffected by templateFile(): if the site
     * wants a GET response too, it adds a +controller.php, an explicit
     * decision rather than an incidental one from adding a template.
     */
    public function testActionOnlyEndpointWithATemplateStillRejectsGet(): void
    {
        $this->writeFile(
            'routes/notify/+action.php',
            '<?php return static fn() => \Garner\Render\ActionResult::failure(["error" => "nope"]);',
        );
        $this->writeFile('routes/notify/+template.twig', 'Error: {{ form.error }}');

        $response = $this->respond(Request::create('http://localhost/notify'), '/notify');

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->header('Allow'));
    }

    /**
     * Same shape as above, but exercising the actual point of the template:
     * a POST failure() re-renders it with `form` populated instead of
     * throwing "no page to re-render" — the template is reachable, just only
     * as this POST's own response.
     */
    public function testActionOnlyEndpointWithATemplateRendersFailureIntoIt(): void
    {
        $this->writeFile(
            'routes/notify/+action.php',
            '<?php return static fn() => \Garner\Render\ActionResult::failure(["error" => "nope"]);',
        );
        $this->writeFile('routes/notify/+template.twig', 'Error: {{ form.error }}');

        $response = $this->respond($this->formPost('/notify', ['email' => 'a@example.test']));

        self::assertSame(422, $response->status());
        self::assertSame('Error: nope', $response->body());
    }

    public function testEndpointWithControllerAndActionAnswersGetViaControllerAndRejectsOtherVerbs(): void
    {
        // GET is the controller's, exactly like any other route; anything
        // beyond GET/HEAD/POST is 405 even though a controller is present —
        // it does not become a free-form router just because it's an
        // endpoint.
        $this->writeFile('routes/api/+controller.php', <<<'PHP'
            <?php

            use Garner\Content\Page;
            use Garner\Content\Site;
            use Garner\Core\Application;
            use Garner\Render\RenderedResponse;

            return static fn(Page $page, Site $site, Application $app): RenderedResponse
                => RenderedResponse::json(['method' => $app->request()->method()]);
            PHP);
        $this->writeFile(
            'routes/api/+action.php',
            '<?php return static fn() => \Garner\Render\RenderedResponse::json(["via" => "action"]);',
        );

        $get = $this->respond(Request::create('http://localhost/api'), '/api');
        $delete = $this->respond(Request::create('http://localhost/api', 'DELETE'), '/api');

        self::assertSame(200, $get->status());
        self::assertStringContainsString('GET', $get->body());
        self::assertSame(405, $delete->status());
        self::assertSame('GET, HEAD, POST', $delete->header('Allow'));
    }

    public function testEndpointWithControllerAndActionGivesPostToTheAction(): void
    {
        // POST is the action's exclusively, mirroring a page-tree route —
        // the controller never sees it, even though it's present.
        $this->writeFile(
            'routes/api/+controller.php',
            '<?php return static fn() => \Garner\Render\RenderedResponse::json(["via" => "controller"]);',
        );
        $this->writeFile('routes/api/+action.php', <<<'PHP'
            <?php

            use Garner\Core\Request;
            use Garner\Render\RenderedResponse;

            return static fn(Request $request): RenderedResponse
                => RenderedResponse::json(['email' => $request->form()['email'] ?? null]);
            PHP);

        $response = $this->respond($this->formPost('/api', ['email' => 'a@example.test']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('a@example.test', $response->body());
        self::assertStringNotContainsString('via', $response->body());
    }

    public function testEndpointWithControllerAndActionFailureThrowsBecauseThereIsNoPageToReRender(): void
    {
        $this->writeFile(
            'routes/api/+controller.php',
            '<?php return static fn() => \Garner\Render\RenderedResponse::json(["via" => "controller"]);',
        );
        $this->writeFile('routes/api/+action.php', <<<'PHP'
            <?php

            use Garner\Render\ActionResult;

            return static fn(): ActionResult => ActionResult::failure(['error' => 'nope']);
            PHP);

        // Distinct from the action-only case above: this endpoint does have a
        // controller, so the message must not call it "Action-only route" —
        // that would misdirect debugging toward "add a controller" when the
        // real fix is in the action's return value.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Endpoint "/api" has no page to re-render');
        $this->respond($this->formPost('/api', ['email' => 'a@example.test']));
    }

    /**
     * The prototype action shape: validate, fail with errors + values, answer
     * htmx with a fragment, otherwise redirect (Post/Redirect/Get).
     */
    private function writeSubscribeAction(): void
    {
        $this->writeFile('routes/subscribe/+action.php', <<<'PHP'
            <?php

            use Garner\Content\Page;
            use Garner\Content\Site;
            use Garner\Core\Application;
            use Garner\Core\Request;
            use Garner\Render\ActionResult;
            use Garner\Render\RenderedResponse;

            return static function (
                Request $request,
                Page $page,
                Site $site,
                Application $app,
            ): ActionResult|RenderedResponse {
                $email = trim((string) ($request->form()['email'] ?? ''));

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return ActionResult::failure([
                        'errors' => ['email' => 'Enter a valid email.'],
                        'values' => ['email' => $email],
                    ]);
                }

                if ($request->isHtmx()) {
                    return RenderedResponse::html('<li>subscribed</li>');
                }

                return ActionResult::redirect($page->path() . '/thanks');
            };
            PHP);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $server
     */
    private function formPost(string $path, array $fields, array $server = []): Request
    {
        return Request::create('http://localhost' . $path, 'POST', $server, $fields);
    }

    private function respond(
        Request $request,
        ?string $path = null,
    ): \Garner\Render\RenderedResponse {
        $app = new Application(
            $this->root,
            $this->root,
            [
                'app' => ['debug' => true, 'name' => 'Action Test'],
            ],
            $request,
        );

        return $app->publicSite()->respond($path ?? $request->path());
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeEntry(string $route, array $meta): void
    {
        $directory = $route === '' ? 'routes' : 'routes/' . $route;
        $json = json_encode($meta, JSON_PRETTY_PRINT);
        $this->writeFile($directory . '/+page.json', $json !== false ? $json : '{}');
    }

    private function writeTemplates(): void
    {
        $this->writeFile(
            'app/templates/default.twig',
            "<h1>{{ page.title }}</h1>\n"
            . '{% if form is not null %}FORM:{{ form.errors.email }}|{{ form.values.email }}'
            . '{% else %}NOFORM{% endif %}'
            . "\n{{ extra ?? '' }}\n"
            . '{% block subscribe_form %}FRAGMENT['
            . '{% if form is not null %}{{ form.errors.email }}|{{ form.values.email }}{% endif %}'
            . ":{{ extra ?? '' }}]{% endblock %}",
        );
    }

    /**
     * An action whose failure names a template fragment for htmx.
     */
    private function writeSubscribeActionWithFragment(string $fragment = 'subscribe_form'): void
    {
        $this->writeFile('routes/subscribe/+action.php', <<<PHP
            <?php

            use Garner\Core\Request;
            use Garner\Render\ActionResult;

            return static fn(Request \$request): ActionResult => ActionResult::failure([
                'errors' => ['email' => 'Enter a valid email.'],
                'values' => ['email' => (string) (\$request->form()['email'] ?? '')],
            ], fragment: '{$fragment}');
            PHP);
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
