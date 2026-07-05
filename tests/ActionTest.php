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

    public function testFailureReRenderPresentsGetToMethodBranchingControllers(): void
    {
        $this->writeSubscribeAction();
        // A controller that still branches on the method (pre-action POST
        // handling) must not hijack or starve the failure re-render: it sees
        // the re-render as a true GET — no POST payload either, so context
        // built from form()/body() cannot react to the handled submission.
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

    public function testControllerReturnedResponseStillAnswersPostWithoutAction(): void
    {
        // Pre-action compatibility: a page controller may branch on the method
        // and take over the POST with a full response.
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

        self::assertSame(200, $response->status());
        self::assertStringContainsString('by-controller', $response->body());
    }

    public function testEndpointKeepsFullMethodFreedom(): void
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

        $response = $this->respond(Request::create('http://localhost/api', 'DELETE'), '/api');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('DELETE', $response->body());
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
            . "\n{{ extra ?? '' }}",
        );
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
