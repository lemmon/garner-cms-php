<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Render\MarkdownRenderer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Event\DocumentPreRenderEvent;
use League\CommonMark\Event\DocumentRenderedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\ConfigurableExtensionInterface;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Output\RenderedContent;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * app.markdown.extensions: how a site's additions reach the CommonMark
 * environment, not what the extensions themselves render.
 */
final class MarkdownRendererTest extends TestCase
{
    private const string FOOTNOTE = "Claim.[^1]\n\n[^1]: Source.";

    public function testDefaultsLeaveFootnoteSyntaxUnparsed(): void
    {
        $html = new MarkdownRenderer()->render(self::FOOTNOTE);

        self::assertStringNotContainsString('class="footnotes"', $html);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function firstParagraphs(): iterable
    {
        yield 'plain paragraphs' => [
            "First **one**\nwrapped.\n\nSecond.",
            "<p>First <strong>one</strong>\nwrapped.</p>",
        ];
        yield 'heading passed over' => ["# Title\n\nBody.", '<p>Body.</p>'];
        yield 'image-only paragraph passed over' => ["![Hero](/h.jpg)\n\nText.", '<p>Text.</p>'];
        yield 'zero-width space only passed over' => ["&#8203;\n\nText.", '<p>Text.</p>'];
        yield 'non-breaking space only passed over' => ["&nbsp;\n\nText.", '<p>Text.</p>'];
        yield 'image and non-breaking space passed over' => [
            "![Hero](/h.jpg)&nbsp;\n\nText.",
            '<p>Text.</p>',
        ];
        yield 'reference link resolved from later in the document' => [
            "See [docs][1].\n\n[1]: /docs",
            '<p>See <a href="/docs">docs</a>.</p>',
        ];
        yield 'code fence with a blank line passed over' => [
            "```\na\n\nb\n```\n\nAfter.",
            '<p>After.</p>',
        ];
        yield 'quote and list paragraphs passed over' => [
            "> quoted\n\n- item\n\nReal.",
            '<p>Real.</p>',
        ];
        yield 'no paragraph' => ['# Only a heading', ''];
        yield 'empty' => [null, ''];
    }

    #[DataProvider('firstParagraphs')]
    public function testFirstParagraph(?string $markdown, string $expected): void
    {
        self::assertSame($expected, new MarkdownRenderer()->firstParagraph($markdown));
    }

    public function testFirstParagraphKeepsTheDocumentDataRenderListenersRead(): void
    {
        $markdown = new MarkdownRenderer([
            'extensions' => [
                static function (EnvironmentBuilderInterface $environment): void {
                    $environment->addEventListener(DocumentParsedEvent::class, static function (DocumentParsedEvent $event): void {
                        $event->getDocument()->data->set('lang', 'cs');
                    });
                    $environment->addEventListener(DocumentRenderedEvent::class, static function (DocumentRenderedEvent $event): void {
                        $output = $event->getOutput();
                        $lang = $output->getDocument()->data->get('lang');
                        $event->replaceOutput(
                            new RenderedContent($output->getDocument(), str_replace(
                                '<p>',
                                '<p lang="' . $lang . '">',
                                $output->getContent(),
                            )),
                        );
                    });
                },
            ],
        ]);

        self::assertSame('<p lang="cs">Ahoj.</p>', $markdown->firstParagraph("# Title\n\nAhoj."));
    }

    public function testFirstParagraphRunsRenderEventsLikeMarkdown(): void
    {
        $markdown = new MarkdownRenderer([
            'extensions' => [
                static function (EnvironmentBuilderInterface $environment): void {
                    $environment->addEventListener(DocumentPreRenderEvent::class, static function (DocumentPreRenderEvent $event): void {
                        foreach ($event->getDocument()->iterator() as $node) {
                            if (!$node instanceof Text) {
                                continue;
                            }

                            $node->setLiteral(strtoupper($node->getLiteral()));
                        }
                    });
                    $environment->addEventListener(DocumentRenderedEvent::class, static function (DocumentRenderedEvent $event): void {
                        $output = $event->getOutput();
                        $event->replaceOutput(
                            new RenderedContent($output->getDocument(), str_replace(
                                '<p>',
                                '<p class="lead">',
                                $output->getContent(),
                            )),
                        );
                    });
                },
            ],
        ]);

        self::assertSame("<p class=\"lead\">SHOUT.</p>\n", $markdown->render('Shout.'));
        self::assertSame(
            '<p class="lead">SHOUT.</p>',
            $markdown->firstParagraph("# Title\n\nShout."),
        );
    }

    public function testExtensionByClassName(): void
    {
        $html = new MarkdownRenderer(['extensions' => [FootnoteExtension::class]])->render(
            self::FOOTNOTE,
        );

        self::assertStringContainsString('class="footnotes"', $html);
    }

    public function testExtensionInstance(): void
    {
        $html = new MarkdownRenderer(['extensions' => [new FootnoteExtension()]])->render(
            self::FOOTNOTE,
        );

        self::assertStringContainsString('class="footnotes"', $html);
    }

    public function testAnExtensionsOwnSettingsPassThrough(): void
    {
        $html = new MarkdownRenderer([
            'extensions' => [FootnoteExtension::class],
            'footnote' => ['container_class' => 'notes'],
        ])->render(self::FOOTNOTE);

        self::assertStringContainsString('class="notes"', $html);
    }

    public function testCallableReceivesTheEnvironment(): void
    {
        $renderer = new class implements NodeRendererInterface {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                return $node instanceof Text ? strtoupper($node->getLiteral()) : '';
            }
        };

        $html = new MarkdownRenderer([
            'extensions' => [
                static function (Environment $environment) use ($renderer): void {
                    $environment->addRenderer(Text::class, $renderer, 10);
                },
            ],
        ])->render('quiet words');

        self::assertSame("<p>QUIET WORDS</p>\n", $html);
    }

