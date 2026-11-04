<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Claude\OpenCode;
use PHPUnit\Framework\TestCase;

/** OpenCode's JSON lines, told as the events of Claude Code's stream-json (the window shows them without changes). */
final class OpenCodeTest extends TestCase
{
    /** @param list<array<string, mixed>> $events @return list<array<string, mixed>> */
    private static function feed(array $events, array &$state): array
    {
        $out = [];
        foreach ($events as $e) {
            array_push($out, ...OpenCode::translate($e, $state));
        }
        return $out;
    }

    public function testTheFirstEventStartsTheConversationWithItsSession(): void
    {
        $state = ['model' => 'ollama/qwen2.5-coder'];
        $out = self::feed([['type' => 'step_start', 'sessionID' => 'ses_abc123456789', 'part' => ['type' => 'step-start']]], $state);
        self::assertSame(['type' => 'system', 'subtype' => 'init', 'session_id' => 'ses_abc123456789', 'model' => 'ollama/qwen2.5-coder', 'tools' => []], $out[0]);
    }

    public function testATextBecomesTheAssistantsAnswer(): void
    {
        $state = [];
        $out = self::feed([['type' => 'text', 'sessionID' => 'ses_x1234567890', 'part' => ['type' => 'text', 'text' => 'Done.']]], $state);
        self::assertSame('assistant', $out[1]['type']);
        self::assertSame('Done.', $out[1]['message']['content'][0]['text']);
        self::assertSame('Done.', $state['text']);
    }

    public function testTheSameAnswerSaidTwiceIsShownOnce(): void
    {
        $state = [];
        $text = ['type' => 'text', 'sessionID' => 'ses_x1234567890', 'part' => ['text' => 'It has 2 lines.']];
        $out = self::feed([$text, $text], $state);
        self::assertCount(2, $out, 'the init and one answer');
    }

    public function testAToolIsToldWithClaudesNamesAndItsResult(): void
    {
        $state = [];
        $out = self::feed([['type' => 'tool_use', 'sessionID' => 'ses_x1234567890', 'part' => ['type' => 'tool', 'tool' => 'edit', 'callID' => 'c1',
            'state' => ['status' => 'completed', 'input' => ['filePath' => '/p/a.php', 'oldString' => 'a', 'newString' => 'b'], 'output' => 'Edit applied.']]]], $state);
        $use = $out[1]['message']['content'][0];
        self::assertSame(['tool_use', 'Edit', 'c1'], [$use['type'], $use['name'], $use['id']]);
        self::assertSame(['file_path' => '/p/a.php', 'old_string' => 'a', 'new_string' => 'b'], $use['input']);
        $result = $out[2]['message']['content'][0];
        self::assertSame(['tool_result', 'c1', 'Edit applied.', false], [$result['type'], $result['tool_use_id'], $result['content'], $result['is_error']]);
        self::assertSame(1, $state['tools']);
    }

    public function testAFailedToolIsAnError(): void
    {
        $state = [];
        $out = self::feed([['type' => 'tool_use', 'part' => ['tool' => 'bash', 'callID' => 'c2', 'state' => ['status' => 'error', 'input' => ['command' => 'false'], 'error' => 'exit 1']]]], $state);
        self::assertTrue($out[2]['message']['content'][0]['is_error']);
        self::assertSame('Bash', $out[1]['message']['content'][0]['name']);
    }

    public function testTheTurnEndsWithAResultCarryingTheCostAndTheLastText(): void
    {
        $state = ['started' => microtime(true) - 2];
        self::feed([
            ['type' => 'text', 'sessionID' => 'ses_x1234567890', 'part' => ['text' => 'All set']],
            ['type' => 'step_finish', 'part' => ['cost' => 0.002, 'tokens' => ['total' => 900, 'input' => 850, 'output' => 50]]],
            ['type' => 'step_finish', 'part' => ['cost' => 0.001, 'tokens' => ['total' => 1000, 'input' => 940, 'output' => 60]]],
        ], $state);
        $result = OpenCode::finish($state);
        self::assertSame(['result', 'success', false, 'All set', 'ses_x1234567890'], [$result['type'], $result['subtype'], $result['is_error'], $result['result'], $result['session_id']]);
        self::assertEqualsWithDelta(0.003, $result['total_cost_usd'], 1e-9);
        self::assertGreaterThanOrEqual(1900, $result['duration_ms']);
        self::assertSame(1000, $state['context']);
    }

    public function testAnErrorOfOpenCodeEndsTheTurnAsAnError(): void
    {
        $state = [];
        self::feed([['type' => 'error', 'error' => ['name' => 'APIError', 'data' => ['message' => 'No model is set up']]]], $state);
        $result = OpenCode::finish($state);
        self::assertTrue($result['is_error']);
        self::assertSame('No model is set up', $result['result']);
        self::assertSame('error', OpenCode::finish([], 'command failed')['subtype']);
    }
}
