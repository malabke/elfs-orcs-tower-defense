// The web IDE's plugins (PHP) in the visitor's browser: a Web Worker running jumphp.net's shared WebAssembly engine
// with the IDE's plugin core (plugin-core.pack: ide/src and its packages, JumPHP\Ide\Plugin\BrowserHost). Nothing of a
// plugin runs on the server. plugin-sandbox.js starts it and talks to it:
//   {t: 'boot', engine, core, files, name, state, installed: [{id, url}]}  -> {t: 'ready', plugins} | {t: 'failed', error}
//   {t: 'call', id, name, payload}                                          -> {t: 'answer', id, result | error, events, changes, state}
//   {t: 'install', id, url}                                                 -> {t: 'answer', id, result | error, ...}
// A plugin sees a copy of the project's files; what it writes comes back as `changes`, saved by the page through the
// web IDE's own commands. `state`: the plugins' settings / disabled list (plugins.json), kept by the page.
self.window = self;
const inert = () => ({ style: {}, getContext: () => null, addEventListener() {}, removeEventListener() {} });
self.document = {
  addEventListener() {}, removeEventListener() {}, createElement: inert, getElementById: () => null,
  querySelector: () => null, body: inert(), documentElement: inert(), title: '',
};

let ccall = null;
let FS = null;
let lastState = null;
const errors = [];

const USER_DIR = '/plugins';

function write(path, bytes) {
  const slash = path.lastIndexOf('/');
  if (slash > 0) FS.mkdirTree(path.slice(0, slash));
  FS.writeFile(path, bytes);
}

/** A JPK1 pack (tools/pack.php's format) unpacked under `to`. */
function unpack(buffer, to) {
  const bytes = new Uint8Array(buffer);
  if (new TextDecoder().decode(bytes.subarray(0, 4)) !== 'JPK1') throw new Error('not a pack');
  const length = new DataView(buffer).getUint32(4, true);
  const header = JSON.parse(new TextDecoder().decode(bytes.subarray(8, 8 + length)));
  const base = 8 + length;
  for (const [path, offset, size] of header.files) {
    if (path.split('/').some((p) => p === '..' || p === '')) throw new Error('bad path in the pack: ' + path);
    write(`${to}/${path}`, bytes.subarray(base + offset, base + offset + size));
  }
}