    /**
     * A callable registers in its place among the extensions, so a listener
     * it adds runs after the defaults' listeners of the same priority (here
     * the attributes extension, which sets the heading id).
     */
    public function testCallableRegistersInOrderWithTheExtensions(): void
    {
        $seen = null;
        $listener = static function (DocumentParsedEvent $event) use (&$seen): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if (!$node instanceof Heading) {
                    continue;
                }

                $seen = $node->data->get('attributes/id', null);
            }
        };

        new MarkdownRenderer([
            'extensions' => [
                static function (Environment $environment) use ($listener): void {
                    $environment->addEventListener(DocumentParsedEvent::class, $listener);
                },
            ],
        ])->render('# Title {#custom}');

        self::assertSame('custom', $seen);
    }

    public function testDefaultExtensionsStayRegistered(): void
    {
        $html = new MarkdownRenderer(['extensions' => [SmartPunctExtension::class]])->render(
            "~~gone~~ \"quoted\"\n\n# Title {#custom}",
        );

        self::assertStringContainsString('<del>gone</del>', $html);
        self::assertStringContainsString('“quoted”', $html);
        self::assertStringContainsString('<h1 id="custom">Title</h1>', $html);
    }

    public function testInvalidEntryNamesItsPositionAndTheAcceptedForms(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'app.markdown.extensions[1] must be an extension instance or class name '
            . '(implementing League\CommonMark\Extension\ExtensionInterface), or a callable '
            . 'receiving the League\CommonMark\Environment\Environment; got "Not\Installed\Extension"',
        );

        new MarkdownRenderer(['extensions' => [
            FootnoteExtension::class,
            'Not\Installed\Extension',
        ]]);
    }

    public function testExtensionsMustBeAList(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('app.markdown.extensions must be a list, got string');

        new MarkdownRenderer(['extensions' => FootnoteExtension::class]);
    }

    public function testCallableMayReturnAnExtension(): void
    {
        $html = new MarkdownRenderer([
            'extensions' => [static fn(): ExtensionInterface => new FootnoteExtension()],
        ])->render(self::FOOTNOTE);

        self::assertStringContainsString('class="footnotes"', $html);
    }

    public function testCallableReturningSomethingElseIsReported(): void
    {
        $renderer = new MarkdownRenderer(['extensions' => [static fn(): string => 'footnotes']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'app.markdown.extensions[0] could not be registered: the callable must return '
            . 'nothing or an League\CommonMark\Extension\ExtensionInterface; got string',
        );

        $renderer->render('text');
    }

    public function testEntriesGarnerAlreadyRegistersAreSkipped(): void
    {
        $html = new MarkdownRenderer(['extensions' => [
            CommonMarkCoreExtension::class,
            GithubFlavoredMarkdownExtension::class,
            new StrikethroughExtension(),
            FootnoteExtension::class,
        ]])->render("**bold** ~~gone~~\n\n" . self::FOOTNOTE);

        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<del>gone</del>', $html);
        self::assertStringContainsString('class="footnotes"', $html);
    }

    public function testClassNeedingConstructorArgumentsIsRejectedUpFront(): void
    {
        $extension = new class('x') implements ExtensionInterface {
            public function __construct(
                public string $required,
            ) {}

            public function register(EnvironmentBuilderInterface $environment): void {}
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'app.markdown.extensions[0]: %s cannot be constructed without arguments; '
            . 'pass an instance instead',
            $extension::class,
        ));

        new MarkdownRenderer(['extensions' => [$extension::class]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> Config, and
     *         a regex fragment of League's reason.
     */
    public static function invalidSettings(): iterable
    {
        yield 'read only while rendering' => [
            [
                'extensions' => [HeadingPermalinkExtension::class],
                'heading_permalink' => ['symbol' => 123],
            ],
            "heading_permalink\\W+symbol' expects to be string",
        ];

        yield 'misspelled key' => [
            ['extensions' => [FootnoteExtension::class], 'footnotes' => ['container_class' => 'x']],
            'Missing config schema for "footnotes"',
        ];

        yield 'core setting, no extensions' => [
            ['slug_normalizer' => ['max_length' => -1]],
            "slug_normalizer\\W+max_length' expects to be in range",
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreReportedOnTheFirstRender(
        array $config,
        string $reason,
    ): void {
        $renderer = new MarkdownRenderer($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/^app\.markdown has an invalid setting: .*' . $reason . '/u',
        );

        $renderer->render('plain');
    }

    public function testCallableThatInitializesEarlyIsNamed(): void
    {
        $renderer = new MarkdownRenderer(['extensions' => [
            static function (Environment $environment): void {
                iterator_to_array($environment->getBlockStartParsers());
            },
            SmartPunctExtension::class,
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'app.markdown.extensions[0] could not be registered: a callable may add to the '
            . 'Environment but not read from it',
        );

        $renderer->render('text');
    }

    public function testTheSameExtensionTwiceIsReported(): void
    {
        $renderer = new MarkdownRenderer(['extensions' => [
            FootnoteExtension::class,
            static fn(): ExtensionInterface => new FootnoteExtension(),
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'app.markdown.extensions[1] could not be registered: '
            . 'League\CommonMark\Extension\Footnote\FootnoteExtension is already added by '
            . 'app.markdown.extensions[0]',
        );

        $renderer->render('text');
    }

    public function testInterfaceIsRejectedAsNotInstantiable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'app.markdown.extensions[0]: League\CommonMark\Extension\ConfigurableExtensionInterface '
            . 'is not an instantiable class',
        );

        new MarkdownRenderer(['extensions' => [ConfigurableExtensionInterface::class]]);
    }

    public function testFailedBuildIsNotRetried(): void
    {
        $calls = 0;
        $renderer = new MarkdownRenderer(['extensions' => [
            static function () use (&$calls): void {
                $calls++;

                throw new LogicException('broken');
            },
        ]]);

        foreach ([1, 2] as $_) {
            try {
                $renderer->render('text');
                self::fail('Expected the build to fail');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'app.markdown.extensions[0] could not be registered: broken',
                    $exception->getMessage(),
                );
            }
        }

        self::assertSame(1, $calls);
    }

    /**
     * Garner skips entries for what it already registers; that list must
     * keep up with what GithubFlavoredMarkdownExtension adds in the installed
     * league/commonmark, or a listed part would register twice.
     */
    public function testEveryPartOfTheDefaultsMayBeListed(): void
    {
        $environment = new Environment();
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        iterator_to_array($environment->getBlockStartParsers());

        $parts = array_map(
            static fn(ExtensionInterface $extension): string => $extension::class,
            iterator_to_array($environment->getExtensions(), false),
        );

        $html = new MarkdownRenderer(['extensions' => $parts])->render(
            "~~gone~~ **bold** www.example.com\n\n| a |\n| - |\n| b |",
        );

        self::assertStringContainsString('<del>gone</del>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<td>b</td>', $html);
    }

    public function testNothingIsBuiltUntilTheFirstRender(): void
    {
        $calls = 0;
        $renderer = new MarkdownRenderer(['extensions' => [
            static function () use (&$calls): void {
                $calls++;
            },
        ]]);

        self::assertSame(0, $calls);
        self::assertSame('', $renderer->render(''));
        self::assertSame(0, $calls);

        $renderer->render('one');
        $renderer->render('two');

        self::assertSame(1, $calls);
    }

    public function testApplicationReadsExtensionsFromConfig(): void
    {
        $app = new Application(sys_get_temp_dir(), sys_get_temp_dir(), [
            'app' => ['markdown' => ['extensions' => [FootnoteExtension::class]]],
        ]);

        self::assertStringContainsString(
            'class="footnotes"',
            $app->markdownRenderer()->render(self::FOOTNOTE),
        );
    }
}
