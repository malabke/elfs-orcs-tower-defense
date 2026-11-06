<?php

namespace App\Command;

use App\Tasks;
use App\Ui\TaskScreen;
use App\Ui\WebTerminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('ui', 'The tasks, full screen (arrows, Enter: done, a: add, d: delete, q: quit)')]
final class UiCommand extends Command
{
    public function __construct(private Tasks $tasks)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // a full-screen screen needs a terminal, both ways: on a pipe (a Run window, a script) its keys never come and
        // its loop would wait on an input that is always "readable" (at its end), using the CPU for nothing
        if ((!stream_isatty(STDIN) || !stream_isatty(STDOUT)) && !WebTerminal::available()) {
            $output->writeln('<comment>The screen needs a terminal (here the input or the output is a pipe): run it in a terminal, '
                . 'e.g. the IDE\'s Terminal (Alt+F12). The tasks:</comment>');
            return $this->getApplication()?->find('list-tasks')->run(new ArrayInput([]), $output) ?? Command::FAILURE;
        }
        (new TaskScreen($this->tasks))->run();
        return Command::SUCCESS;
    }
}
