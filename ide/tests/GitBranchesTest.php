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

    public function testTheGitHubAccountOfTheRepositoryIsPinnedForTheOtherPrograms(): void
    {
        self::assertFalse($this->wait($this->git->pinAccount('ada')), 'no origin: nothing pinned');
        $this->wait($this->git->run(['remote', 'add', 'origin', 'https://github.com/other/game.git']));
        self::assertFalse($this->wait($this->git->pinAccount('ada')), 'the repository is another account\'s');
        $this->wait($this->git->run(['remote', 'set-url', 'origin', 'https://github.com/Ada/game.git']));
        self::assertTrue($this->wait($this->git->pinAccount('ada')));
        self::assertSame("ada\n", $this->wait($this->git->run(['config', '--local', 'credential.https://github.com.username'])));
    }

    public function testOneHunkOfAFileIsStagedAndUnstaged(): void
    {
        $lines = [];
        for ($i = 1; $i <= 40; $i++) {
            $lines[] = "line $i";
        }
        $this->write('big.txt', implode("\n", $lines) . "\n");
        $this->wait($this->git->commit('Big file', ['big.txt']));
        $lines[1] = 'changed 2';
        $lines[37] = 'changed 38';
        $this->write('big.txt', implode("\n", $lines) . "\n");
        $hunks = $this->wait($this->git->hunks('big.txt'));
        self::assertCount(2, $hunks);
        self::assertSame([1, 1], [$hunks[0]['added'], $hunks[0]['removed']]);
        $this->wait($this->git->stageHunk('big.txt', 1));
        $staged = $this->wait($this->git->hunks('big.txt', true));
        self::assertCount(1, $staged);
        self::assertStringContainsString('changed 38', $staged[0]['text']);
        self::assertStringNotContainsString('changed 2', $staged[0]['text']);
        self::assertCount(1, $this->wait($this->git->hunks('big.txt')), 'the other hunk is still unstaged');
        $this->wait($this->git->stageHunk('big.txt', 0, true));
        self::assertCount(0, $this->wait($this->git->hunks('big.txt', true)), 'taken out again');
        self::assertCount(2, $this->wait($this->git->hunks('big.txt')));
    }

    public function testSubmodulesAreListed(): void
    {
        self::assertSame([], $this->wait($this->git->submodules()));
        $sub = sys_get_temp_dir() . '/jumphp-sub-' . bin2hex(random_bytes(4));
        mkdir($sub);
        exec('cd ' . escapeshellarg($sub) . ' && git init -q -b main && git -c user.name=a -c user.email=a@b commit -q --allow-empty -m init 2>&1');
        try {
            $this->wait($this->git->run(['-c', 'protocol.file.allow=always', 'submodule', 'add', $sub, 'vendor-sub']));
            $list = $this->wait($this->git->submodules());
            self::assertSame('vendor-sub', $list[0]['path']);
            self::assertSame('ok', $list[0]['state']);
        } finally {
            exec('rm -rf ' . escapeshellarg($sub));
        }
    }

    public function testTagsAreMadeListedPushedNamesAndDeleted(): void
    {
        $this->wait($this->git->createTag('v1'));
        $this->wait($this->git->createTag('v2', null, 'Second release'));
        self::assertEqualsCanonicalizing(['v1', 'v2'], array_column($this->wait($this->git->tags()), 'name'));
        $v2 = array_values(array_filter($this->wait($this->git->tags()), fn ($t) => $t['name'] === 'v2'))[0];
        self::assertSame('Second release', $v2['subject']);
        $this->wait($this->git->deleteTag('v1'));
        self::assertSame(['v2'], array_column($this->wait($this->git->tags()), 'name'));
        $this->expectException(GitException::class);
        $this->wait($this->git->createTag('bad name'));
    }

    public function testCommitsCanBeRevertedCherryPickedAndResetTo(): void
    {
        $first = trim($this->wait($this->git->run(['rev-parse', 'HEAD'])));
        $this->write('a.txt', "one\ntwo\nthree\nfour\n");
        $second = $this->wait($this->git->commit('Add four', ['a.txt']));
        $this->wait($this->git->revert($second));
        self::assertSame("one\ntwo\nthree\n", file_get_contents("$this->dir/a.txt"));
        self::assertSame('Revert "Add four"', $this->wait($this->git->log(null, 1))[0]['subject']);
        // back to the first commit, the work kept in the files (mixed), then thrown away (hard)
        $this->wait($this->git->resetTo($second, 'hard'));
        self::assertSame("one\ntwo\nthree\nfour\n", file_get_contents("$this->dir/a.txt"));
        $this->wait($this->git->resetTo($first, 'mixed'));
        self::assertSame("one\ntwo\nthree\nfour\n", file_get_contents("$this->dir/a.txt"));
        self::assertCount(1, $this->wait($this->git->status())['files']);
        $this->wait($this->git->resetTo($first, 'hard'));
        self::assertSame("one\ntwo\nthree\n", file_get_contents("$this->dir/a.txt"));
        // cherry-pick the "Add four" commit back
        $this->wait($this->git->cherryPick($second));
        self::assertSame("one\ntwo\nthree\nfour\n", file_get_contents("$this->dir/a.txt"));
        $this->wait($this->git->undoLastCommit());
        self::assertSame('First', $this->wait($this->git->log(null, 1))[0]['subject']);
        $this->expectException(GitException::class);
        $this->wait($this->git->resetTo('not-a-sha', 'hard'));
    }

    public function testStashesAreListedAppliedAndDropped(): void
    {
        $this->write('a.txt', "changed\n");
        $this->wait($this->git->stash('first stash'));
        $this->write('a.txt', "other\n");
        $this->wait($this->git->stash('second stash'));
        $list = $this->wait($this->git->stashes());
        self::assertCount(2, $list);
        self::assertStringContainsString('second stash', $list[0]['message']);
        $this->wait($this->git->stashApply(1));
        self::assertSame("changed\n", file_get_contents("$this->dir/a.txt"));
        $this->wait($this->git->rollback(['a.txt']));
        $this->wait($this->git->stashDrop(0));
        self::assertCount(1, $this->wait($this->git->stashes()));
        $this->wait($this->git->stashPop());
        self::assertSame([], $this->wait($this->git->stashes()));
    }

    public function testRemotesAreAddedChangedRemovedAndBranchesRenamed(): void
    {
        $this->wait($this->git->addRemote('origin', 'https://example.com/a.git'));
        $this->wait($this->git->setRemoteUrl('origin', 'https://example.com/b.git'));
        self::assertSame('https://example.com/b.git', $this->wait($this->git->remotes())[0]['url']);
        $this->wait($this->git->removeRemote('origin'));
        self::assertSame([], $this->wait($this->git->remotes()));
        $this->wait($this->git->renameBranch('main', 'trunk'));
        self::assertSame('trunk', $this->wait($this->git->status())['branch']);
    }

    public function testAnAuthenticationFailureSaysWhatToDo(): void
    {
        $this->wait($this->git->addRemote('origin', 'https://127.0.0.1:9/nope.git'));
        try {
            $this->wait($this->git->run(['fetch', 'origin'], true));
            self::fail('fetched from nowhere');
        } catch (GitException $e) {
            self::assertStringNotContainsString('Git > Credentials', $e->getMessage(), 'a refused connection is not a sign-in problem');
        }
        $hint = new \ReflectionMethod(Git::class, 'authHint');
        self::assertStringContainsString('Git > Credentials', $hint->invoke(null, "fatal: Authentication failed for 'https://github.com/x/y.git/'"));
        self::assertSame('', $hint->invoke(null, 'fatal: not a git repository'));
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
