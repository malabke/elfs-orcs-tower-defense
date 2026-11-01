// The Game tool window (right side): the game played in the IDE, as the playground's editor does it. Web IDE: the
// play page with the owner's drafts (preview.url). Live: each file saved goes into the running game without
// reloading it ({type: 'jumphp:hot'} to the page, engine/web/jumphp.js: pictures and sounds in place, code by PHP
// started again in the same engine, in the scene that was playing); no answer in 4 s: the game is relaunched.
// What the game's PHP writes (jumphp:output) goes to the console under it.
import { backend } from './backend.js';
import { el, clear, icon, notify, prefs } from './ui.js';

export class GamePanel {
  constructor(app) {
    this.app = app;
    this.live = prefs.get('gameLive', true);
    this.ready = false;
    this.hotWait = null;
    this.frame = null;
    this.status = el('span.game-status');
    this.log = el('div.game-log');
    this.consoleBox = el('details.game-console', { ontoggle: () => { this.log.scrollTop = this.log.scrollHeight; } },
      el('summary', {}, 'Console', el('button.game-clear', { title: 'Clear', onclick: (e) => { e.preventDefault(); clear(this.log); } }, icon('close'))), this.log);
    this.screen = el('div.game-screen', {}, el('div.placeholder', {}, el('p', {}, 'Play the game here, beside the code.'),
      el('button.btn.primary', { onclick: () => this.play() }, icon('play'), 'Play')));
    this.node = el('div.game-panel', {}, this.screen, this.consoleBox);
    addEventListener('message', (e) => this.message(e));
    app.editors.on('saved', () => this.saved());
  }

  title() { return 'Game'; }

  headerExtra() { return this.status; }

  actions() {
    return [
      el(`button${this.live ? '.on' : ''}`, { title: this.live ? 'Live: each save goes into the game (click: off)' : 'Live is off: saves wait for Restart (click: on)', onclick: () => {
        this.live = !this.live;
        prefs.set('gameLive', this.live);
        this.app.refreshToolHeader('game');
      } }, el('span.game-live', {}, '⚡')),
      el('button', { title: 'Restart the game', onclick: () => this.play() }, icon('refresh')),
      el('button', { title: 'Open in a new tab', onclick: () => this.url && this.app.openUrl(this.url) }, icon('globe')),
      el('button', { title: 'Stop', onclick: () => this.stop() }, icon('stop')),
    ];
  }

  /** Starts (or starts again) the game with what is saved. */
  async play() {
    if (this.app.open.right !== 'game') this.app.showTool('game');
    await this.app.editors.saveAll(true);
    this.say('starting…');
    try {
      this.url = (await backend.invoke('preview.url')).url;
    } catch (e) {
      this.say('');
      notify(e.message, 'error', { title: 'Game' });
      return;
    }
    this.ready = false;
    clearTimeout(this.hotWait);
    this.hotWait = null;
    if (!this.frame) {
      clear(this.screen);
      this.frame = el('iframe.game-frame', { allow: 'autoplay; fullscreen; gamepad; clipboard-write', title: 'Game' });
      this.screen.append(this.frame);
    }
    this.separator('game started');
    this.frame.src = this.url;
  }

  stop() {
    clearTimeout(this.hotWait);
    this.hotWait = null;
    this.ready = false;
    this.frame?.remove();
    this.frame = null;
    this.say('');
    clear(this.screen);
    this.screen.append(el('div.placeholder', {}, el('p', {}, 'The game is stopped.'), el('button.btn.primary', { onclick: () => this.play() }, icon('play'), 'Play')));
  }

  /** A file was saved: into the running game (live). */
  saved() {
    if (!this.live || !this.frame) return;
    clearTimeout(this.debounce);
    this.debounce = setTimeout(() => this.hot(), 250);
  }

  hot() {
    if (!this.frame) return;
    if (!this.ready || this.hotWait) {
      if (!this.hotWait) this.play();
      return;
    }
    this.say('reloading…');
    this.hotWait = setTimeout(() => { this.hotWait = null; this.play(); }, 4000);
    this.frame.contentWindow.postMessage({ type: 'jumphp:hot' }, '*');
  }

  message(event) {
    if (!this.frame || event.source !== this.frame.contentWindow) return;
    const data = event.data || {};
    if (data.type === 'jumphp:ready') {
      this.ready = true;
      this.say('playing');
    } else if (data.type === 'jumphp:hot-done') {
      clearTimeout(this.hotWait);
      this.hotWait = null;
      if (data.result === 'error') {
        this.say('error');
        this.line('err', 'Hot reload failed: ' + String(data.message || '').split('\n')[0].replace(/^\[jumphp\] BOOT FAILED: /, ''));
        this.consoleBox.open = true;
      } else {
        this.say({ none: 'playing', restart: 'restarted with the new code' }[data.result] || 'reloaded');
        if (data.result === 'restart') this.separator('PHP restarted with the new code');
        setTimeout(() => { if (!this.hotWait && this.ready) this.say('playing'); }, 2500);
      }
    } else if (data.type === 'jumphp:output' && Array.isArray(data.lines)) {
      for (const [stream, text] of data.lines) this.line(stream === 'err' ? 'err' : 'out', String(text));
      if (data.dropped) this.line('err', `(${data.dropped} lines dropped)`);
    }
  }

  say(text) { this.status.textContent = text; }

  line(stream, text) {
    const atBottom = this.log.scrollHeight - this.log.scrollTop - this.log.clientHeight < 30;
    this.log.append(el(`pre${stream === 'err' ? '.err' : ''}`, {}, text));
    while (this.log.childElementCount > 2000) this.log.firstElementChild.remove();
    if (atBottom) this.log.scrollTop = this.log.scrollHeight;
    if (stream === 'err' && !this.consoleBox.open) this.consoleBox.classList.add('has-errors');
  }

  separator(text) {
    if (this.log.firstChild) this.log.append(el('div.game-sep', {}, text));
  }
}