/** A plugin's zip (bin/jumphp plugin:pack: one top folder, or the files at its root) into `to`. */
async function unzip(buffer, to) {
  const bytes = new Uint8Array(buffer);
  const view = new DataView(buffer);
  let end = bytes.length - 22;
  while (end >= 0 && view.getUint32(end, true) !== 0x06054b50) end--;
  if (end < 0) throw new Error('not a zip file');
  const count = view.getUint16(end + 10, true);
  let at = view.getUint32(end + 16, true);
  const entries = [];
  for (let i = 0; i < count; i++) {
    if (view.getUint32(at, true) !== 0x02014b50) throw new Error('broken zip');
    const method = view.getUint16(at + 10, true);
    const size = view.getUint32(at + 20, true);
    const nameLength = view.getUint16(at + 28, true);
    const extra = view.getUint16(at + 30, true);
    const comment = view.getUint16(at + 32, true);
    const local = view.getUint32(at + 42, true);
    const name = new TextDecoder().decode(bytes.subarray(at + 46, at + 46 + nameLength));
    entries.push({ method, size, name, local });
    at += 46 + nameLength + extra + comment;
  }
  const names = entries.filter((e) => !e.name.endsWith('/')).map((e) => e.name);
  const top = names.length && names.every((n) => n.includes('/') && n.split('/')[0] === names[0].split('/')[0]) ? names[0].split('/')[0] + '/' : '';
  for (const e of entries) {
    if (e.name.endsWith('/')) continue;
    const rel = top ? e.name.slice(top.length) : e.name;
    if (!rel || rel.split('/').some((p) => p === '..' || p === '')) throw new Error('bad path in the zip: ' + e.name);
    const nameLength = view.getUint16(e.local + 26, true);
    const extra = view.getUint16(e.local + 28, true);
    const start = e.local + 30 + nameLength + extra;
    const data = bytes.subarray(start, start + e.size);     // the central directory's compressed size
    let content;
    if (e.method === 0) content = data;
    else if (e.method === 8) content = new Uint8Array(await new Response(new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'))).arrayBuffer());
    else throw new Error(`${e.name}: compression ${e.method} not supported`);
    write(`${to}/${rel}`, content);
  }
}

function php(code) {
  ccall('zend_eval_string', 'number', ['string', 'number', 'string'], [code, 0, 'jumphp plugins']);
}

/** One call of BrowserHost: its answer, with the plugins' state when it changed. */
function call(name, payload) {
  FS.writeFile('/tmp/plugins-in.json', JSON.stringify({ name, payload }));
  php(`JumPHP\\Ide\\Plugin\\BrowserHost::handle('/tmp/plugins-in.json', '/tmp/plugins-out.json');`);
  let answer;
  try {
    answer = JSON.parse(FS.readFile('/tmp/plugins-out.json', { encoding: 'utf8' }));
    FS.unlink('/tmp/plugins-out.json');
  } catch {
    answer = { error: 'the plugins stopped: ' + (errors.join(' | ') || 'PHP failed') };
  }
  let state = null;
  try { state = FS.readFile(`${USER_DIR}/plugins.json`, { encoding: 'utf8' }); } catch { /* none yet */ }
  if (state !== null && state !== lastState) { lastState = state; answer.state = state; }
  return answer;
}

async function download(url) {
  const response = await fetch(url, { credentials: 'same-origin' });
  if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
  return response.arrayBuffer();
}

async function install(id, url) {
  if (!/^[a-z0-9][a-z0-9-]*$/.test(id)) throw new Error('bad plugin id ' + id);
  await unzip(await download(url), `${USER_DIR}/${id}`);
}

async function boot(data) {
  const engine = new URL(data.engine, self.location.href);
  const [{ default: createPhpModule }, wasmBinary, core] = await Promise.all([
    import(new URL('php-web.mjs', engine).href),
    download(new URL('php-web.wasm', engine).href),
    download(new URL(data.core, self.location.href).href),
  ]);
  const module = await createPhpModule({
    wasmBinary,
    locateFile: (path, prefix) => (path === 'php-web.data' ? new URL(path, engine).href : prefix + path),
    print: (text) => console.log('[plugins]', text),
    printErr(text) {
      if (!text || /munmap\(\) failed/.test(text)) return;
      errors.push(String(text));
      if (errors.length > 8) errors.shift();
      console.error('[plugins]', text);
    },
  });
  ccall = module.ccall;
  FS = module.FS;
  unpack(core, '/plugin-core');
  FS.mkdirTree('/tmp');
  FS.mkdirTree(USER_DIR);
  if (data.state) { FS.writeFile(`${USER_DIR}/plugins.json`, data.state); lastState = data.state; }
  for (const p of data.installed || []) {
    try { await install(p.id, p.url); } catch (e) { errors.push(`${p.id}: ${e.message}`); }
  }
  ccall('php_embed_init', 'number', ['number', 'number'], [0, 0]);
  php(`define('JUMPHP_WASM', true); require '/plugin-core/autoload.php';`);
  const answer = call('host.boot', { files: data.files || {}, name: data.name || 'project' });
  if (answer.error) throw new Error(answer.error);
  const project = call('host.loadProject', {});
  return { ...answer, events: [...(answer.events || []), ...(project.events || [])] };
}

self.onmessage = async ({ data }) => {
  try {
    if (data.t === 'boot') {
      const answer = await boot(data);
      self.postMessage({ t: 'ready', ...answer, warnings: errors.slice() });
      return;
    }
    if (data.t === 'install') {
      await install(data.plugin, data.url);
      self.postMessage({ t: 'answer', id: data.id, ...call('host.load', { dir: `${USER_DIR}/${data.plugin}`, source: 'user' }) });
      return;
    }
    if (data.t === 'call') self.postMessage({ t: 'answer', id: data.id, ...call(data.name, data.payload || {}) });
  } catch (e) {
    const error = String(e && e.message ? e.message : e) + (errors.length ? ' ' + errors.join(' | ') : '');
    self.postMessage(data.t === 'boot' ? { t: 'failed', error } : { t: 'answer', id: data.id, error });
  }
};
