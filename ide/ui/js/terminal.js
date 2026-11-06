// The Terminal tool window (desktop IDE): shells in the project's folder, one tab each (xterm.js, MIT).
// Linux / macOS: the user's shell in a pseudo-terminal (PHP: JumPHP\Ide\Run\Terminals), everything as in a real
// terminal. Windows: cmd.exe (or PowerShell) on pipes: the page echoes and edits the line itself (pty: false).
import { backend } from './backend.js';
import { el, clear, icon, notify, copyText, pasteText } from './ui.js';

/** xterm.js and its fit addon (index.html loads them before Monaco's AMD loader: UMD scripts). */
function loadXterm() {
  if (!window.Terminal || !window.FitAddon) return Promise.reject(new Error('xterm.js is missing (run ide/tools/fetch-vendor.sh)'));
  return Promise.resolve({ Terminal: window.Terminal, FitAddon: window.FitAddon.FitAddon });
}

const decode = (b64) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));

export class TerminalPanel {
  constructor(app) {
    this.app = app;
    this.terms = [];             // {id, label, term, fit, pty, line, node, ended}
    this.active = null;
    this.count = 0;
    this.node = el('div.terminal-panel');
    this.tabsNode = el('div.tw-tabs');
    backend.on('terminal.output', (p) => {
      const t = this.terms.find((x) => x.id === p.id);
      if (!t) return;
      t.lastOutput = Date.now();
      t.term.write(decode(p.data));
    });
    backend.on('terminal.exit', (p) => {
      const t = this.terms.find((x) => x.id === p.id);
      if (!t) return;
      t.ended = true;
      t.term.write('\r\n\x1b[2m[process ended: Enter opens a new shell]\x1b[0m\r\n');
      this.render();
    });
    new ResizeObserver(() => this.fit()).observe(this.node);
  }

  title() { return 'Terminal'; }
  headerExtra() { return this.tabsNode; }

  actions() {
    return [el('button', { title: 'New Terminal', onclick: () => this.open() }, icon('plus'))];
  }

  current() { return this.terms.find((t) => t === this.active) || null; }

  /** Shown: a first shell, the size of the window, the keys. */
  shown() {
    if (!this.terms.length && !this.running) this.open();
    else { this.render(); requestAnimationFrame(() => { this.fit(); this.current()?.term.focus(); }); }
  }

  async open() {
    let lib;
    try { lib = await loadXterm(); } catch (e) { notify(e.message, 'error'); return; }
    const style = getComputedStyle(document.documentElement);
    const v = (name, fallback) => style.getPropertyValue(name).trim() || fallback;
    const term = new lib.Terminal({
      fontFamily: v('--mono', 'monospace'), fontSize: 13, cursorBlink: true, scrollback: 5000, allowProposedApi: false,
      theme: { background: v('--bg', '#1e1f22'), foreground: v('--text', '#dfe1e5'), cursor: v('--text', '#dfe1e5'), selectionBackground: v('--selection', '#2e436e') },
    });
    const fit = new lib.FitAddon();
    term.loadAddon(fit);
    const t = { id: null, label: `Local${this.count++ ? ' (' + this.count + ')' : ''}`, term, fit, pty: true, line: '', node: el('div.terminal-view'), ended: false };
    this.terms.push(t);
    this.active = t;
    this.render();
    term.open(t.node);
    fit.fit();
    term.onData((data) => this.input(t, data));
    term.attachCustomKeyEventHandler((e) => {
      // Ctrl+Shift+C / V: copy and paste (Ctrl+C stays the shell's interrupt)
      if (e.type === 'keydown' && e.ctrlKey && e.shiftKey && (e.code === 'KeyC' || e.code === 'KeyV')) {
        if (e.code === 'KeyC' && term.hasSelection()) copyText(term.getSelection());
        if (e.code === 'KeyV') pasteText().then((text) => text && this.input(t, text));
        e.preventDefault();
        return false;
      }
      return true;
    });
    term.onResize(({ cols, rows }) => { if (t.id !== null && t.pty) backend.invoke('terminal.resize', { id: t.id, cols, rows }).catch(() => {}); });
    await this.start(t);
    term.focus();
  }

