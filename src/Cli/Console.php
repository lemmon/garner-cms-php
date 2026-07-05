<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Builds and runs the Garner CLI: registers the commands and applies the
 * blue-names / gray-headers theme over Symfony Console's default chrome.
 *
 * Lives in src/ (rather than the extensionless bin/garner) so static analysis
 * and the formatter cover the command wiring.
 */
final class Console
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function run(): int
    {
        $output = new ConsoleOutput();
        $this->theme($output->getFormatter());
        $this->theme($output->getErrorOutput()->getFormatter());

        $cli = new ConsoleApplication('Garner');
        $cli->setAutoExit(false);
        $cli->addCommand(new CacheClearCommand($this->app));
        $cli->addCommand(new ReindexCommand($this->app));
        $cli->addCommand(new SessionGcCommand($this->app));
        $cli->addCommand(new ValidateCommand($this->app));
        $cli->addCommand(new CreatePageCommand($this->app));

        return $cli->run(null, $output);
    }

    private function theme(OutputFormatterInterface $formatter): void
    {
        $formatter->setStyle('comment', new OutputFormatterStyle('gray'));
        $formatter->setStyle('info', new OutputFormatterStyle('blue'));
    }
}
