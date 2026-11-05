// Git, beyond commit / push / branches (git.js): a commit's menu in the log (branch, tag, cherry-pick, revert, reset), remotes, tags, stashes,
// and the user and token of the https hosts git talks to (GitHub's come from the jumphp.net sign-in). Each call is a git.* command of Ide.php.
import { backend } from './backend.js';
import { el, clear, notify, confirm, prompt, dialog, menu, timeAgo, copyText } from './ui.js';

export class GitExtra {
  constructor(git) {
    this.git = git;
    this.app = git.app;
  }

  /** Runs a git command with the usual progress, errors and refresh (git.js run). */
  run(command, payload, success, reload) { return this.git.run(command, payload, success, reload); }

  /** The right click of a commit in the Git log. */
  commitMenu(c, at) {
    const merging = this.git.status?.merging != null;
    menu([
      { head: `${c.short} ${c.subject}`.slice(0, 60) },
      { label: 'Copy Revision Number', action: () => copyText(c.sha) },
      '-',
      { label: 'New Branch from Here…', action: () => this.branchFrom(c) },
      { label: 'New Tag on This Commit…', action: () => this.tagOn(c) },
      '-',
      { label: 'Cherry-Pick', action: () => this.run('git.cherryPick', { sha: c.sha }, 'Cherry-picked', true), disabled: merging },
      { label: 'Revert Commit', action: () => this.run('git.revert', { sha: c.sha }, 'Reverted (a new commit)', true), disabled: merging },
      '-',
      { label: 'Reset Current Branch to Here…', action: () => this.reset(c), disabled: merging },
    ], at);
  }

  async branchFrom(c) {
    const name = (await prompt('New branch', { label: `from ${c.short}`, ok: 'Create' }))?.trim();
    if (!name) return;
    try {
      await backend.invoke('git.checkout', { branch: name, create: true });
      await backend.invoke('git.reset', { sha: c.sha, mode: 'hard' });
      await this.app.editors.reloadFromDisk();
      notify(`On new branch ${name}, at ${c.short}`, 'success', { title: 'Git' });
    } catch (e) { notify(e.message, 'error', { title: 'Git' }); }
    this.git.refresh();
  }

  async tagOn(c) {
    const name = (await prompt('New tag', { label: `on ${c.short}: a version name (v1.0)`, ok: 'Tag' }))?.trim();
    if (!name) return;
    await this.run('git.tagCreate', { name, revision: c.sha }, `Tag ${name} made`);
  }

  async reset(c) {
    const body = el('div', {}, el('p', {}, `Move ${this.git.status?.branch || 'the current branch'} to ${c.short}: “${c.subject}”.`),
      ...[['mixed', 'Keep the changes in the files (not staged)'], ['soft', 'Keep the changes staged'], ['hard', 'Throw the changes away (files go back to that commit)']]
        .map(([v, label], i) => el('label.checkbox', { style: { display: 'block', color: v === 'hard' ? 'var(--red)' : '' } }, el('input', { type: 'radio', name: 'resetMode', value: v, checked: i === 0 }), ' ' + label)),
      el('p.pub-faint', {}, 'Commits already pushed need a force push afterwards (Branches > Push, force with lease).'));
    dialog({ title: 'Reset Current Branch', body, buttons: [{ label: 'Cancel' }, { label: 'Reset', danger: true, action: async () => {
      const mode = body.querySelector('input:checked').value;
      if (mode === 'hard' && !(await confirm('Reset hard', 'The changes not committed are lost for good. Reset?', { ok: 'Reset', danger: true }))) return false;
      await this.run('git.reset', { sha: c.sha, mode }, `Reset to ${c.short}`, true);
    } }] });
  }

