<?php

namespace App\Command;

use App\Tasks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('add', 'Adds a task')]
final class AddCommand extends Command
{
    public function __construct(private Tasks $tasks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('text', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'What to do');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $n = $this->tasks->add(implode(' ', (array)$input->getArgument('text')));
        $output->writeln("<info>Task $n added.</info>");
        return Command::SUCCESS;
    }
}
