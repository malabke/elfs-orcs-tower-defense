<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Ai\ChatModels;
use JumPHP\Ide\Ai\OpenAiImages;
use JumPHP\Ide\Ai\Pixelize;
use JumPHP\Ide\Ai\ResourceGenerator;
use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use PHPUnit\Framework\TestCase;

final class ResourceGeneratorTest extends TestCase
{
    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<string> the header file's text of each curl call (the key is in it, not on the command line) */
    private array $headersSent = [];

    /** A generator whose `claude` / `curl` answers $stdout. */
    private function generator(string $stdout): ResourceGenerator
    {
        return new ResourceGenerator(new Processes(), sys_get_temp_dir(), function (array $command) use ($stdout): Deferred {
            $this->commands[] = $command;
            return Deferred::resolved(['code' => 0, 'stdout' => $stdout, 'stderr' => '']);
        });
    }

    private static function claude(array $data): string
    {
        return (string)json_encode(['type' => 'result', 'is_error' => false, 'result' => json_encode($data), 'structured_output' => $data]);
    }

    private static function settled(Deferred $d): mixed
    {
        if ($d->error()) {
            throw $d->error();
        }
        return $d->result();
    }

    public function testASpriteComesBackAsItsPixelsAndPalette(): void
    {
        $sprite = ['palette' => ['a' => '#FF0000', 'b' => '#000000'], 'frames' => [['.ab.', 'abba'], ['b..a', '.aa.']]];
        $out = self::settled($this->generator(self::claude($sprite))->generate('sprite', 'a red blob', ['width' => 4, 'height' => 2, 'frames' => 2]));
        self::assertSame(['a' => '#ff0000', 'b' => '#000000'], $out['palette']);
        self::assertSame([['.ab.', 'abba'], ['b..a', '.aa.']], $out['frames']);
        $command = $this->commands[0];
        self::assertSame(['claude', '-p'], array_slice($command, 0, 2));
        self::assertStringContainsString('2 frame(s) of 4 x 2 pixels', $command[2]);
        self::assertContains('--json-schema', $command);
        self::assertSame('', $command[array_search('--tools', $command, true) + 1]);
    }

    public function testASpriteOfTheWrongSizeIsRefused(): void
    {
        $sprite = ['palette' => ['a' => '#ff0000'], 'frames' => [['aaa', 'aa']]];
        $this->expectExceptionMessage('row 2 is 2 pixels wide instead of 3');
        self::settled($this->generator(self::claude($sprite))->generate('sprite', 'x', ['width' => 3, 'height' => 2, 'frames' => 1]));
    }

    public function testAColourOutsideThePaletteIsRefused(): void
    {
        $this->expectExceptionMessage("the colour 'z' is not in the palette");
        self::settled($this->generator(self::claude(['palette' => ['a' => '#ff0000'], 'frames' => [['az']]]))
            ->generate('sprite', 'x', ['width' => 2, 'height' => 1, 'frames' => 1]));
    }

    public function testASoundEffectIsCheckedAgainstTheEditorsRanges(): void
    {
        $sfx = ['wave' => 'square', 'start' => 300, 'end' => 900, 'attack' => 0, 'sustain' => 60, 'decay' => 120, 'volume' => 6,
            'vibrato' => 0, 'speed' => 0, 'repeat' => 1, 'gap' => 0];
        self::assertSame($sfx, self::settled($this->generator(self::claude($sfx))->generate('sfx', 'a jump')));
        $this->expectExceptionMessage('volume must be between 0 and 10');
        self::settled($this->generator(self::claude(['volume' => 40] + $sfx))->generate('sfx', 'a jump'));
    }

    public function testATuneHasFourVoicesOfNotesInsideItsBars(): void
    {
        $tune = ['tempo' => 140, 'voices' => [['volume' => 7, 'notes' => [[4, 72, 2], [0, 72, 2]]], ['notes' => []],
            ['notes' => [[0, 36, 4]]], ['notes' => [[0, 0, 1], [8, 1, 1]]]]];
        $out = self::settled($this->generator(self::claude($tune))->generate('music', 'happy', ['bars' => 1]));
        self::assertSame([[0, 72, 2], [4, 72, 2]], $out['voices'][0]['notes']);
        self::assertSame(1, $out['bars']);
        $this->expectExceptionMessage('voice 4 has the note [0,7,1]');
        $tune['voices'][3]['notes'] = [[0, 7, 1]];
        self::settled($this->generator(self::claude($tune))->generate('music', 'happy', ['bars' => 1]));
    }