  async remotes() {
    const body = el('div');
    const d = dialog({ title: 'Git Remotes', wide: true, body, buttons: [{ label: 'Close' }] });
    const draw = async () => {
      clear(body);
      const list = await backend.invoke('git.remotes').catch(() => []);
      for (const r of list) {
        body.append(el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center', margin: '4px 0' } }, el('b', { style: { width: '80px' } }, r.name), el('span', { style: { flex: 1, wordBreak: 'break-all' } }, r.url),
          el('button.btn.small', { onclick: async () => { const url = (await prompt('Remote ' + r.name, { value: r.url, ok: 'Change URL' }))?.trim(); if (url) { await this.call('git.remoteSetUrl', { name: r.name, url }); draw(); } } }, 'Change…'),
          el('button.btn.small.danger', { onclick: async () => { if (await confirm('Remove the remote', `Remove ${r.name}? The repository on the server stays.`, { ok: 'Remove', danger: true })) { await this.call('git.remoteRemove', { name: r.name }); draw(); } } }, 'Remove')));
      }
      if (!list.length) body.append(el('p.pub-faint', {}, 'No remote: nothing can be pushed. Add one, or “Create its GitHub repository”.'));
      const name = el('input.input', { placeholder: 'name (origin)', style: { width: '120px' } });
      const url = el('input.input', { placeholder: 'https://… or git@…', style: { flex: 1 } });
      body.append(el('div', { style: { display: 'flex', gap: '8px', marginTop: '12px' } }, name, url,
        el('button.btn.primary.small', { onclick: async () => { if (name.value.trim() && url.value.trim()) { await this.call('git.remoteAdd', { name: name.value.trim(), url: url.value.trim() }); draw(); } } }, 'Add')));
    };
    draw();
    return d;
  }

  async tags() {
    const body = el('div');
    dialog({ title: 'Git Tags', wide: true, body, buttons: [{ label: 'Close' }] });
    const draw = async () => {
      clear(body);
      const list = await backend.invoke('git.tags').catch(() => []);
      for (const t of list) {
        body.append(el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center', margin: '4px 0' } }, el('b', { style: { width: '110px' } }, t.name), el('span.pub-faint', { style: { width: '70px' } }, t.sha),
          el('span', { style: { flex: 1 } }, t.subject || '', ' ', el('span.pub-faint', {}, t.time ? timeAgo(t.time) : '')),
          el('button.btn.small', { onclick: async () => { await this.call('git.tagPush', { name: t.name }); notify(`Tag ${t.name} pushed`, 'success', { title: 'Git' }); } }, 'Push'),
          el('button.btn.small.danger', { onclick: async () => {
            if (!(await confirm('Delete the tag', `Delete ${t.name}?`, { ok: 'Delete', danger: true }))) return;
            await this.call('git.tagDelete', { name: t.name });
            draw();
          } }, 'Delete')));
      }
      if (!list.length) body.append(el('p.pub-faint', {}, 'No tags yet.'));
      const name = el('input.input', { placeholder: 'v1.0', style: { width: '120px' } });
      const message = el('input.input', { placeholder: 'message (optional: an annotated tag)', style: { flex: 1 } });
      body.append(el('div', { style: { display: 'flex', gap: '8px', marginTop: '12px' } }, name, message,
        el('button.btn.primary.small', { onclick: async () => { if (name.value.trim()) { await this.call('git.tagCreate', { name: name.value.trim(), message: message.value.trim() || undefined }); draw(); } } }, 'Tag HEAD')));
    };
    draw();
  }

  async stashes() {
    const body = el('div');
    dialog({ title: 'Git Stashes', wide: true, body, buttons: [{ label: 'Close' }] });
    const draw = async () => {
      clear(body);
      const list = await backend.invoke('git.stashes').catch(() => []);
      for (const s of list) {
        body.append(el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center', margin: '4px 0' } }, el('span', { style: { flex: 1 } }, s.message, ' ', el('span.pub-faint', {}, timeAgo(s.time))),
          el('button.btn.small', { onclick: async () => { await this.run('git.stashApply', { index: s.index }, 'Stash applied (it is kept)', true); draw(); } }, 'Apply'),
          el('button.btn.small', { onclick: async () => { await this.run('git.stashPop', { index: s.index }, 'Stash applied and removed', true); draw(); } }, 'Pop'),
          el('button.btn.small.danger', { onclick: async () => { if (await confirm('Drop the stash', `Throw away “${s.message}”?`, { ok: 'Drop', danger: true })) { await this.call('git.stashDrop', { index: s.index }); draw(); } } }, 'Drop')));
      }
      if (!list.length) body.append(el('p.pub-faint', {}, 'Nothing is put aside. Branches > Stash Changes puts the changes aside.'));
    };
    draw();
  }

  /** The user and token of an https host (GitLab, a company's server, another GitHub): git here uses them for that host. */
  async credentials() {
    const body = el('div');
    dialog({ title: 'Git Credentials', wide: true, body, buttons: [{ label: 'Close' }] });
    const draw = async () => {
      clear(body);
      body.append(el('p.pub-faint', {}, 'GitHub works through the jumphp.net sign-in (account menu). For any other https server, give a user and a personal access token: git uses them for that host only. They stay on this computer.'));
      const list = await backend.invoke('git.credentials').catch(() => []);
      for (const c of list) {
        body.append(el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center', margin: '4px 0' } }, el('b', { style: { width: '180px' } }, c.host), el('span', { style: { flex: 1 } }, c.user + ' · token saved'),
          el('button.btn.small.danger', { onclick: async () => { await this.call('git.credentialRemove', { host: c.host }); draw(); } }, 'Remove')));
      }
      const host = el('input.input', { placeholder: 'gitlab.com', style: { width: '160px' } });
      const user = el('input.input', { placeholder: 'user (oauth2)', style: { width: '120px' } });
      const token = el('input.input', { type: 'password', placeholder: 'token', style: { flex: 1 } });
      body.append(el('div', { style: { display: 'flex', gap: '8px', marginTop: '12px' } }, host, user, token,
        el('button.btn.primary.small', { onclick: async () => { await this.call('git.credentialSet', { host: host.value, user: user.value, token: token.value }); draw(); } }, 'Save')));
    };
    draw();
  }

  /** A command whose error is shown, not thrown. */
  async call(command, payload) {
    try { return await backend.invoke(command, payload); } catch (e) { notify(e.message, 'error', { title: 'Git' }); return null; }
  }
}
