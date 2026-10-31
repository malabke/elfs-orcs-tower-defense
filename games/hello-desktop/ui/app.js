// The page of Hello Desktop. window.jumphp (injected by JumPHP before this script):
//   jumphp.invoke(name, payload) -> Promise (a command of src/Main.php, or a built-in native.*)
//   jumphp.on(name, fn)          <- what PHP sends ($app->send / $window->send)
const $ = (id) => document.getElementById(id);
const status = (text, ok) => { $('status').textContent = text; $('status').style.color = ok ? 'var(--ok)' : ''; };
let dirty = false;

const setFile = (name) => { $('fileName').textContent = name || 'Untitled'; };
const markDirty = (on) => {
  if (dirty === on) return;
  dirty = on;
  jumphp.invoke('notes.changed', {dirty: on});      // PHP keeps the title and asks before quitting
};

$('text').addEventListener('input', () => markDirty(true));

$('open').addEventListener('click', async () => {
  try {
    const r = await jumphp.invoke('notes.open');
    if (!r) return status('Open: cancelled.');
    $('text').value = r.text;
    dirty = false;
    setFile(r.name);
    status(`Opened ${r.path} (${r.text.length} characters).`, true);
  } catch (e) { status(`Open failed: ${e.message}`); }
});

const save = async (as) => {
  try {
    const r = await jumphp.invoke(as ? 'notes.saveAs' : 'notes.save', {text: $('text').value});
    if (!r) return status('Save: cancelled.');
    dirty = false;
    setFile(r.name);
    status(`Saved ${r.bytes} bytes to ${r.path}.`, true);
  } catch (e) { status(`Save failed: ${e.message}`); }
};
$('save').addEventListener('click', () => save(false));
$('saveAs').addEventListener('click', () => save(true));
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(e.shiftKey); }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'o') { e.preventDefault(); $('open').click(); }
});

$('greet').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('greeting').textContent = await jumphp.invoke('greet', {name: $('name').value});
});

// the built-in native commands (JumPHP\Tauri\NativeCommands): no PHP code needed in the app
document.querySelectorAll('[data-native]').forEach((b) => b.addEventListener('click', async () => {
  const out = $('native');
  try {
    switch (b.dataset.native) {
      case 'message':
        await jumphp.invoke('native.message', {title: 'Hello Desktop', message: 'A message box of your system, opened by PHP.'});
        out.textContent = 'Message closed.';
        break;
      case 'confirm': {
        const yes = await jumphp.invoke('native.confirm', {title: 'Hello Desktop', message: 'Do you like PHP?', ok: 'Yes', cancel: 'No'});
        out.textContent = yes ? 'You said yes.' : 'You said no.';
        break;
      }
      case 'folder': {
        const dir = await jumphp.invoke('native.open', {folder: true, title: 'Pick a folder'});
        out.textContent = dir ? dir : 'Cancelled.';
        break;
      }
    }
  } catch (e) { out.textContent = e.message; }
}));
$('notify').addEventListener('click', async () => {
  $('native').textContent = (await jumphp.invoke('notify')) ? 'Notification sent.' : 'No notifications here.';
});

jumphp.on('clock', (p) => {
  $('clock').textContent = p.time;
  $('uptime').textContent = `up ${p.uptime}s · ${p.memory} MB`;
});

jumphp.on('dropped', (files) => {
  $('dropped').innerHTML = '';
  for (const f of files) {
    const li = document.createElement('li');
    li.textContent = f.dir ? `${f.name}/ (folder)` : `${f.name} — ${f.size ?? '?'} bytes`;
    $('dropped').appendChild(li);
  }
});
['dragenter', 'dragover'].forEach((t) => document.addEventListener(t, () => $('drop').classList.add('hover')));
['dragleave', 'drop'].forEach((t) => document.addEventListener(t, () => $('drop').classList.remove('hover')));

jumphp.invoke('info').then((info) => {
  const rows = {PHP: `${info.php} (${info.sapi})`, System: info.os, Runtime: `jumphp-tauri ${info.runtime}`, Native: info.native, Loop: info.loop};
  $('info').innerHTML = '';
  for (const [k, v] of Object.entries(rows)) {
    const dt = document.createElement('dt'); dt.textContent = k;
    const dd = document.createElement('dd'); dd.textContent = v; dd.title = v;
    $('info').append(dt, dd);
  }
});
