<?php

namespace App\Command;

use App\Tasks;
use App\Ui\TaskScreen;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
        (new TaskScreen($this->tasks))->run();
        return Command::SUCCESS;
    }
}
