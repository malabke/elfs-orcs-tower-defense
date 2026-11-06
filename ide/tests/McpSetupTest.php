<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Claude\McpSetup;
use PHPUnit\Framework\TestCase;

/** jumphp.net's MCP server found in Claude Code's configuration, and set up with `claude mcp add` (a fake claude here). */
final class McpSetupTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-mcp-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/bin", 0777, true);
        mkdir("$this->dir/project");
        $this->path = (string)getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->path);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function config(array $config): string
    {
        file_put_contents("$this->dir/claude.json", json_encode($config));
        return "$this->dir/claude.json";
    }

    public function testItIsFoundInTheUsersScopeOnlyForThisServer(): void
    {
        $setup = new McpSetup(new Processes(), "$this->dir/project", $this->config(['mcpServers' => ['jumphp' => ['type' => 'http', 'url' => 'https://jumphp.net/mcp']]]));
        self::assertTrue($setup->status('https://jumphp.net')['installed']);
        self::assertSame('user', $setup->status('https://jumphp.net')['scope']);
        $other = $setup->status('http://localhost:8000');
        self::assertFalse($other['installed'], 'a server of another jumphp.net does not count');
        self::assertSame('https://jumphp.net/mcp', $other['url']);
    }

    public function testItIsFoundInTheProjectsScopesAndMissingElsewhere(): void
    {
        $root = "$this->dir/project";
        $local = new McpSetup(new Processes(), $root, $this->config(['projects' => [$root => ['mcpServers' => ['site' => ['url' => 'https://jumphp.net/mcp/']]]]]));
        self::assertSame('local', $local->status('https://jumphp.net')['scope'], 'any name, the same address');
        file_put_contents("$root/.mcp.json", json_encode(['mcpServers' => ['jumphp' => ['url' => 'https://jumphp.net/mcp']]]));
        self::assertTrue((new McpSetup(new Processes(), $root, $this->config([])))->status('https://jumphp.net')['installed']);
        unlink("$root/.mcp.json");
        self::assertFalse((new McpSetup(new Processes(), $root, $this->config(['projects' => []])))->status('https://jumphp.net')['installed']);
        self::assertFalse((new McpSetup(new Processes(), $root, "$this->dir/none.json"))->status('https://jumphp.net')['installed']);
    }

    public function testInstallReplacesAnOldServerOfTheUser(): void
    {
        // a fake claude: writes what `claude mcp add / remove` would into the configuration file
        $config = $this->config(['mcpServers' => ['jumphp' => ['url' => 'http://old.example/mcp']]]);
        file_put_contents("$this->dir/bin/claude", "#!/bin/sh\necho \"\$@\" >> $this->dir/calls\n"
            . "case \"\$2\" in remove) echo '{\"mcpServers\":{}}' > $config ;;\n"
            . " add) echo '{\"mcpServers\":{\"jumphp\":{\"type\":\"http\",\"url\":\"'\$8'\"}}}' > $config ;; esac\n");
        chmod("$this->dir/bin/claude", 0755);
        putenv("PATH=$this->dir/bin:" . $this->path);
        $processes = new Processes();
        $setup = new McpSetup($processes, "$this->dir/project", $config);
        $done = $setup->install('https://jumphp.net', 'jpi_abc123');
        for ($i = 0; $i < 500 && !$done->isDone(); $i++) {
            $processes->poll();
            usleep(5000);
        }
        self::assertTrue($done->result()['installed']);
        self::assertSame("mcp remove --scope user jumphp\nmcp add --transport http --scope user jumphp https://jumphp.net/mcp --header Authorization: Bearer jpi_abc123\n",
            file_get_contents("$this->dir/calls"));
        self::assertInstanceOf(Deferred::class, $setup->install('https://jumphp.net', 'bad token; rm -rf /'));
        self::assertNotNull($setup->install('https://jumphp.net', 'bad token; rm -rf /')->error());
    }
}
