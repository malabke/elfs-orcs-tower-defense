// Git in the IDE: the Commit tool window (changes, diff, commit and push), the branch popup, the Git log tool
// window, annotate (blame), update / push. On the web IDE the same calls work on the drafts (commit = push to
// GitHub through jumphp.net); what a host cannot do is hidden by project.info's capabilities.
import { backend } from './backend.js';
import { el, clear, icon, basename, dirname, fileIcon, notify, confirm, prompt, chooser, timeAgo, menu, prefs } from './ui.js';
import { monaco } from './editor.js';
import { GitExtra } from './git-extra.js';

export class Git {
  constructor(app) {
    this.app = app;
    this.caps = app.project?.capabilities || {};
    this.enabled = !!this.caps.git;
    this.status = null;
    this.byPath = new Map();
    this.checked = null;
    this.commitNode = el('div.commit-box');
    this.logNode = el('div', { style: { height: '100%', display: 'flex' } });
    this.extra = new GitExtra(this);
    this.message = prefs.get('commitMessage:' + app.projectKey, '');
    this.blame = new Map();       // path -> decorations
    if (this.enabled) backend.on('git.output', (p) => this.app.console?.append('git', p.chunk));
  }

  title() { return 'Commit'; }

  async refresh() {
    if (!this.enabled) return;
    try {
      this.status = await backend.invoke('git.status');
    } catch (e) {
      this.status = null;
      return;
    }
    if (!this.status) return;
    this.byPath = new Map(this.status.files.map((f) => [f.path, f]));
    this.app.onGitStatus?.(this.status);
    if (this.checked === null) this.checked = new Set(this.status.files.filter((f) => f.status !== 'untracked').map((f) => f.path));
    for (const f of this.status.files) if (!this.seen?.has(f.path) && f.status !== 'untracked') this.checked.add(f.path);
    this.seen = new Set(this.status.files.map((f) => f.path));
    this.renderCommit();
  }

  statusOf(path) {
    return this.byPath.get(path)?.status || null;
  }

  folderStatus(dir) {
    if (!this.status) return null;
    const prefix = dir ? dir + '/' : '';
    return this.status.files.some((f) => f.path.startsWith(prefix) && f.status !== 'untracked' && f.status !== 'deleted') ? 'modified' : null;
  }

  // commit tool window --------------------------------------------------------------------------------------

