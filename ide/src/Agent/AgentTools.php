<?php

namespace JumPHP\Ide\Agent;

/**
 * What an AI (the Claude Code the IDE starts) may do in the IDE, as MCP tools: show files, point at lines, run the
 * project's configurations in the Run window, ask the user... The interface carries them out where the user sees them
 * (ide/ui/js/agent.js); McpServer offers them to the AI, IdeAgent hands them to the interface.
 */
final class AgentTools
{
    /** the MCP server's name: Claude Code calls the tools mcp__jumphp-ide__<name> */
    public const SERVER = 'jumphp-ide';

    /** the tool Claude Code asks permissions through (claude --permission-prompt-tool mcp__jumphp-ide__permission) */
    public const PERMISSION = 'permission';

    /**
     * What Claude may always do in a JumPHP project without asking (claude --allowedTools): the project's own command
     * (everything runs in Docker through it) and git's reading commands.
     */
    public const ALLOWED = ['Bash(bin/jumphp:*)', 'Bash(./bin/jumphp:*)', 'Bash(git status:*)', 'Bash(git diff:*)', 'Bash(git log:*)', 'Bash(git show:*)'];

    /** told to the AI with the tools (MCP instructions, and claude --append-system-prompt) */
    public const INSTRUCTIONS = <<<'TXT'
        You are running inside the JumPHP IDE, and the user watches the IDE while you work: the files you read and edit
        open there by themselves. Use the jumphp-ide tools to show the user what you are talking about:
        - open_file / highlight to point at the code you explain or changed (with a short message);
        - run to start the project's run configurations (tests, shots, builds) in the IDE's Run window, where the user sees
          the output and the test tree, rather than running bin/jumphp in a shell when the user should see the result;
        - get_state to know what the user is looking at (open files, cursor, selection, run configurations);
        - ask_user when you need a decision from the user (you cannot ask otherwise);
        - notify for a short message when something is done;
        - debug_* to debug PHP with Xdebug where the user sees it (breakpoints in the gutter, the Debug window): set
          breakpoints (debug_breakpoints), run a configuration with the debugger (debug_run: it waits until a breakpoint
          stops the program), read the variables (debug_variables) or evaluate PHP there (debug_eval), step
          (debug_step), and always end with debug_step stop or run when done. Prefer it to adding var_dump / echo.
        TXT;

    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>, timeout: float}> */
    public static function all(): array
    {
        $path = ['type' => 'string', 'description' => 'a file of the project, relative to its root (e.g. games/pong/src/Scene/PlayScene.php)'];
        $line = ['type' => 'integer', 'minimum' => 1];
        return [
            self::tool('get_state', 'What the user sees in the IDE: the open files, the active one with its cursor and selected text, the tool windows shown, the run configurations (their ids for `run`), the number of problems.', [], [], 10),
            self::tool('open_file', 'Opens a file in the IDE\'s editor (for the user to see), optionally at a line or a range of lines, selected.',
                ['path' => $path, 'line' => $line, 'end_line' => $line], ['path'], 15),
            self::tool('highlight', 'Points the user at lines of a file: opens it there and marks the lines with your message beside them (until the next highlight or the user clears it). Use it to show what you explain or what you changed.',
                ['path' => $path, 'start_line' => $line, 'end_line' => $line, 'message' => ['type' => 'string', 'description' => 'a short note shown at the lines']], ['path', 'start_line'], 15),
            self::tool('clear_highlights', 'Removes the marks of highlight.', [], [], 10),
            self::tool('show_diff', 'Shows the user the changes of a file against the last commit (a diff tab), or the Commit window with every changed file when no path is given.',
                ['path' => $path], [], 15),
            self::tool('show_tool', 'Shows a tool window of the IDE.',
                ['tool' => ['type' => 'string', 'enum' => ['project', 'commit', 'structure', 'hierarchy', 'run', 'find', 'problems', 'game', 'git', 'claude', 'publish', 'debug']]], ['tool'], 10),
            self::tool('find_in_files', 'Shows a search in the IDE\'s Find window (the user sees the matches) and returns how many there are.',
                ['query' => ['type' => 'string'], 'regex' => ['type' => 'boolean'], 'include' => ['type' => 'string', 'description' => 'a file mask, e.g. *.php or games/pong/*']], ['query'], 30),
            self::tool('run', 'Runs a run configuration of the project in the IDE\'s Run window (the user sees its output, test results as a tree, screenshots) and waits for its end: exit code, tests passed / failed, the end of the output, the screenshot it made. `config` is an id from get_state (e.g. "test:pong", "shot:pong") or a command line like "bin/jumphp test pong".',
                ['config' => ['type' => 'string'], 'wait' => ['type' => 'boolean', 'description' => 'wait for the end (default true)']], ['config'], 900),
            self::tool('debug_breakpoints', 'Sets the breakpoints of a file (all of them: lines not given are removed), shown in the IDE\'s gutter, given at once to the programs being debugged; a line can have a condition (a PHP expression: it stops only when true). Without a path: lists them. clear_all removes every breakpoint first.',
                ['path' => $path, 'lines' => ['type' => 'array', 'items' => ['anyOf' => [$line, ['type' => 'object', 'properties' => ['line' => $line, 'condition' => ['type' => 'string']], 'required' => ['line']]]]],
                    'clear_all' => ['type' => 'boolean']], [], 15),
            self::tool('debug_run', 'Runs a run configuration with the debugger (Xdebug, like the IDE\'s Debug: config is an id of get_state or "bin/jumphp test pong", "bin/jumphp shot pong"...) and waits until a breakpoint stops it (then: where, the stack, the local variables), it ends, or `timeout` seconds pass (default 120). Set breakpoints first. A game run headless (shot, test) is best: `run` opens a window and waits for the player.',
                ['config' => ['type' => 'string'], 'timeout' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 600]], ['config'], 660),
            self::tool('debug_state', 'Where the debugged program is stopped: file, line, the stack, the variables of a frame (0: where it stopped; 1: its caller...).',
                ['frame' => ['type' => 'integer', 'minimum' => 0]], [], 15),
            self::tool('debug_step', 'Moves the stopped program: step_over (the next line), step_into (into the call), step_out (back to the caller), run (to the next breakpoint), stop (ends the program). Waits until it stops again (returns the new state) or ends.',
                ['action' => ['type' => 'string', 'enum' => ['step_over', 'step_into', 'step_out', 'run', 'stop']], 'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600]], ['action'], 660),
            self::tool('debug_eval', 'Evaluates a PHP expression where the program is stopped ($this->score * 2, count($enemies), $map->tileAt(3, 4)...): its value (and its children, for arrays and objects).',
                ['expression' => ['type' => 'string']], ['expression'], 30),
            self::tool('debug_variables', 'The variables of a frame of the stopped program, or the children of one of them (name: its full name as given, e.g. $this->player, $list[3]).',
                ['name' => ['type' => 'string'], 'frame' => ['type' => 'integer', 'minimum' => 0]], [], 30),
            self::tool('play', 'Plays the file\'s game, as the IDE\'s Play does: a scene file starts the game in that scene, a test file runs only its tests.',
                ['path' => $path], ['path'], 30),
            self::tool('diagnostics', 'The problems the IDE finds in a PHP file (syntax errors, unknown classes, methods, functions), or in the open files when no path is given.',
                ['path' => $path], [], 30),
            self::tool('ask_user', 'Asks the user a question in the IDE and waits for the answer (up to 10 minutes). With `options`, the user picks one of them (or types another answer).',
                ['question' => ['type' => 'string'], 'options' => ['type' => 'array', 'items' => ['type' => 'string']]], ['question'], 600),
            // claude -p --permission-prompt-tool: what needs a yes (a command, a web page...) is asked in the IDE
            self::tool(self::PERMISSION, 'Asks the user in the IDE whether Claude may use a tool (Claude Code calls it by itself when a tool needs a permission: do not call it).',
                ['tool_name' => ['type' => 'string'], 'input' => ['type' => 'object'], 'tool_use_id' => ['type' => 'string']], ['tool_name'], 600),
            self::tool('notify', 'Shows the user a short notification in the IDE.',
                ['message' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['info', 'success', 'error']]], ['message'], 10),
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>, timeout: float}|null */
    public static function find(string $name): ?array
    {
        foreach (self::all() as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, timeout: float}
     */
    private static function tool(string $name, string $description, array $properties, array $required, float $timeout): array
    {
        $schema = ['type' => 'object', 'properties' => $properties ?: new \stdClass()];
        if ($required) {
            $schema['required'] = $required;
        }
        return ['name' => $name, 'description' => $description, 'inputSchema' => $schema, 'timeout' => $timeout];
    }
}
