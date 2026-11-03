<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Git\Git;
use JumPHP\Ide\Git\GitException;
use PHPUnit\Framework\TestCase;

final class GitBranchesTest extends TestCase
{
    private string $dir;
    private Processes $processes;
    private Git $git;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-ide-git-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->processes = new Processes();
        $this->git = new Git($this->processes, $this->dir);
        $this->git->setFallbackAuthor(['name' => 'Agent', 'email' => 'agent@localhost']);
        $this->wait($this->git->run(['init', '-q', '-b', 'main']));
        $this->write('a.txt', "one\ntwo\nthree\n");
        $this->wait($this->git->commit('First', ['a.txt']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testABranchIsCreatedSwitchedToAndMergedBackWithoutConflict(): void
    {
        $this->wait($this->git->checkout('feature', true));
        self::assertSame('feature', $this->wait($this->git->status())['branch']);
        $this->write('b.txt', "new\n");
        $this->wait($this->git->commit('Add b', ['b.txt']));
        $this->wait($this->git->checkout('main'));
        self::assertFileDoesNotExist("$this->dir/b.txt");
        self::assertFalse($this->wait($this->git->isMerged('feature')));

        self::assertSame(['conflicts' => []], $this->wait($this->git->merge('feature')));
        self::assertFileExists("$this->dir/b.txt");
        self::assertTrue($this->wait($this->git->isMerged('feature')));
        self::assertNull($this->wait($this->git->status())['merging']);
        $this->wait($this->git->deleteBranch('feature'));
        self::assertSame(['main'], array_column($this->wait($this->git->branches()), 'name'));
    }

    public function testAConflictWaitsUntilEachFileIsResolvedThenTheMergeIsCommitted(): void
    {
        $this->conflict();
        $status = $this->wait($this->git->status());
        self::assertSame('feature', $status['merging']);
        self::assertSame(['a.txt', 'c.txt', 'd.txt'], array_column(array_filter($status['files'], fn ($f) => $f['conflict']), 'path'));
        $versions = $this->wait($this->git->conflictVersions('a.txt'));
        self::assertSame(["one\ntwo\nthree\n", "one\nMAIN\nthree\n", "one\nFEATURE\nthree\n"], [$versions['base'], $versions['ours'], $versions['theirs']]);

        try {
            $this->wait($this->git->resolve('a.txt', 'mark'));
            self::fail('a file with conflict markers is not marked resolved');
        } catch (GitException $e) {
            self::assertStringContainsString('conflict markers', $e->getMessage());
        }
        try {
            $this->wait($this->git->commitMerge());
            self::fail('no merge commit while files have conflicts');
        } catch (GitException $e) {
            self::assertStringContainsString('resolve them first', $e->getMessage());
        }

        $this->wait($this->git->resolve('a.txt', 'theirs'));
        self::assertSame("one\nFEATURE\nthree\n", file_get_contents("$this->dir/a.txt"));
        $this->wait($this->git->resolve('c.txt', 'both'));
        self::assertSame("main c\nfeature c\n", file_get_contents("$this->dir/c.txt"));
        $this->write('d.txt', "edited by hand\n");
        $this->wait($this->git->resolve('d.txt', 'mark'));

        $sha = $this->wait($this->git->commitMerge());
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);
        $status = $this->wait($this->git->status());
        self::assertNull($status['merging']);
        self::assertSame([], $status['files']);
        self::assertCount(2, $this->wait($this->git->log(null, 1, 'HEAD'))[0]['parents']);
    }

    public function testAMergeWithConflictsCanBeGivenUp(): void
    {
        $this->conflict();
        $this->wait($this->git->resolve('a.txt', 'ours'));
        $this->wait($this->git->abortMerge());
        $status = $this->wait($this->git->status());
        self::assertNull($status['merging']);
        self::assertSame([], $status['files']);
        self::assertSame("one\nMAIN\nthree\n", file_get_contents("$this->dir/a.txt"));
    }

    public function testAnUnmergedBranchIsNotDeletedWithoutForce(): void
    {
        $this->wait($this->git->checkout('feature', true));
        $this->write('b.txt', "new\n");
        $this->wait($this->git->commit('Add b', ['b.txt']));
        $this->wait($this->git->checkout('main'));
        try {
            $this->wait($this->git->deleteBranch('feature'));
            self::fail('an unmerged branch is kept');
        } catch (GitException $e) {
            self::assertStringContainsString('not fully merged', $e->getMessage());
        }
        $this->wait($this->git->deleteBranch('feature', true));
        self::assertSame(['main'], array_column($this->wait($this->git->branches()), 'name'));
    }

    /** main and feature change the same lines: a.txt (both edited), c.txt (both added), d.txt (both edited). */
    private function conflict(): void
    {
        $this->write('d.txt', "d\n");
        $this->wait($this->git->commit('Add d', ['d.txt']));
        $this->wait($this->git->checkout('feature', true));
        $this->write('a.txt', "one\nFEATURE\nthree\n");
        $this->write('c.txt', "feature c\n");
        $this->write('d.txt', "feature d\n");
        $this->wait($this->git->commit('Feature', ['a.txt', 'c.txt', 'd.txt']));
        $this->wait($this->git->checkout('main'));
        $this->write('a.txt', "one\nMAIN\nthree\n");
        $this->write('c.txt', "main c\n");
        $this->write('d.txt', "main d\n");
        $this->wait($this->git->commit('Main', ['a.txt', 'c.txt', 'd.txt']));
        self::assertSame(['conflicts' => ['a.txt', 'c.txt', 'd.txt']], $this->wait($this->git->merge('feature')));
    }

    private function write(string $path, string $content): void
    {
        file_put_contents("$this->dir/$path", $content);
    }

    private function wait(mixed $deferred): mixed
    {
        return $this->processes->wait($deferred);
    }
}
