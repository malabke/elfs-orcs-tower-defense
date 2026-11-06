// The Logs tool window: the IDE's detailed logs (ide/src/Log/LogBuffer.php) as they happen, like `tail -f` but visual: what the IDE does
// and fails at, the programs it runs (bin/jumphp, Docker, git, Claude, OpenCode…), with filters (source, level, text), Pause / Follow, Copy
// and Clear. The same lines are kept in a file on disk for bug reports (its path: the folder button). Errors that arrive while the window is
// closed put a red mark on its button.
import { backend } from './backend.js';
import { el, icon, notify, copyText, prefs } from './ui.js';

const LEVELS = ['debug', 'info', 'warn', 'error'];
const MAX_ROWS = 1500;

export class LogsPanel {
  constructor(app) {
    this.app = app;
    this.entries = [];
    this.paused = false;
    this.pending = [];
    this.file = null;
    this.sources = new Set();
    this.level = el('select.input.small', { title: 'Shows this level and above', onchange: () => { prefs.set('logLevel', this.level.value); this.redraw(); } },
      ...LEVELS.map((l) => el('option', { value: l }, l)));
    this.level.value = prefs.get('logLevel', 'info');
    this.source = el('select.input.small', { title: 'Where it comes from', onchange: () => this.redraw() }, el('option', { value: '' }, 'all sources'));
    this.search = el('input.input.small', { type: 'search', placeholder: 'Search the logs', oninput: () => this.redraw() });
    this.list = el('div.logs-list', { tabIndex: 0 });
    this.status = el('span.logs-status');
    this.node = el('div.logs-panel', {}, el('div.logs-bar', {}, this.level, this.source, this.search, this.status), this.list);
    backend.on('log.entry', (e) => this.entry(e));
    this.load();
  }

  title() { return 'Logs'; }

  actions() {
    return [
      el('button', { title: 'Pause / follow', onclick: (e) => { this.togglePause(); e.currentTarget.classList.toggle('on', this.paused); } }, icon('stop')),
      el('button', { title: 'Copy what is shown', onclick: () => { copyText(this.text()); notify('Logs copied', 'info', { title: 'Logs' }); } }, icon('file')),
      el('button', { title: 'Where the logs are kept on disk (for a bug report): copy the path', onclick: () => this.copyPath() }, icon('folder')),
      el('button', { title: 'Clear', onclick: () => this.clear() }, icon('close')),
    ];
  }

  async load() {
    const r = await backend.invoke('log.list', { limit: 800 }).catch(() => null);
    if (!r) return;
    this.file = r.file;
    this.entries = r.entries;
    for (const e of r.entries) this.sources.add(e.source);
    this.fillSources();
    this.redraw();
  }

  /** A new entry: shown at once, held while paused; a closed window only marks the button when it is an error. */
  entry(e) {
    this.sources.add(e.source);
    if (!this.source.querySelector(`option[value="${CSS.escape(e.source)}"]`)) this.fillSources();
    this.entries.push(e);
    if (this.entries.length > 3000) this.entries.splice(0, this.entries.length - 3000);
    if (e.level === 'error' && !this.isOpen()) this.app.badge('logs', 'red');
    if (this.paused) { this.pending.push(e); this.status.textContent = `paused · ${this.pending.length} new`; return; }
    if (this.visible(e)) this.append(e, true);
  }

  isOpen() { const tool = this.app.tools?.logs; return !!tool && this.app.open?.[tool.side] === 'logs'; }

  /** Called by the tool window when it is shown: the red mark goes. */
  shown() { this.app.badge('logs', null); this.scroll(); }

  visible(e) {
    if (LEVELS.indexOf(e.level) < LEVELS.indexOf(this.level.value)) return false;
    if (this.source.value && e.source !== this.source.value) return false;
    const q = this.search.value.trim().toLowerCase();
    return !q || `${e.source} ${e.message}`.toLowerCase().includes(q);
  }

  append(e, follow) {
    const t = new Date(e.time * 1000);
    const hh = [t.getHours(), t.getMinutes(), t.getSeconds()].map((n) => String(n).padStart(2, '0')).join(':');
    this.list.append(el(`div.log-row.${e.level}`, {}, el('span.log-time', {}, hh), el('span.log-source', {}, e.source), el('span.log-msg', {}, e.message)));
    while (this.list.childElementCount > MAX_ROWS) this.list.firstElementChild.remove();
    if (follow) this.scroll();
  }

  scroll() { if (!this.paused) this.list.scrollTop = this.list.scrollHeight; }

  fillSources() {
    const keep = this.source.value;
    this.source.replaceChildren(el('option', { value: '' }, 'all sources'), ...[...this.sources].sort().map((s) => el('option', { value: s }, s)));
    this.source.value = keep;
  }

  redraw() {
    this.list.replaceChildren();
    for (const e of this.entries.slice(-MAX_ROWS * 2)) if (this.visible(e)) this.append(e, false);
    this.status.textContent = this.paused ? `paused · ${this.pending.length} new` : '';
    this.scroll();
  }

  togglePause() {
    this.paused = !this.paused;
    if (!this.paused) { this.pending = []; this.redraw(); } else this.status.textContent = 'paused';
  }

  text() {
    return [...this.list.children].map((r) => [...r.children].map((c) => c.textContent).join(' ')).join('\n');
  }

  async clear() {
    await backend.invoke('log.clear').catch(() => null);
    this.entries = [];
    this.pending = [];
    this.redraw();
  }

  copyPath() {
    if (!this.file) { notify('This IDE does not keep a log file.', 'info', { title: 'Logs' }); return; }
    copyText(this.file);
    notify(`${this.file} (copied). Send it with a bug report.`, 'info', { title: 'Logs' });
  }
}
