// The web IDE's plugins, run in this browser (plugin-worker.js: jumphp.net's WebAssembly PHP in a Web Worker), never
// on the server. It answers the plugins.* commands the desktop app's PHP answers (plugins.js calls it the same way),
// and the market.* ones from jumphp.net's public plugin API. The installed plugins and their settings are kept in
// this browser (localStorage). A plugin sees a copy of the project's files; what it writes is saved through the web
// IDE's own commands (a read-only member's plugins can read, not change anything).
// What needs the desktop app is left out: run configurations (programs on a computer) and tools for Claude.
import { backend } from './backend.js';

const KEY = 'jumphp.ide.plugins';
const MAX_FILE = 256 * 1024;
const MAX_FILES = 3000;
const TEXT = /\.(php|phtml|ya?ml|json|md|txt|js|mjs|ts|css|html?|xml|neon|ini|sh|twig|tmj|tsj|csv|sql|dist|lock)$/i;

function stored() {
  try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch { return {}; }
}
function store(data) {
  try { localStorage.setItem(KEY, JSON.stringify(data)); } catch { /* private window: this session only */ }
}

export class PluginSandbox {
  /** Starts the worker with the project's text files: resolves once the plugins are loaded. */
  static async start(app, config) {
    const box = new PluginSandbox(app, config);
    await box.boot();
    return box;
  }

  constructor(app, config) {
    this.app = app;
    this.config = config;
    this.kind = 'sandbox';
    this.listeners = new Map();
    this.waiting = new Map();
    this.next = 1;
    this.queue = Promise.resolve();
    this.data = stored();
    this.data.installed ??= {};
  }

  on(event, fn) {
    if (!this.listeners.has(event)) this.listeners.set(event, new Set());
    this.listeners.get(event).add(fn);
    return () => this.listeners.get(event).delete(fn);
  }

  emit(event, payload) {
    for (const fn of this.listeners.get(event) || []) {
      try { fn(payload); } catch (e) { console.error(e); }
    }
  }

  async files() {
    const { files } = await backend.invoke('files.list', {});
    const wanted = files.filter((f) => f.size <= MAX_FILE && TEXT.test(f.path)).slice(0, MAX_FILES);
    const out = {};
    const queue = wanted.slice();
    await Promise.all(Array.from({ length: 6 }, async () => {
      for (let f; (f = queue.shift());) {
        const file = await backend.invoke('file.read', { path: f.path }).catch(() => null);
        if (file && typeof file.content === 'string') out[f.path] = file.content;
      }
    }));
    return out;
  }

  boot() {
    const url = (id) => new URL(`/api/plugins/${encodeURIComponent(id)}/download?via=web-ide`, location.origin).href;
    return new Promise(async (resolve, reject) => {
      let files = {};
      try { files = await this.files(); } catch (e) { console.error(e); }
      this.worker = new Worker(new URL('./plugin-worker.js', import.meta.url));
      this.worker.onerror = (e) => reject(new Error(e.message || 'the plugins\' worker failed'));
      this.worker.onmessage = ({ data }) => {
        if (data.t === 'ready') { this.handle(data); resolve(this); return; }
        if (data.t === 'failed') { reject(new Error(data.error)); return; }
        if (data.t === 'answer') {
          const w = this.waiting.get(data.id);
          this.waiting.delete(data.id);
          if (!w) return;
          this.handle(data);
          if (data.error) w.reject(new Error(data.error)); else w.resolve(data.result);
        }
      };
      this.worker.postMessage({ t: 'boot', engine: new URL(this.config.engine, location.href).href, core: new URL(this.config.core, document.baseURI).href,
        files, name: this.app.project?.name || 'project', state: this.data.state || null,
        installed: Object.keys(this.data.installed).map((id) => ({ id, url: url(id) })) });
      // the plugins' timers (every()): a tick a second, like the desktop app's frames, only while the page is shown
      this.ticker = setInterval(() => { if (!document.hidden) this.call('host.tick').catch(() => null); }, 1000);
      // the editor's saves reach the plugins (file.saved), and so do files changed by other means
      backend.after?.add((name, payload) => this.saved(name, payload));
    });
  }