  renderCommit() {
    const node = clear(this.commitNode);
    if (!this.status) return node.append(el('div.placeholder', {}, 'Not a Git repository.'));
    const files = this.status.files;
    const tracked = files.filter((f) => f.status !== 'untracked');
    const untracked = files.filter((f) => f.status === 'untracked');
    const list = el('div.tree', { tabIndex: 0 });
    const group = (title, items) => {
      if (!items.length) return;
      const all = items.every((f) => this.checked.has(f.path));
      list.append(el('div.row.open', { style: { paddingLeft: '6px' } }, el('span.twisty'),
        el('input', { type: 'checkbox', checked: all, onclick: (e) => { e.stopPropagation(); for (const f of items) e.target.checked ? this.checked.add(f.path) : this.checked.delete(f.path); this.renderCommit(); } }),
        el('span.label', {}, title), el('span.hint', {}, `${items.length} file${items.length > 1 ? 's' : ''}`)));
      for (const f of items) {
        list.append(el(`div.row.st-${f.status}`, {
          style: { paddingLeft: '28px' },
          title: `${f.path} (${f.status})`,
          ondblclick: () => this.diff(f.path),
          onclick: (e) => { if (e.target.type !== 'checkbox') this.diff(f.path); },
          oncontextmenu: (e) => { e.preventDefault(); this.fileMenu(f, e); },
        }, el('input', { type: 'checkbox', checked: this.checked.has(f.path), onclick: (e) => { e.stopPropagation(); e.target.checked ? this.checked.add(f.path) : this.checked.delete(f.path); this.updateButtons(); } }),
        fileIcon(f.path), el('span.label', {}, basename(f.path)), el('span.hint', {}, dirname(f.path))));
      }
    };
    group(this.caps.gitMode === 'drafts' ? 'Changes (drafts)' : 'Changes', tracked);
    group('Unversioned Files', untracked);
    if (!files.length) list.append(el('div.placeholder', {}, 'No changes. Edit a file and it shows here.'));
    const textarea = el('textarea.input', { placeholder: 'Commit message', value: this.message, oninput: (e) => { this.message = e.target.value; prefs.set('commitMessage:' + this.app.projectKey, this.message); this.updateButtons(); } });
    textarea.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.ctrlKey) this.commit(false); });
    const amend = el('input', { type: 'checkbox' });
    this.buttons = {
      commit: el('button.btn.primary', { onclick: () => this.commit(this.caps.gitMode === 'drafts') }, this.caps.gitMode === 'drafts' ? 'Commit and Push' : 'Commit'),
      push: this.caps.gitMode === 'drafts' ? null : el('button.btn', { onclick: () => this.commit(true) }, 'Commit and Push…'),
    };
    this.amend = amend;
    node.append(
      ...[this.mergePanel()].filter(Boolean),
      el('div.tw-header', {}, el('span.tw-title', {}, 'Commit'), el('span.tw-spacer'), el('div.tw-actions', {},
        el('button', { title: 'Refresh', onclick: () => this.refresh() }, icon('refresh')),
        el('button', { title: 'Rollback the selected files', onclick: () => this.rollback([...this.checked]) }, icon('rollback')),
        el('button', { title: 'Show diff', onclick: () => { const f = [...this.checked][0]; if (f) this.diff(f); } }, icon('diff')))),
      list,
      el('div.commit-form', {}, textarea,
        this.caps.gitMode === 'drafts' ? null : el('label.checkbox', {}, amend, 'Amend last commit'),
        el('div.actions', {}, this.buttons.commit, this.buttons.push,
          el('span', { style: { color: 'var(--text-3)', fontSize: '12px' } }, this.status.branch ? `on ${this.status.branch}${this.status.ahead ? ` · ${this.status.ahead}↑` : ''}${this.status.behind ? ` · ${this.status.behind}↓` : ''}` : ''))));
    this.updateButtons();
  }

  /** A merge stopped on conflicts: each conflicted file (compare, open, take ours / theirs / both, resolved), then commit or abort. */
  mergePanel() {
    if (!this.caps.branches || this.status.merging == null) return null;
    const conflicts = this.status.files.filter((f) => f.conflict);
    const what = this.status.merging ? `Merging ${this.status.merging} into ${this.status.branch || 'HEAD'}` : 'Merge in progress';
    const take = (f, how, label, hint) => el('button.btn', { title: hint, onclick: () => this.resolve(f.path, how) }, label);
    return el('div.merge-box', { style: { padding: '8px', borderBottom: '1px solid var(--border)', background: 'var(--bg-2)' } },
      el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' } }, icon('branch'),
        el('strong', {}, what), el('span.hint', {}, conflicts.length ? `${conflicts.length} conflict${conflicts.length > 1 ? 's' : ''} left` : 'every conflict resolved')),
      ...conflicts.map((f) => el('div.merge-file', { style: { margin: '4px 0 8px' }, title: f.path },
        el('div.row.st-conflict', { style: { paddingLeft: '6px' }, ondblclick: () => this.compareConflict(f.path) }, fileIcon(f.path), el('span.label', {}, f.path)),
        el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '4px', paddingLeft: '28px' } },
        el('button.btn', { title: 'Their version beside yours', onclick: () => this.compareConflict(f.path) }, 'Compare'),
        el('button.btn', { title: 'Edit the file with its <<<<<<< / >>>>>>> markers, then mark it resolved', onclick: () => this.app.editors.open(f.path) }, 'Open'),
        el('button.btn', { title: 'Each conflict: ours, theirs or both', onclick: () => this.extra.resolveBlocks(f.path) }, 'By block…'),
        take(f, 'ours', 'Ours', `Keep ${this.status.branch || 'the current branch'}'s version`),
        take(f, 'theirs', 'Theirs', `Take ${this.status.merging || 'the merged branch'}'s version`),
        take(f, 'both', 'Both', 'Ours, then theirs'),
        take(f, 'mark', 'Resolved', 'The file as it is now (its markers edited away)')))),
      el('div', { style: { display: 'flex', gap: '8px', marginTop: '6px' } },
        el('button.btn.primary', { disabled: conflicts.length > 0, onclick: () => this.commitMerge() }, 'Commit Merge'),
        el('button.btn', { onclick: () => this.abortMerge() }, 'Abort Merge')));
  }

  async compareConflict(path) {
    try {
      const v = await backend.invoke('git.conflict', { path });
      this.app.editors.openDiff(path, { original: v.ours, modified: v.theirs, title: `${basename(path)} (ours ↔ theirs)` });
    } catch (e) { notify(e.message, 'error', { title: 'Git' }); }
  }

  async resolve(path, how) {
    await this.app.editors.saveAll(true);
    try {
      await backend.invoke('git.resolve', { path, how });
      if (how !== 'mark') await this.app.editors.reloadFromDisk();
    } catch (e) { notify(e.message, 'error', { title: 'Not resolved' }); }
    this.refresh();
  }

  async commitMerge() {
    try {
      const { sha } = await backend.invoke('git.commitMerge');
      notify(`Merge committed ${String(sha || '').slice(0, 8)}`, 'success', { title: 'Git', actions: [{ label: 'Push', action: () => this.push() }] });
    } catch (e) { notify(e.message, 'error', { title: 'Merge not committed' }); }
    this.refresh();
  }

  async abortMerge() {
    if (!(await confirm('Abort Merge', 'Give up this merge? The branch goes back to how it was before it (what you resolved is lost).', { ok: 'Abort Merge', danger: true }))) return;
    await this.run('git.abortMerge', {}, 'Merge aborted', true);
  }

  updateButtons() {
    if (!this.buttons) return;
    const ok = this.message.trim() !== '' && (this.caps.gitMode === 'drafts' ? this.status.files.length > 0 : this.checked.size > 0 || this.amend?.checked);
    this.buttons.commit.disabled = !ok;
    if (this.buttons.push) this.buttons.push.disabled = !ok;
  }

  fileMenu(f, e) {
    menu([
      { label: 'Show Diff', icon: 'diff', action: () => this.diff(f.path) },
      { label: 'Open File', icon: 'file', action: () => this.app.editors.open(f.path), disabled: f.status === 'deleted' },
      { label: 'Rollback…', icon: 'rollback', action: () => this.rollback([f.path]) },
      this.caps.log ? { label: 'Show History', icon: 'history', action: () => this.showLog(f.path) } : null,
      f.status !== 'untracked' && f.status !== 'deleted' && this.caps.gitMode !== 'drafts' ? { label: 'Stage by Change…', icon: 'diff', action: () => this.extra.hunks(f.path) } : null,
    ].filter(Boolean), { x: e.clientX, y: e.clientY });
  }

  async commit(push) {
    await this.app.editors.saveAll(true);
    if (push && this.app.project.needsRepository) { push = false; this.noRepoHint = true; }   // a local project: nothing to push to yet
    const paths = this.caps.gitMode === 'drafts' ? undefined : [...this.checked];
    const message = this.message.trim();
    if (!message) return;
    const task = this.app.task?.(push ? 'Committing and pushing (git)…' : 'Committing (git)…', { open: () => this.app.showTool('commit') });
    try {
      const result = await backend.invoke('git.commit', { message, paths, push, amend: !!this.amend?.checked });
      this.message = '';
      prefs.set('commitMessage:' + this.app.projectKey, '');
      this.checked = null;
      notify(`${result.pushed ? 'Committed and pushed' : 'Committed'} ${String(result.sha || '').slice(0, 8)}`, 'success', {
        title: 'Git', actions: result.url ? [{ label: 'Open on GitHub', action: () => this.app.openUrl(result.url) }]
          : this.noRepoHint ? [{ label: 'Create its GitHub repository…', action: () => this.app.createRepository() }] : [] });
      this.noRepoHint = false;
      this.app.afterCommit?.(result);
    } catch (e) {
      notify(e.message, 'error', { title: push ? 'Commit and push failed' : 'Commit failed' });
    } finally {
      task?.();
      this.refresh();
    }
  }

  /** One click: every change (new files included) committed with a message asked here, then pushed. No Claude, no tool window. */
  async commitAll() {
    await this.app.editors.saveAll(true);
    await this.refresh();
    const files = this.status?.files || [];
    if (!files.length) return notify('Nothing to commit: no changes.', 'info', { title: 'Git' });
    const message = (await prompt('Commit all and push', { value: this.lastQuickMessage || 'Update', label: `${files.length} file${files.length > 1 ? 's' : ''} will be committed and pushed.`, ok: 'Commit and Push' }))?.trim();
    if (!message) return;
    this.lastQuickMessage = message;
    const task = this.app.task?.('Committing and pushing (git)…', { open: () => this.app.showTool('commit') });
    try {
      const local = !!this.app.project.needsRepository;   // a local project: committed here, nothing to push to until it has its repository
      const result = await backend.invoke('git.commit', { message, paths: this.caps.gitMode === 'drafts' ? undefined : files.map((f) => f.path), push: !local });
      this.checked = null;
      notify(`${local ? 'Committed' : 'Committed and pushed'} ${String(result.sha || '').slice(0, 8)}`, 'success', {
        title: 'Git', actions: result.url ? [{ label: 'Open on GitHub', action: () => this.app.openUrl(result.url) }]
          : local ? [{ label: 'Create its GitHub repository…', action: () => this.app.createRepository() }] : [] });
      this.app.afterCommit?.(result);
    } catch (e) {
      notify(e.message, 'error', { title: 'Commit and push failed' });
    } finally {
      task?.();
      this.refresh();
    }
  }

  async rollback(paths) {
    if (!paths.length) return;
    if (!(await confirm('Rollback Changes', `Throw away the changes of ${paths.length === 1 ? paths[0] : paths.length + ' files'}? New files are deleted.`, { ok: 'Rollback', danger: true }))) return;
    try {
      await backend.invoke('git.rollback', { paths });
      await this.app.editors.reloadFromDisk();
      this.app.tree?.refresh();
      this.refresh();
    } catch (e) { notify(e.message, 'error', { title: 'Rollback failed' }); }
  }

  async stage(paths) {
    await backend.invoke('git.stage', { paths }).catch((e) => notify(e.message, 'error'));
    this.refresh();
  }

  /** A diff tab: HEAD (or the published version) against the file now. */
  async diff(path) {
    if (!path) return;
    const f = this.byPath.get(path);
    const { content } = await backend.invoke('git.original', { path }).catch(() => ({ content: '' }));
    if (f?.status === 'deleted') return this.app.editors.openDiff(path, { original: content, modified: '', title: basename(path) + ' (deleted)' });
    this.app.editors.openDiff(path, { original: content, title: basename(path) + (this.caps.gitMode === 'drafts' ? ' (published ↔ draft)' : ' (HEAD ↔ local)') });
  }

  // branches, update, push --------------------------------------------------------------------------------

  async branchPopup(at) {
    if (!this.caps.branches) return;
    const branches = await backend.invoke('git.branches').catch((e) => { notify(e.message, 'error'); return []; });
    const local = branches.filter((b) => !b.remote);
    const remote = branches.filter((b) => b.remote);
    const items = [
      { label: 'Update Project…', icon: 'pull', hint: 'Ctrl+T', action: () => this.pull(), group: 'Actions' },
      { label: 'Commit…', icon: 'commit', hint: 'Ctrl+K', action: () => this.app.showTool('commit'), group: 'Actions' },
      { label: 'Push…', icon: 'push', hint: 'Ctrl+Shift+K', action: () => this.push(), group: 'Actions' },
      { label: 'New Branch…', icon: 'plus', action: () => this.newBranch(), group: 'Actions' },
      { label: 'Stash Changes', icon: 'package', action: () => this.run('git.stash', {}, 'Changes stashed'), group: 'Actions' },
      { label: 'Unstash (pop)', icon: 'package', action: () => this.run('git.stashPop', {}, 'Stash applied'), group: 'Actions' },
      { label: 'Stashes…', icon: 'package', action: () => this.extra.stashes(), group: 'Actions' },
      { label: 'Tags…', icon: 'branch', action: () => this.extra.tags(), group: 'Actions' },
      { label: 'Remotes…', icon: 'globe', action: () => this.extra.remotes(), group: 'Actions' },
      { label: 'Submodules…', icon: 'folder', action: () => this.extra.submodules(), group: 'Actions' },
      { label: 'Push, Force with Lease…', icon: 'push', action: () => this.forcePush(), group: 'Actions' },
      { label: 'Fetch (and prune)', icon: 'pull', action: () => this.run('git.fetch', {}, 'Fetched'), group: 'Actions' },
      ...local.map((b) => ({ label: (b.current ? '★ ' : '') + b.name, hint: b.upstream || b.sha, icon: 'branch', group: 'Local', action: () => this.branchMenu(b, at) })),
      ...remote.map((b) => ({ label: b.name, hint: b.sha, icon: 'branch', group: 'Remote', action: () => this.branchMenu(b, at) })),
    ];
    chooser(items, { at, placeholder: 'Search for branches and actions', width: 380 });
  }

  branchMenu(branch, at) {
    const merging = this.status?.merging != null;
    menu([
      { head: branch.name },
      { label: 'Checkout', action: () => this.checkout(branch.name), disabled: branch.current || merging },
      { label: `Merge into ${this.status?.branch || 'current'}`, action: () => this.merge(branch.name), disabled: branch.current || merging },
      { label: 'New Branch from here…', action: async () => { if (branch.current || await this.checkout(branch.name)) this.newBranch(); }, disabled: merging },
      branch.current && !branch.upstream ? { label: 'Push (publish this branch)', icon: 'push', action: () => this.push() } : null,
      !branch.remote ? { label: 'Rename…', action: () => this.renameBranch(branch.name) } : null,
      !branch.remote ? { label: 'Delete…', action: () => this.deleteBranch(branch.name), disabled: branch.current } : null,
      branch.remote ? { label: 'Delete on the Server…', action: () => this.deleteRemoteBranch(branch.name) } : null,
    ].filter(Boolean), at);
  }

  async renameBranch(name) {
    const next = (await prompt('Rename branch', { value: name, ok: 'Rename' }))?.trim();
    if (next && next !== name) await this.run('git.renameBranch', { old: name, new: next }, `${name} is now ${next}`);
  }

  async deleteRemoteBranch(name) {
    const [remote, ...rest] = name.split('/');
    if (!(await confirm('Delete on the server', `Delete the branch ${rest.join('/')} of ${remote} (on the server)? Local copies stay.`, { ok: 'Delete', danger: true }))) return;
    await this.run('git.deleteRemoteBranch', { remote, branch: rest.join('/') }, 'Branch deleted on the server');
  }

  async forcePush() {
    if (!(await confirm('Push, force with lease', 'Replaces the branch on the server with yours (after a reset or an amend). Refused if someone else pushed meanwhile.', { ok: 'Force push', danger: true }))) return;
    await this.run('git.pushForce', {}, 'Pushed (force with lease)');
  }

  /** Switches branch; local changes are offered a stash first (or the switch is cancelled). @return {Promise<boolean>} */
  async checkout(name, create = false) {
    if (this.status?.merging != null) { notify('Finish or abort the merge in progress first (Commit window).', 'warning', { title: 'Git' }); return false; }
    await this.app.editors.saveAll(true);
    await this.refresh();
    const changed = (this.status?.files || []).filter((f) => f.status !== 'untracked');
    if (!create && changed.length) {
      if (!(await confirm('Uncommitted Changes', `${changed.length} file${changed.length > 1 ? 's have' : ' has'} changes not committed. Stash them (put aside, "Unstash" brings them back) and check out ${name}?`, { ok: 'Stash and Checkout' }))) return false;
      try { await backend.invoke('git.stash', { message: `before checking out ${name}` }); } catch (e) { notify(e.message, 'error', { title: 'Stash failed' }); return false; }
      notify('Your changes are stashed: Branches > Unstash brings them back.', 'info', { title: 'Git' });
    }
    await this.run('git.checkout', { branch: name, create }, create ? `On new branch ${name}` : `Checked out ${name}`, true);
    return this.status?.branch === name.replace(/^origin\//, '');
  }

  async merge(name) {
    await this.app.editors.saveAll(true);
    const task = this.app.task?.(`Merging ${name} (git merge)…`, { open: () => this.app.showTool('commit') });
    try {
      const { conflicts } = await backend.invoke('git.merge', { branch: name });
      await this.app.editors.reloadFromDisk();
      this.app.tree?.refresh();
      if (conflicts?.length) {
        notify(`${conflicts.length} file${conflicts.length > 1 ? 's' : ''} in conflict: resolve them in the Commit window, then Commit Merge (or Abort Merge).`, 'warning', { title: `Merging ${name}` });
        this.app.showTool('commit');
      } else {
        notify(`Merged ${name}`, 'success', { title: 'Git' });
      }
    } catch (e) {
      notify(e.message, 'error', { title: 'Merge failed' });
    } finally {
      task?.();
      this.refresh();
    }
  }

  async deleteBranch(name) {
    const merged = await backend.invoke('git.isMerged', { branch: name }).catch(() => false);
    const ok = merged
      ? await confirm('Delete Branch', `Delete ${name}? It is merged into ${this.status?.branch || 'the current branch'}: nothing is lost.`, { ok: 'Delete', danger: true })
      : await confirm('Delete Unmerged Branch', `${name} has commits that are not merged into ${this.status?.branch || 'the current branch'}: deleting it loses them (unless it was pushed). Delete it anyway?`, { ok: 'Delete Anyway', danger: true });
    if (ok) this.run('git.deleteBranch', { branch: name, force: !merged }, 'Branch deleted');
  }

  async newBranch() {
    const name = await prompt('New Branch', { placeholder: 'feature/my-change', ok: 'Create' });
    if (name) this.checkout(name.trim(), true);
  }

  async run(command, payload, success, reload = false) {
    const what = {
      'git.pull': 'Updating the project (git pull)…', 'git.merge': `Merging ${payload?.branch} (git merge)…`,
      'git.checkout': payload?.create ? `Creating the branch ${payload?.branch}…` : `Checking out ${payload?.branch}…`,
    }[command] || `Git: ${command.replace(/^git\./, '')}…`;
    const task = this.app.task?.(what, { open: () => this.app.showTool('git') });
    try {
      await backend.invoke(command, payload);
      if (success) notify(success, 'success', { title: 'Git' });
      if (reload) { await this.app.editors.reloadFromDisk(); this.app.tree?.refresh(); }
    } catch (e) {
      notify(e.message, 'error', { title: 'Git' });
    } finally {
      task?.();
      await this.refresh();
    }
  }

  pull() { return this.run('git.pull', {}, 'Project updated', true); }

  async push() {
    if (this.caps.gitMode === 'drafts') return this.app.showTool('commit');
    const task = this.app.task?.('Pushing to GitHub (git push)…', { open: () => this.app.showTool('git') });
    try {
      await backend.invoke('git.push');
      notify('Pushed', 'success', { title: 'Git' });
      this.app.afterPush?.();
    } catch (e) {
      notify(e.message, 'error', { title: 'Push failed' });
    } finally {
      task?.();
      this.refresh();
    }
  }

  // log -------------------------------------------------------------------------------------------------------

  async showLog(path = null) {
    if (!this.caps.log) return;
    // the content goes in BEFORE the window is shown: showTool('git') calls showLog() again while the log is empty (app.js), which showed the
    // tool again... thousands of nested showLog / `git log` runs (the IDE froze on "Indexing" with the Git window open at start)
    clear(this.logNode);
    const commits = el('div.tree', { tabIndex: 0, style: { flex: '1', overflow: 'auto', minWidth: '0' } });
    const details = el('div', { style: { width: '38%', borderLeft: '1px solid var(--border-2)', overflow: 'auto' } });
    this.logNode.append(commits, details);
    this.app.showTool('git');
    commits.append(el('div.placeholder', {}, 'Loading history…'));
    let list = [];
    try { list = await backend.invoke('git.log', { path, limit: 300 }); } catch (e) { clear(commits).append(el('div.placeholder', {}, e.message)); return; }
    clear(commits);
    if (path) commits.append(el('div.section-title', {}, icon('history'), 'History of ' + path));
    const graph = path ? [] : this.extra.graph(list);
    for (const [n, c] of list.entries()) {
      const refs = c.refs ? c.refs.split(', ').filter(Boolean).map((r) => el('span.p-tag', { style: { marginRight: '4px', fontSize: '10px' } }, r.replace('HEAD -> ', '★ '))) : [];
      const row = el('div.row', { style: { paddingLeft: '10px' }, oncontextmenu: (e) => { e.preventDefault(); this.extra.commitMenu(c, e); }, onclick: () => { commits.querySelectorAll('.row').forEach((r) => r.classList.remove('sel')); row.classList.add('sel'); this.commitDetails(c, details, path); } },
        graph[n] || el('span', { style: { color: 'var(--accent-2)', width: '10px' } }, '●'), ...refs, el('span.label', {}, c.subject),
        el('span.right', {}, `${c.author} · ${timeAgo(c.time)}`));
      commits.append(row);
    }
    if (!list.length) commits.append(el('div.placeholder', {}, 'No commits yet.'));
  }

  async commitDetails(c, node, onlyPath) {
    clear(node);
    node.append(el('div.panel-pad', {}, el('div', { style: { fontWeight: 600, marginBottom: '4px' } }, c.subject),
      el('div', { style: { color: 'var(--text-3)', fontSize: '12px' } }, `${c.short} · ${c.author} <${c.email}> · ${new Date(c.time * 1000).toLocaleString()}`)));
    const files = await backend.invoke('git.commitFiles', { sha: c.sha }).catch(() => []);
    for (const f of files) {
      const status = { A: 'added', D: 'deleted', M: 'modified', R: 'modified' }[f.status] || 'modified';
      node.append(el(`div.row.st-${status}`, { style: { paddingLeft: '12px' }, onclick: () => this.commitDiff(c, f) },
        fileIcon(f.path), el('span.label', {}, basename(f.path)), el('span.hint', {}, dirname(f.path))));
    }
  }

  async commitDiff(c, f) {
    const parent = c.parents[0];
    const [before, after] = await Promise.all([
      parent && f.status !== 'A' ? backend.invoke('git.original', { path: f.from || f.path, revision: parent }).then((r) => r.content) : '',
      f.status !== 'D' ? backend.invoke('git.original', { path: f.path, revision: c.sha }).then((r) => r.content) : '',
    ]);
    this.app.editors.openDiff(f.path, { original: before, modified: after, title: `${basename(f.path)} @ ${c.short}` });
  }

  // annotate ---------------------------------------------------------------------------------------------------

  async toggleBlame() {
    if (!this.caps.blame) return;
    const path = this.app.editors.activePath();
    const editor = this.app.editors.editor();
    if (!path || !editor) return;
    if (this.blame.has(path)) {
      editor.removeDecorations(this.blame.get(path));
      this.blame.delete(path);
      return;
    }
    const model = editor.getModel();
    const lines = await backend.invoke('git.blame', { path, content: model.getValue() }).catch((e) => { notify(e.message, 'error'); return null; });
    if (!lines) return;
    let previous = null;
    const decorations = lines.map((l) => {
      const fresh = l.sha.startsWith('0000000');
      const text = fresh ? 'not committed' : `${new Date(l.time * 1000).toLocaleDateString()} ${l.author}`.slice(0, 26);
      const same = l.sha === previous;
      previous = l.sha;
      return { range: new monaco.Range(l.line, 1, l.line, 1), options: { before: { content: (same ? '' : text).padEnd(28, ' '), inlineClassName: 'blame-text', cursorStops: monaco.editor.InjectedTextCursorStops.None },
        hoverMessage: fresh ? undefined : { value: `**${l.summary}**\n\n${l.author} · ${new Date(l.time * 1000).toLocaleString()} · \`${l.sha.slice(0, 8)}\`` } } };
    });
    this.blame.set(path, editor.deltaDecorations([], decorations));
  }
}
