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

  // hunks ---------------------------------------------------------------------------------------------------

  /** One change of a file at a time: what goes into the next commit (staged) and what stays out. */
  async hunks(path) {
    const body = el('div');
    dialog({ title: 'Stage by change: ' + path, wide: true, body, buttons: [{ label: 'Close' }] });
    const draw = async () => {
      clear(body);
      const [unstaged, staged] = await Promise.all([backend.invoke('git.hunks', { path }).catch(() => []), backend.invoke('git.hunks', { path, staged: true }).catch(() => [])]);
      const section = (title, list, unstage) => {
        body.append(el('h4', {}, `${title} (${list.length})`));
        for (const h of list) {
          body.append(el('div', { style: { margin: '6px 0', border: '1px solid var(--border-2)', borderRadius: '4px' } },
            el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '2px 8px', background: 'var(--bg-2)' } }, el('code', { style: { flex: 1 } }, h.header),
              el('span', { style: { color: 'var(--green)' } }, '+' + h.added), el('span', { style: { color: 'var(--red)' } }, '−' + h.removed),
              el('button.btn.small', { onclick: async () => { await this.call('git.stageHunk', { path, index: h.index, unstage }); await this.git.refresh(); draw(); } }, unstage ? 'Unstage' : 'Stage')),
            el('pre', { style: { margin: 0, padding: '4px 8px', maxHeight: '180px', overflow: 'auto', fontSize: '12px' } }, ...h.text.split('\n').slice(1).map((l) => el('div', { style: { color: l.startsWith('+') ? 'var(--green)' : l.startsWith('-') ? 'var(--red)' : '' } }, l || ' ')))));
        }
      };
      section('Staged: in the next commit', staged, true);
      section('Not staged', unstaged, false);
      if (!staged.length && !unstaged.length) body.append(el('p.pub-faint', {}, 'No change in this file (a new file is staged whole, with its check box).'));
    };
    draw();
  }

  // conflicts, one block at a time --------------------------------------------------------------------------

  /** A file with <<<<<<< ======= >>>>>>> markers: for each block, ours / theirs / both, then the file is written and marked resolved. */
  async resolveBlocks(path) {
    let text;
    try { text = (await backend.invoke('file.read', { path })).content ?? ''; } catch (e) { return notify(e.message, 'error', { title: 'Git' }); }
    const re = /^<<<<<<< ([^\n]*)\n([\s\S]*?)(?:^\|\|\|\|\|\|\| [^\n]*\n[\s\S]*?)?^=======\n([\s\S]*?)^>>>>>>> ([^\n]*)\n?/gm;
    const blocks = [...text.matchAll(re)].map((m) => ({ start: m.index, end: m.index + m[0].length, ours: m[2], theirs: m[3], oursName: m[1], theirsName: m[4], choice: 'both' }));
    if (!blocks.length) return notify('No conflict markers left in this file.', 'info', { title: 'Git' });
    const body = el('div');
    blocks.forEach((b, i) => {
      const radio = (value, label) => el('label.checkbox', { style: { marginRight: '14px' } }, el('input', { type: 'radio', name: 'blk' + i, checked: b.choice === value, onchange: () => { b.choice = value; } }), ' ' + label);
      const code = (t, color) => el('pre', { style: { flex: 1, margin: 0, padding: '4px 8px', overflow: 'auto', maxHeight: '140px', fontSize: '12px', borderLeft: `3px solid ${color}` } }, t || ' ');
      body.append(el('div', { style: { margin: '8px 0 14px' } }, el('div', {}, el('b', {}, `Conflict ${i + 1} of ${blocks.length}`), '  ', radio('ours', `Ours (${b.oursName})`), radio('theirs', `Theirs (${b.theirsName})`), radio('both', 'Both')),
        el('div', { style: { display: 'flex', gap: '8px' } }, code(b.ours, 'var(--accent)'), code(b.theirs, 'var(--green)'))));
    });
    dialog({ title: 'Resolve conflicts: ' + path, wide: true, body, buttons: [{ label: 'Cancel' }, { label: 'Apply and mark resolved', primary: true, action: async () => {
      let out = '';
      let at = 0;
      for (const b of blocks) {
        out += text.slice(at, b.start) + (b.choice === 'ours' ? b.ours : b.choice === 'theirs' ? b.theirs : b.ours + b.theirs);
        at = b.end;
      }
      out += text.slice(at);
      await backend.invoke('file.write', { path, content: out });
      await this.run('git.resolve', { path, how: 'mark' }, 'Conflicts resolved', true);
    } }] });
  }

  // submodules ----------------------------------------------------------------------------------------------

  async submodules() {
    const body = el('div');
    dialog({ title: 'Git Submodules', wide: true, body, buttons: [{ label: 'Close' }] });
    const draw = async () => {
      clear(body);
      const list = await backend.invoke('git.submodules').catch(() => []);
      for (const m of list) {
        body.append(el('div', { style: { display: 'flex', gap: '8px', margin: '4px 0' } }, el('b', { style: { flex: 1 } }, m.path), el('span.pub-faint', {}, `${m.sha} ${m.describe}`),
          el('span', { style: { color: m.state === 'ok' ? 'var(--green)' : 'var(--yellow)' } }, m.state === 'uninitialized' ? 'not cloned yet' : m.state === 'modified' ? 'another commit than the project\'s' : m.state === 'conflict' ? 'conflict' : 'up to date')));
      }
      if (!list.length) body.append(el('p.pub-faint', {}, 'No submodules (other repositories kept inside this one).'));
      const url = el('input.input', { placeholder: 'https://… repository', style: { flex: 1 } });
      const dir = el('input.input', { placeholder: 'folder (libs/name)', style: { width: '180px' } });
      body.append(el('div', { style: { display: 'flex', gap: '8px', marginTop: '12px' } },
        el('button.btn.small', { onclick: async () => { await this.run('git.submodulesUpdate', {}, 'Submodules cloned / updated', true); draw(); } }, 'Update all (init, recursive)'), url, dir,
        el('button.btn.primary.small', { onclick: async () => { if (url.value.trim() && dir.value.trim()) { await this.run('git.submoduleAdd', { url: url.value.trim(), path: dir.value.trim() }, 'Submodule added', true); draw(); } } }, 'Add')));
    };
    draw();
  }

  // ssh keys ------------------------------------------------------------------------------------------------

  async sshKeys() {
    const body = el('div');
    dialog({ title: 'SSH Keys', wide: true, body, buttons: [{ label: 'Close' }] });
    const draw = async () => {
      clear(body);
      body.append(el('p.pub-faint', {}, 'For repositories addressed git@host:owner/repo. The IDE uses the keys of ~/.ssh (and your ssh agent). Give the PUBLIC key to GitHub (Settings > SSH keys) or GitLab; the private key never leaves this computer.'));
      const keys = await backend.invoke('ssh.keys').catch(() => []);
      for (const k of keys) {
        body.append(el('div', { style: { margin: '8px 0' } }, el('b', {}, k.name), ' ', el('span.pub-faint', {}, `${k.type} ${k.comment}`),
          el('textarea.input', { readonly: true, rows: 2, style: { width: '100%', fontSize: '11px' }, value: k.publicKey }),
          el('button.btn.small', { onclick: () => { copyText(k.publicKey); notify('Public key copied.', 'success', { title: 'SSH' }); } }, 'Copy the public key')));
      }
      if (!keys.length) body.append(el('p', {}, 'No key yet.'));
      const name = el('input.input', { value: 'id_ed25519', style: { width: '160px' } });
      const host = el('input.input', { value: 'github.com', style: { width: '140px' } });
      body.append(el('div', { style: { display: 'flex', gap: '8px', marginTop: '12px', alignItems: 'center' } }, name,
        el('button.btn.small', { onclick: async () => { if (await this.call('ssh.generate', { name: name.value.trim() })) draw(); } }, 'Generate a key (ed25519)'), el('span', { style: { flex: 1 } }), host,
        el('button.btn.small', { onclick: async () => { const r = await this.call('ssh.test', { host: host.value.trim() }); if (r) notify(r.message || 'No answer', r.ok ? 'success' : 'warning', { title: 'ssh ' + host.value }); } }, 'Test the connection'),
        el('button.btn.small', { onclick: () => this.app.openUrl('https://github.com/settings/ssh/new') }, 'Add it on GitHub…')));
    };
    draw();
  }

  // the log's graph -----------------------------------------------------------------------------------------

  /** One small SVG per commit (lines of the branches, a dot, merges joining): lanes worked out from the parents, newest first. @return Node[] */
  graph(list) {
    const ns = 'http://www.w3.org/2000/svg';
    const colors = ['#4c8bf5', '#e5683c', '#4caf7d', '#c678dd', '#e5c07b', '#56b6c2', '#e06c75'];
    const W = 12;
    const H = 22;
    let lanes = [];
    const rows = [];
    for (const c of list) {
      const before = lanes.slice();
      let col = before.indexOf(c.sha);
      if (col < 0) { col = before.indexOf(null); if (col < 0) col = before.length; before[col] = c.sha; }
      const after = before.slice();
      const merges = [];
      // every lane that waits for this commit ends here (a branch joining)
      const joins = before.map((s, i) => (s === c.sha && i !== col ? i : -1)).filter((i) => i >= 0);
      for (const i of joins) after[i] = null;
      after[col] = c.parents[0] || null;
      for (const p of c.parents.slice(1)) {
        let at = after.indexOf(p);
        if (at < 0) { at = after.indexOf(null); if (at < 0 || at === col) at = after.length; after[at] = p; }
        merges.push(at);
      }
      rows.push({ before, after, col, joins, merges });
      lanes = after;
      while (lanes.length && lanes[lanes.length - 1] == null) lanes.pop();
    }
    const width = Math.max(1, ...rows.map((r) => Math.max(r.before.length, r.after.length))) * W + 6;
    return rows.map((r) => {
      const svg = document.createElementNS(ns, 'svg');
      svg.setAttribute('width', String(width));
      svg.setAttribute('height', String(H));
      svg.style.flex = 'none';
      const line = (x1, y1, x2, y2, color) => {
        const l = document.createElementNS(ns, 'line');
        l.setAttribute('x1', x1); l.setAttribute('y1', y1); l.setAttribute('x2', x2); l.setAttribute('y2', y2);
        l.setAttribute('stroke', color); l.setAttribute('stroke-width', '1.6');
        svg.append(l);
      };
      const x = (i) => i * W + W / 2 + 2;
      const color = (i) => colors[i % colors.length];
      r.before.forEach((s, i) => { if (s != null && i !== r.col && !r.joins.includes(i)) line(x(i), 0, x(i), H, color(i)); });
      if (r.before[r.col] != null && r.before.indexOf(r.before[r.col]) === r.col) line(x(r.col), 0, x(r.col), H / 2, color(r.col));
      for (const i of r.joins) line(x(i), 0, x(r.col), H / 2, color(i));
      if (r.after[r.col] != null) line(x(r.col), H / 2, x(r.col), H, color(r.col));
      for (const i of r.merges) line(x(r.col), H / 2, x(i), H, color(i));
      const dot = document.createElementNS(ns, 'circle');
      dot.setAttribute('cx', x(r.col)); dot.setAttribute('cy', H / 2); dot.setAttribute('r', '3.6'); dot.setAttribute('fill', color(r.col));
      svg.append(dot);
      return svg;
    });
  }
}