    public function testALevelBecomesTilesAndObjects(): void
    {
        $level = ['rows' => ['.....', '.P.C.', '..==E', '#####']];
        $out = self::settled($this->generator(self::claude($level))->generate('level', 'a small room', ['width' => 5, 'height' => 4]));
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 2, 0, 1, 1, 1, 1, 1], $out['tiles']);
        self::assertSame([['type' => 'start', 'x' => 1, 'y' => 1], ['type' => 'coin', 'x' => 3, 'y' => 1], ['type' => 'enemy', 'x' => 4, 'y' => 2]], $out['objects']);
        $this->expectExceptionMessage('exactly one start');
        self::settled($this->generator(self::claude(['rows' => ['.....', '.....', '.....', '#####']]))->generate('level', 'x', ['width' => 5, 'height' => 4]));
    }

    public function testClaudesErrorsAreShown(): void
    {
        $this->expectExceptionMessage('Claude: Not logged in');
        self::settled($this->generator((string)json_encode(['is_error' => true, 'result' => 'Not logged in']))->generate('sfx', 'x'));
    }

    public function testAPictureServiceIsAskedWithTheKeyInAFileAndItsPictureMadePixelArt(): void
    {
        $picture = imagecreatetruecolor(64, 64);
        imagesavealpha($picture, true);
        imagealphablending($picture, false);
        imagefill($picture, 0, 0, imagecolorallocatealpha($picture, 0, 0, 0, 127));
        imagefilledrectangle($picture, 16, 16, 47, 47, imagecolorallocate($picture, 255, 0, 0));
        ob_start();
        imagepng($picture);
        $png = (string)ob_get_clean();
        $headers = null;
        $gen = new ResourceGenerator(new Processes(), sys_get_temp_dir(), function (array $command) use ($png, &$headers): Deferred {
            $this->commands[] = $command;
            $headers = (string)file_get_contents(substr($command[array_search('-H', $command, true) + 1], 1));
            return Deferred::resolved(['code' => 0, 'stdout' => json_encode(['data' => [['b64_json' => base64_encode($png)]]]), 'stderr' => '']);
        });
        $out = self::settled($gen->generate('sprite', 'a red box', ['width' => 4, 'height' => 4], OpenAiImages::ID, 'sk-test'));
        self::assertSame(OpenAiImages::URL, end($this->commands[0]));
        self::assertStringNotContainsString('sk-test', implode(' ', $this->commands[0]));
        self::assertStringContainsString('Authorization: Bearer sk-test', (string)$headers);
        self::assertSame(['....', '.aa.', '.aa.', '....'], $out['frames'][0]);
        self::assertMatchesRegularExpression('/^#f[c-f]0[0-4]0[0-4]$/', $out['palette']['a']);   // GD's quantizer rounds a little
    }

    public function testAPictureServiceNeedsAKeyAndMakesSpritesOnly(): void
    {
        $gen = $this->generator('');
        self::assertStringContainsString('needs your API key', $gen->generate('sprite', 'x', [], OpenAiImages::ID, '')->error()?->getMessage() ?? '');
        self::assertStringContainsString('sprites only', $gen->generate('sfx', 'x', [], OpenAiImages::ID, 'k')->error()?->getMessage() ?? '');
        self::assertSame([], $this->commands);
    }

    public function testTheOpenAiRequestAndItsErrors(): void
    {
        $req = (new OpenAiImages())->request('a cat', 'k');
        self::assertSame('gpt-image-1', json_decode($req['body'], true)['model']);
        $this->expectExceptionMessage('OpenAI Images: Incorrect API key');
        (new OpenAiImages())->decode((string)json_encode(['error' => ['message' => 'Incorrect API key']]));
    }

    public function testPixelizeKeepsTransparentPixels(): void
    {
        $img = imagecreatetruecolor(2, 1);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagesetpixel($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagesetpixel($img, 1, 0, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagepng($img);
        self::assertSame(['.a'], Pixelize::sprite((string)ob_get_clean(), 2, 1)['frames'][0]);
    }

    /** A generator whose commands get the next of $answers (what a chat server / opencode would print). */
    private function sequence(string ...$answers): ResourceGenerator
    {
        return new ResourceGenerator(new Processes(), sys_get_temp_dir(), function (array $command) use (&$answers): Deferred {
            $this->commands[] = $command;
            $headers = $command[array_search('-H', $command, true) + 1] ?? null;
            $this->headersSent[] = is_string($headers) && str_starts_with($headers, '@') ? (string)@file_get_contents(substr($headers, 1)) : '';
            return Deferred::resolved(['code' => 0, 'stdout' => array_shift($answers) ?? '', 'stderr' => '']);
        });
    }

    private static function chat(string $content): string
    {
        return (string)json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]]);
    }

    public function testALocalModelNeedsNoKeyAndSendsNothingOut(): void
    {
        $sfx = ['wave' => 'square', 'start' => 400, 'end' => 900, 'attack' => 5, 'sustain' => 80, 'decay' => 120, 'volume' => 6, 'vibrato' => 0, 'speed' => 0, 'repeat' => 1, 'gap' => 0];
        $out = self::settled($this->sequence(self::chat("Here it is:\n```json\n" . json_encode($sfx) . "\n```"))->generate('sfx', 'a coin', [], 'ollama', '', 'qwen2.5-coder'));
        self::assertSame('square', $out['wave']);
        $command = $this->commands[0];
        self::assertSame('http://127.0.0.1:11434/v1/chat/completions', end($command));
        self::assertStringNotContainsString('Authorization', $this->headersSent[0]);
    }

    public function testAnOnlineModelGetsTheKeyInAFileNotOnTheCommandLine(): void
    {
        $sfx = ['wave' => 'sine', 'start' => 300, 'end' => 300, 'attack' => 5, 'sustain' => 50, 'decay' => 50, 'volume' => 5, 'vibrato' => 0, 'speed' => 0, 'repeat' => 1, 'gap' => 0];
        self::settled($this->sequence(self::chat(json_encode($sfx)))->generate('sfx', 'x', [], 'openai', 'sk-live', 'gpt-4o-mini'));
        self::assertSame('https://api.openai.com/v1/chat/completions', end($this->commands[0]));
        self::assertStringNotContainsString('sk-live', implode(' ', $this->commands[0]));
        self::assertStringContainsString('Authorization: Bearer sk-live', $this->headersSent[0]);
        $this->expectExceptionMessage('needs your API key');
        self::settled($this->generator('')->generate('sfx', 'x', [], 'groq', ''));
    }

    public function testAnAnswerTheEditorCannotUseIsSentBackOnceWithWhatIsWrong(): void
    {
        $good = ['wave' => 'noise', 'start' => 800, 'end' => 100, 'attack' => 0, 'sustain' => 20, 'decay' => 200, 'volume' => 7, 'vibrato' => 0, 'speed' => 0, 'repeat' => 1, 'gap' => 0];
        $bad = ['wave' => 'banjo'] + $good;
        $out = self::settled($this->sequence(self::chat(json_encode($bad)), self::chat(json_encode($good)))->generate('sfx', 'an explosion', [], 'lmstudio', '', 'local-model'));
        self::assertSame('noise', $out['wave']);
        self::assertCount(2, $this->commands);
        self::assertSame('http://127.0.0.1:1234/v1/chat/completions', end($this->commands[1]));
    }

    public function testOpenCodeIsAskedWithItsOwnModelAndItsTextIsReadAsJson(): void
    {
        $level = ['rows' => ['....', '....', '.P..', '####']];
        $out = self::settled($this->sequence("\e[1mDone.\e[0m {\"rows\": " . json_encode($level['rows']) . "}")->generate('level', 'tiny', ['width' => 4, 'height' => 4], 'opencode', '', 'ollama/qwen2.5-coder'));
        self::assertCount(1, $out['objects']);
        $command = $this->commands[0];
        self::assertSame(['opencode', 'run'], array_slice($command, 0, 2));
        self::assertSame(['-m', 'ollama/qwen2.5-coder'], array_slice($command, -2));
        self::assertStringContainsString('JSON schema', $command[2]);
    }

    public function testTheModelsAServerHasAreListed(): void
    {
        $list = self::settled($this->sequence((string)json_encode(['data' => [['id' => 'b-model'], ['id' => 'a-model']]]))->models('lmstudio'));
        self::assertSame(['a-model', 'b-model'], $list);
        self::assertSame(['llama3', 'qwen2.5-coder'], ChatModels::models((string)json_encode(['models' => [['name' => 'qwen2.5-coder'], ['name' => 'llama3']]])));
        self::assertSame('http://127.0.0.1:1234/v1/models', end($this->commands[0]));
    }

    public function testJsonIsFoundWhereverTheModelPutIt(): void
    {
        self::assertSame(['a' => 1], ChatModels::extractJson('{"a": 1}'));
        self::assertSame(['a' => '}{'], ChatModels::extractJson("Sure!\n```json\n{\"a\": \"}{\"}\n```\nHope it helps {not json}"));
        self::assertNull(ChatModels::extractJson('no braces at all'));
        self::assertSame(['x' => ['y' => 2]], ChatModels::extractJson('first { broken then {"x": {"y": 2}} end'));
    }

    public function testTheProvidersListSaysWhichAreLocalAndWhichNeedAKey(): void
    {
        $byId = array_column($this->generator('')->providers(), null, 'id');
        self::assertTrue($byId['ollama']['local']);
        self::assertFalse($byId['ollama']['needsKey']);
        self::assertTrue($byId['openai']['needsKey']);
        self::assertSame('opencode', $byId['opencode']['kind']);
        self::assertSame('image', $byId['openai-images']['kind']);
    }
}
