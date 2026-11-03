// Show Local History (PhpStorm's Local History): the versions of a file the IDE kept on this computer, without git (a
// version at each save, and when the file changed outside the IDE: Claude Code, a terminal, git). One is compared with
// the file (a diff whose right side is the file, editable), or put back (Revert: the editor gets it, Ctrl+Z takes it back).
// The PHP side: JumPHP\Ide\History\LocalHistory, kept in .jumphp/history/files/ (not in git).
import { backend } from './backend.js';
import { el, basename, dialog, notify, confirm, timeAgo } from './ui.js';

const when = (v) => new Date(v.time * 1000).toLocaleString();

export async function showLocalHistory(app, path) {
  if (!path) return;
  let versions;
  try {
    versions = await backend.invoke('history.local.list', { path });
  } catch (e) { notify(e.message, 'error'); return; }
  const list = el('div.local-history');
  const box = dialog({ title: `Local History of ${basename(path)}`, wide: true, body: list, buttons: [{ label: 'Close' }] });
  if (!versions.length) {
    list.append(el('p.pub-faint', {}, 'No version yet: the IDE keeps one each time it saves this file, and when it finds it changed outside the IDE.'));
    return;
  }
  list.append(el('p.pub-faint', {}, `${versions.length} version(s), newest first, kept on this computer (.jumphp/history/files/, not in git).`));
  for (const v of versions) {
    list.append(el('div.local-history-row', { title: 'Compare with the file', onclick: () => { box.close(); compare(app, path, v); } },
      el('span.local-history-time', {}, when(v)), el('span.pub-faint', {}, timeAgo(v.time)),
      el('span.local-history-label', {}, v.label || 'Saved'), el('span.pub-faint', {}, `${v.size} bytes`), el('span.tw-spacer'),
      el('button.btn.small', { onclick: (e) => { e.stopPropagation(); box.close(); compare(app, path, v); } }, 'Compare'),
      el('button.btn.small', { onclick: (e) => { e.stopPropagation(); box.close(); revert(app, path, v); } }, 'Revert')));
  }
}

async function compare(app, path, v) {
  const original = await backend.invoke('history.local.read', { path, id: v.id }).catch((e) => { notify(e.message, 'error'); return null; });
  if (original === null) return;
  await app.editors.openDiff(path, { original, title: `${basename(path)} (local history: ${when(v)})`, left: when(v) });
}

/** The version goes into the editor as one change (Ctrl+Z takes it back), then the file is saved. */
async function revert(app, path, v) {
  if (!(await confirm(`Revert ${basename(path)}?`, `Its content becomes the version of ${when(v)}. Ctrl+Z in the editor takes it back.`, { ok: 'Revert' }))) return;
  const content = await backend.invoke('history.local.read', { path, id: v.id }).catch((e) => { notify(e.message, 'error'); return null; });
  if (content === null) return;
  await app.editors.open(path);
  const file = await app.editors.load(path);
  if (!file?.model) { notify(`${path} is not a text file`, 'error'); return; }
  file.model.pushEditOperations([], [{ range: file.model.getFullModelRange(), text: content }], () => null);
  await app.editors.save(path);
  notify(`${basename(path)}: back to the version of ${when(v)}`, 'success');
}
