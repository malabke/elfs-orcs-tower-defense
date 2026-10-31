// The panel: every change goes to PHP (App\Desktop), which the game (BallsScene) reads at its next frame.
const $ = (id) => document.getElementById(id);
const colours = ['#ffcc33', '#ff5577', '#33ccff', '#66ee88', '#c58cff', '#ffffff'];

const show = (s) => {
  $('balls').value = s.balls; $('ballsOut').textContent = s.balls;
  $('speed').value = s.speed; $('speedOut').textContent = `${Number(s.speed).toFixed(1)}×`;
  $('pause').textContent = s.paused ? 'Resume' : 'Pause';
  document.querySelectorAll('.swatches button').forEach((b) => b.classList.toggle('on', b.dataset.c === s.colour));
};
const set = async (change) => show(await jumphp.invoke('set', change));

for (const c of colours) {
  const b = document.createElement('button');
  b.style.background = c; b.dataset.c = c; b.title = c;
  b.addEventListener('click', () => set({colour: c}));
  $('swatches').appendChild(b);
}
$('balls').addEventListener('input', (e) => set({balls: Number(e.target.value)}));
$('speed').addEventListener('input', (e) => set({speed: Number(e.target.value)}));
$('pause').addEventListener('click', () => set({paused: $('pause').textContent === 'Pause'}));
$('shot').addEventListener('click', () => jumphp.invoke('shot'));

jumphp.on('settings', show);           // the game or the tray changed something
jumphp.on('stats', (s) => { $('sBalls').textContent = s.balls; $('sBounces').textContent = s.bounces; $('sFps').textContent = s.fps; });
jumphp.invoke('settings').then(show);
