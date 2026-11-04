// Tools > Video Preview…: the video templates of a game (project.yaml `video:`, docs/video-maker.md) played live, as the
// video will look: the scene runs headless at the speed of the game and the IDE shows its frames as they are written
// (JUMPHP_LIVE, the same frames the Claude window shows). Choose the template, the seed, the length, the title and the captions,
// preview, then "Make the MP4" runs bin/jumphp video:make with the same choices (the sound is in the film, not in the preview).
import { backend } from './backend.js';
import { el, dialog, prefs, notify } from './ui.js';

/** "TEXT@FROM-TO[@place][@style]" -> {text, from, to, at?, style?} (Captions::parseSpec of the shorts extension, the same rules). */
export function parseCaption(spec) {
  const parts = spec.split('@');
  const text = (parts.shift() || '').trim();
  const m = /^(\d+(?:\.\d+)?)?\s*-\s*(\d+(?:\.\d+)?)?$/.exec((parts.shift() || '').trim());
  if (!text || !m) throw new Error(`caption "${spec}": TEXT@FROM-TO[@top|middle|bottom|<y>][@plain|box|pop|type]`);
  const item = { text, from: Number(m[1] || 0), to: m[2] ? Number(m[2]) : 1e9 };
  for (const raw of parts) {
    const part = raw.trim().toLowerCase();
    if (['plain', 'box', 'pop', 'type'].includes(part)) item.style = part;
    else if (['top', 'middle', 'bottom'].includes(part)) item.at = part;
    else if (/^\d+$/.test(part)) item.at = Number(part);
    else if (part) throw new Error(`caption "${spec}": "${part}" is neither a place nor a style`);
  }
  return item;
}

export class VideoPreview {
  constructor(app) { this.app = app; }

  available() { return backend.kind === 'native' && !!this.app.project?.capabilities?.run; }

  registerActions(a) {
    if (this.available()) a.add('videoPreview', 'Video Preview…', () => this.open(), { icon: 'play' });
  }

  async open() {
    const games = await backend.invoke('video.settings').catch(() => []);
    if (!games.length) {
      dialog({ title: 'Video Preview', body: el('div', {}, el('p', {}, 'No game of this project makes videos yet.'),
        el('p', { class: 'muted' }, 'A game does when its config/project.yaml has a video: block (the scene to film, its params, the seconds): see docs/video-maker.md, and games/shorts-demo.')) });
      return;
    }
    const saved = prefs.get('videoPreview', {});
    const game = el('select.input.small', {}, ...games.map((g) => el('option', { value: g.id }, g.title)));
    game.value = games.some((g) => g.id === saved.game) ? saved.game : games[0].id;
    const template = el('select.input.small', {});
    const seed = el('input.input.small.res-num', { type: 'number', min: 1, value: saved.seed || 7 });
    const seconds = el('input.input.small.res-num', { type: 'number', min: 2, max: 600, value: saved.seconds || 15 });
    const title = el('input.input.small', { placeholder: 'the question at the top (empty: the template\'s)', value: saved.title || '' });
    const captions = el('textarea.input', { rows: 3, placeholder: 'one a line: Wait for it@2-5@middle@pop' }, saved.captions || '');
    const status = el('p.res-note', {}, '');
    const view = el('img', { class: 'video-frame', alt: '', style: { maxHeight: '58vh', maxWidth: '100%', imageRendering: 'pixelated', background: '#000', display: 'block', margin: '0 auto' } });
    const current = () => games.find((g) => g.id === game.value) || games[0];
    const fillTemplates = () => {
      const g = current();
      template.replaceChildren(...(g.templates.length ? g.templates : ['']).map((t) => el('option', { value: t }, t || '(the scene\'s own)')));
      template.value = saved.template && g.templates.includes(saved.template) ? saved.template : (g.params.template || g.templates[0] || '');
    };
    game.onchange = fillTemplates;
    fillTemplates();

    const settings = () => {
      const g = current();
      const list = captions.value.split('\n').map((l) => l.trim()).filter(Boolean);
      const parsed = list.map(parseCaption);
      const params = { ...g.params, seed: Number(seed.value) || 1, seconds: Number(seconds.value) || 15, captions: [...(g.captions || []), ...parsed] };
      if (template.value) params.template = template.value;
      if (title.value.trim()) params.title = title.value.trim();
      prefs.set('videoPreview', { game: g.id, template: template.value, seed: Number(seed.value), seconds: Number(seconds.value), title: title.value, captions: captions.value });
      return { g, params, specs: list };
    };

    let timer = null;
    let runId = null;
    const stop = async () => {
      clearInterval(timer);
      timer = null;
      const run = this.app.runner.runs?.find((r) => r.id === runId && r.code === undefined);
      if (run) this.app.runner.stop(run);
      runId = null;
    };
    const play = async () => {
      let s;
      try { s = settings(); } catch (e) { status.textContent = e.message; return false; }
      await stop();
      status.textContent = 'Playing at the video\'s speed…';
      const env = { JUMPHP_SCENE: s.g.scene, JUMPHP_SCENE_PARAMS: JSON.stringify(s.params), JUMPHP_LIVE: '1', JUMPHP_LIVE_EVERY: '2', JUMPHP_REALTIME: '1', JUMPHP_GL: '0' };
      runId = await this.app.runner.start({ id: 'adhoc', kind: 'tool', label: `Video preview of ${s.g.id}`, command: ['bin/jumphp', 'shot', s.g.id, String(Math.round(s.params.seconds * 60))] }, env);
      timer = setInterval(async () => {
        const data = await backend.invoke('file.read', { path: 'var/live/frame.png' }).catch(() => null);
        if (data?.image) view.src = data.image;
      }, 120);
      return false;     // the dialog stays open: the frames are shown in it
    };
    const make = async () => {
      let s;
      try { s = settings(); } catch (e) { status.textContent = e.message; return false; }
      const command = ['bin/jumphp', 'video:make', s.g.id, '--seed', String(s.params.seed), '--seconds', String(s.params.seconds),
        ...(template.value ? ['--template', template.value] : []), ...(title.value.trim() ? ['--title', title.value.trim()] : []),
        ...s.specs.flatMap((c) => ['--caption', c])];
      await this.app.runner.start({ id: 'adhoc', kind: 'build', label: `Video of ${s.g.id}`, command });
      notify('The video is made in the Run window: games/' + s.g.id + '/dist/video/ (MP4 + cover).', 'info', { title: 'Video' });
      return false;
    };
    const row = (label, ...nodes) => el('label.res-row', {}, el('span.res-label', {}, label), ...nodes);
    dialog({
      title: 'Video Preview',
      wide: true,
      onClose: () => { stop(); },
      body: el('div.res-ai', {}, view,
        row('Game', game), row('Template', template), row('Seed', seed), row('Seconds', seconds), row('Title', title),
        el('label', {}, 'Captions'), captions,
        status,
        el('p.res-note', {}, 'The same seed makes the same video. The preview is silent: the sound is mixed in the MP4.')),
      buttons: [{ label: 'Close' }, { label: 'Make the MP4', action: make }, { label: 'Preview', primary: true, action: play }],
    });
  }
}