  /** Runs a command in a new tab (a CLI app's Run): typed into its shell, which stays there afterwards. */
  async run(command, label = null) {
    this.running = true;          // shown() must not open an empty shell beside it
    try { await this.open(); } finally { this.running = false; }
    const t = this.current();
    if (!t) return;
    if (label) { t.label = label; this.render(); }
    // typed only once the shell shows its prompt: typed earlier, the terminal echoes it, then readline again, and the
    // shell's start (stty, its rc files) may swallow the line: it was shown twice and never ran
    await this.ready(t);
    this.input(t, command + '\r');
    t.term.focus();
  }

  /** Resolves once the shell has printed something (its prompt) then stayed quiet 250 ms; at most 8 s. */
  ready(t) {
    const begun = Date.now();
    return new Promise((resolve) => {
      const check = () => {
        const now = Date.now();
        if (t.ended || now - begun > 8000 || (t.lastOutput && now - t.lastOutput >= 250)) resolve();
        else setTimeout(check, 50);
      };
      check();
    });
  }

  async start(t) {
    t.lastOutput = 0;
    t.ended = false;
    try {
      const r = await backend.invoke('terminal.open', { cols: t.term.cols, rows: t.term.rows });
      t.id = r.id;
      t.pty = r.pty;
      t.term.options.convertEol = !t.pty;     // a pipe sends bare \n
      t.label = t.label.replace(/^Local/, r.shell && !/^(bash|zsh|sh)$/.test(r.shell) ? r.shell.replace(/\.exe$/i, '') : 'Local');
      if (t.queued) { const q = t.queued; t.queued = ''; this.input(t, q); }
      if (!t.pty) t.term.write(`\x1b[2m${r.shell}: no pseudo-terminal here, the line is edited before it is sent\x1b[0m\r\n`);
    } catch (e) {
      t.ended = true;
      t.term.write(`\x1b[31m${e.message || e}\x1b[0m\r\n`);
    }
    this.render();
  }

  input(t, data) {
    if (t.ended) {
      if (data === '\r') { t.term.reset(); this.start(t); }
      return;
    }
    if (t.id === null) { t.queued = (t.queued || '') + data; return; }     // the shell is starting
    if (t.pty) { backend.invoke('terminal.write', { id: t.id, data }).catch(() => {}); return; }
    // pipes (Windows): echo and edit the line here, send it whole
    for (const ch of data.replace(/\r\n/g, '\r')) {
      if (ch === '\r' || ch === '\n') {
        t.term.write('\r\n');
        backend.invoke('terminal.write', { id: t.id, data: t.line + '\r\n' }).catch(() => {});
        t.line = '';
      } else if (ch === '\x7f' || ch === '\b') {
        if (t.line) { t.line = t.line.slice(0, -1); t.term.write('\b \b'); }
      } else if (ch === '\x03') {
        t.line = '';
        t.term.write('^C\r\n');
      } else if (ch >= ' ') {
        t.line += ch;
        t.term.write(ch);
      }
    }
  }

  close(t) {
    if (t.id !== null && !t.ended) backend.invoke('terminal.close', { id: t.id }).catch(() => {});
    t.term.dispose();
    this.terms = this.terms.filter((x) => x !== t);
    if (this.active === t) this.active = this.terms[this.terms.length - 1] || null;
    this.render();
    if (!this.terms.length) this.app.closeSide?.(this.app.tools.terminal.side);
  }

  fit() {
    const t = this.current();
    if (!t || !this.node.isConnected || !this.node.offsetWidth) return;
    try { t.fit.fit(); } catch { /* not laid out yet */ }
  }

  render() {
    clear(this.tabsNode);
    for (const t of this.terms) {
      this.tabsNode.append(el(`button.tw-tab${t === this.active ? '.on' : ''}`, { onclick: () => { this.active = t; this.render(); t.term.focus(); } },
        icon('terminal'), t.label + (t.ended ? ' (ended)' : ''), el('span.x', { title: 'Close', onclick: (e) => { e.stopPropagation(); this.close(t); } }, '×')));
    }
    for (const t of this.terms) t.node.hidden = t !== this.active;
    if (!this.node.isConnected || [...this.node.children].length !== this.terms.length) {
      clear(this.node);
      for (const t of this.terms) this.node.append(t.node);
    }
    requestAnimationFrame(() => this.fit());
  }
}
