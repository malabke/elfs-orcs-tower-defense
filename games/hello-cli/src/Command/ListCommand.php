<?php

namespace App\Command;

use App\Tasks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('list-tasks', 'Lists the tasks', ['ls'])]
final class ListCommand extends Command
{
    public function __construct(private Tasks $tasks)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->tasks->all() as $i => $task) {
            $rows[] = [$i + 1, $task['done'] ? '<info>✔</info>' : ' ', $task['text']];
        }
        if (!$rows) {
            $output->writeln('No task yet: <comment>add "Something to do"</comment>');
            return Command::SUCCESS;
        }
        (new Table($output))->setHeaders(['#', '', 'Task'])->setRows($rows)->render();
        return Command::SUCCESS;
    }
}
