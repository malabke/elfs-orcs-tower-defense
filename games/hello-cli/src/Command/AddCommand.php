<?php

namespace App\Command;

use App\Tasks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand('add', 'Adds a task')]
final class AddCommand extends Command
{
    public function __construct(private Tasks $tasks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('text', InputArgument::IS_ARRAY, 'What to do (asked when not given)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $text = trim(implode(' ', (array)$input->getArgument('text')));
        if ($text === '' && $input->isInteractive()) {
            $text = trim((string)(new QuestionHelper())->ask($input, $output, new Question('What to do? ')));
        }
        if ($text === '') {
            $output->writeln('<error>Nothing to add: add <what to do></error>');
            return Command::INVALID;
        }
        $n = $this->tasks->add($text);
        $output->writeln("<info>Task $n added.</info>");
        return Command::SUCCESS;
    }
}
