// window.jumphp is there before this script: invoke (page -> PHP, a Promise), send, on (PHP -> page).
const $ = (id) => document.getElementById(id);

$('greet').addEventListener('submit', async (e) => {
  e.preventDefault();
  const r = await jumphp.invoke('greet', {name: $('name').value});
  $('answer').textContent = `${r.message} (×${r.clicks})`;
});

jumphp.on('clock', (p) => { $('clock').textContent = p.time; });

// a native feature without PHP code of its own (JumPHP\Tauri\NativeCommands)
$('open').addEventListener('click', async () => {
  const file = await jumphp.invoke('native.open', {title: 'Open a file'});
  $('file').textContent = file ? `You chose ${file}` : 'Cancelled';
});
