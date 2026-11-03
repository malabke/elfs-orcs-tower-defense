<?php

namespace App;

use App\Command\AddCommand;
use App\Command\DoneCommand;
use App\Command\ListCommand;
use App\Command\UiCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The app: its commands (Symfony Console) and where it keeps its data. boot() is what app.php and the exported
 * executable call (tools/standalone-stub.php).
 */
final class Main
{
    public readonly Tasks $tasks;
    public readonly Application $console;

    public function __construct(string $data)
    {
        $this->tasks = new Tasks($data . '/tasks.json');
        $this->console = new Application(self::title(), self::version());
        $this->console->addCommands([
            new UiCommand($this->tasks),
            new AddCommand($this->tasks),
            new ListCommand($this->tasks),
            new DoneCommand($this->tasks),
        ]);
        // no command: the full-screen screen in a terminal, the tasks' list when the output is a pipe or a file
        $this->console->setDefaultCommand(stream_isatty(STDOUT) ? 'ui' : 'list-tasks');
    }

    /** @param string $root the app's folder (app.php's, or where the executable unpacked it) */
    public static function boot(string $root): self
    {
        // the data: the user's data folder once exported (JUMPHP_VAR), else var/ of the app
        $data = getenv('JUMPHP_VAR') ?: $root . '/var';
        // paths typed on the command line are relative to where the user is, not to the app
        if (defined('JUMPHP_CWD')) {
            chdir((string)constant('JUMPHP_CWD'));
        }
        return new self($data);
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $this->console->setAutoExit(false);
        return $this->console->run($input ?? new ArgvInput(), $output);
    }

    public static function title(): string
    {
        return self::config()['title'] ?? 'JumPHP CLI';
    }

    public static function version(): string
    {
        return (string)(self::config()['version'] ?? '0.1.0');
    }

    /** @return array<string, mixed> config/project.yaml's (or game.yaml's) id, title and version (read without a YAML parser) */
    private static function config(): array
    {
        $out = [];
        $yaml = (string)@file_get_contents(dirname(__DIR__) . '/config/project.yaml') ?: (string)@file_get_contents(dirname(__DIR__) . '/config/game.yaml');
        foreach (['id', 'title', 'version'] as $key) {
            if (preg_match('/^\s+' . $key . ':\s*"?([^"#\n]*?)"?\s*(?:#.*)?$/m', $yaml, $m)) {
                $out[$key] = trim($m[1]);
            }
        }
        return $out;
    }
}
