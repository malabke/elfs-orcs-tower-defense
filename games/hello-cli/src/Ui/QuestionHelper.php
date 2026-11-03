<?php

namespace App\Ui;

use Symfony\Component\Console\Helper\QuestionHelper as SymfonyQuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * The console's question helper ($this->getHelper('question') in a command): in jumphp.net's web terminal a hidden
 * question ($question->setHidden(true): a password) is typed without being shown, as `stty -echo` does in a terminal.
 */
final class QuestionHelper extends SymfonyQuestionHelper
{
    public function ask(InputInterface $input, OutputInterface $output, Question $question): mixed
    {
        if (!$question->isHidden() || !WebTerminal::available()) {
            return parent::ask($input, $output, $question);
        }
        WebTerminal::hideInput(true);
        try {
            return parent::ask($input, $output, $question);
        } finally {
            WebTerminal::hideInput(false);
        }
    }
}
