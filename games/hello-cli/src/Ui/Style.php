<?php

namespace App\Ui;

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SymfonyStyle (new Style($input, $output) in a command) whose hidden answers ($io->askHidden()) are typed without
 * being shown in jumphp.net's web terminal too.
 */
final class Style extends SymfonyStyle
{
    public function askQuestion(Question $question): mixed
    {
        if (!$question->isHidden() || !WebTerminal::available()) {
            return parent::askQuestion($question);
        }
        WebTerminal::hideInput(true);
        try {
            return parent::askQuestion($question);
        } finally {
            WebTerminal::hideInput(false);
        }
    }
}
