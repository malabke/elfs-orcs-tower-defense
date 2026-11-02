<?php

namespace App\Command;

use App\Tasks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('done', 'Marks a task done (again: not done)')]
final class DoneCommand extends Command
{
    public function __construct(private Tasks $tasks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::REQUIRED, 'The task\'s number (list-tasks shows them)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $n = (int)$input->getArgument('number');
        try {
            $this->tasks->toggle($n);
        } catch (\OutOfRangeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $task = $this->tasks->at($n);
        $output->writeln($task['done'] ? "<info>Done:</info> {$task['text']}" : "Not done any more: {$task['text']}");
        return Command::SUCCESS;
    }
}