  /** What an answer carries: the plugins' events, their file changes (saved through the web IDE), their state. */
  handle(data) {
    for (const [event, payload] of data.events || []) this.emit(event, payload);
    if (data.state) { this.data.state = data.state; store(this.data); }
    for (const c of data.changes || []) this.apply(c);
  }

  async apply(c) {
    this.applying = true;
    try {
      if (c.op === 'write') await backend.invoke('file.write', { path: c.path, content: c.content });
      else if (c.op === 'delete') await backend.invoke('file.delete', { path: c.path });
      else if (c.op === 'rename') await backend.invoke('file.rename', { from: c.path, to: c.to });
      this.app.tree?.refresh?.();
    } catch (e) {
      this.emit('plugins.ui', { do: 'notify', kind: 'error', title: 'Plugins', message: `${c.path}: ${e.message}` });
    } finally {
      this.applying = false;
    }
  }

  saved(name, payload) {
    if (this.applying) return;
    if ((name === 'file.write' || name === 'file.create') && typeof payload.content === 'string' && !payload.base64) {
      this.call('host.saved', { path: payload.path, content: payload.content }).catch(() => null);
    } else if (name === 'file.delete') {
      this.call('host.files', { files: { [payload.path]: null } }).catch(() => null);
    } else if (['file.rename', 'edits.apply', 'search.replace', 'git.rollback'].includes(name)) {
      this.files().then((files) => this.call('host.files', { files })).then(() => this.call('host.changed')).catch(() => null);
    }
  }

  /** One call to the worker, one at a time (PHP runs one thing at a time). */
  call(name, payload = {}, message = 'call') {
    const run = () => new Promise((resolve, reject) => {
      const id = this.next++;
      this.waiting.set(id, { resolve, reject });
      this.worker.postMessage(message === 'call' ? { t: 'call', id, name, payload } : { t: message, id, ...payload });
    });
    const p = this.queue.then(run, run);
    this.queue = p.catch(() => null);
    return p;
  }

  /** The commands plugins.js sends to the IDE's PHP, answered here. */
  async invoke(name, payload = {}) {
    switch (name) {
      case 'plugins.contributions': {
        const c = await this.call(name, payload);
        // needs the desktop app: programs run on a computer, the IDE's MCP server for Claude
        return { ...c, run: [], mcp: [], hidden: { run: (c.run || []).length, mcp: (c.mcp || []).length } };
      }
      case 'plugins.restart': location.reload(); return true;
      case 'plugins.remove': {
        const r = await this.call(name, payload);
        delete this.data.installed[payload.id];
        store(this.data);
        return r;
      }
      case 'market.list': {
        const response = await fetch(`/api/plugins?q=${encodeURIComponent(payload.q || '')}`, { credentials: 'same-origin' });
        if (!response.ok) throw new Error(`jumphp.net: HTTP ${response.status}`);
        return (await response.json()).plugins || [];
      }
      case 'market.install': {
        const r = await this.call('', { plugin: payload.id, url: `/api/plugins/${encodeURIComponent(payload.id)}/download?via=${payload.via === 'update' ? 'update' : 'install'}` }, 'install');
        this.data.installed[payload.id] = { at: Date.now() };
        store(this.data);
        // a newer version of a loaded plugin: PHP keeps its classes, the page reloads to use it
        return r.status === 'active' && payload.via === 'update' ? { ...r, restart: true } : r;
      }
      case 'market.auto': return { auto: false };
      default:
        if (name.startsWith('plugins.')) return this.call(name, payload);
        throw new Error(`${name}: not available in the web IDE`);
    }
  }
}
