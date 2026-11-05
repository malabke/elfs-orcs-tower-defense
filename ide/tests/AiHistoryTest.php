<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Claude\AiHistory;
use JumPHP\Ide\Claude\OpenCode;
use JumPHP\Ide\Run\ToolInstaller;
use PHPUnit\Framework\TestCase;

final class AiHistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jph-ia-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testATurnIsWrittenUnderIaHistoryAndKeptOutOfGit(): void
    {
        $history = new AiHistory($this->dir);
        $history->sent('c1', 'claude', 'claude-opus-5-5', 'Fix the jump bug');
        $history->observe('c1', ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Done.'], ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'src/Hero.php']]]]]);
        $file = $history->observe('c1', ['type' => 'result', 'duration_ms' => 1500]);
        self::assertMatchesRegularExpression('#^IA/HISTORY/CLAUDE/claude-opus-5-5/\d\d-\d\d-\d{4} \d\d-\d\d-\d\d/fix-the-jump-bug\.md$#', (string)$file);
        $md = (string)file_get_contents("$this->dir/$file");
        self::assertStringContainsString('Fix the jump bug', $md);
        self::assertStringContainsString('Edit src/Hero.php', $md);
        self::assertStringContainsString('Done.', $md);
        self::assertStringContainsString('/IA/', (string)file_get_contents("$this->dir/.gitignore"));
    }

    public function testOpenCodeHasItsOwnFolderAndItsPermissionsFollowTheMode(): void
    {
        $history = new AiHistory($this->dir);
        $history->sent('c2', 'opencode', null, 'Hello');
        self::assertStringStartsWith('IA/HISTORY/OPEN CODE/default/', (string)$history->observe('c2', ['type' => 'result']));
        self::assertSame('{"edit":"deny","bash":"deny"}', OpenCode::permissions('plan'));
        self::assertStringContainsString('"external_directory":"allow"', OpenCode::permissions('auto'));
    }

    public function testEveryKnownProgramHasADownloadPageWhenItCannotBeInstalledHere(): void
    {
        foreach (ToolInstaller::tools() as $tool) {
            self::assertStringStartsWith('https://', (new ToolInstaller(new \JumPHP\Ide\Async\Processes(), $this->dir))->plan($tool)['page']);
        }
    }
}
