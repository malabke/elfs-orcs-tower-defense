// Run / Debug icons on the line (the editor's gutter, left of the line numbers): a scene's class line (run THAT scene, not the whole project: the
// game starts on it, JUMPHP_SCENE; also for the samples of an extension, run through its example game), and a CLI app's command
// (#[AsCommand(name: 'add')]) gets a ▶ that runs the program with that command, from the line, and Debug (Xdebug)
// with the same arguments. The arguments (the command's options and values) are typed once and remembered per
// command (prefs), like the run configurations.
import { monaco, pathOf } from './editor.js';
import { menu, prompt, prefs, debounce } from './ui.js';

const COMMAND = /#\[AsCommand\(\s*(?:name:\s*)?(['"])([^'"]+)\1/;
const NAMESPACE = /^namespace\s+([^;\s]+)\s*;/;
const SCENE_CLASS = /^(?:final\s+)?class\s+(\w+)\s+extends\s+\\?(?:[\w\\]*\\)?(\w*Scene)\b/;

/** "add 'two words' --done" -> ['add', 'two words', '--done'] (quotes group, no shell). */
export function splitArgs(text) {
  const out = [];
  const re = /"([^"]*)"|'([^']*)'|(\S+)/g;
  let m;
  while ((m = re.exec(String(text || '')))) out.push(m[1] ?? m[2] ?? m[3]);
  return out;
}

export class GutterRun {
  constructor(app) {
    this.app = app;
    this.marks = new Map();            // model uri => [{ line, name }]
    this.decorations = new Map();      // model uri => decoration ids
    for (const group of app.editors.groups) this.attach(group.editor);
    monaco.editor.onDidCreateEditor((editor) => this.attach(editor));
  }

  attach(editor) {
    if (editor.__gutterRun) return;
    editor.__gutterRun = true;
    const refresh = debounce(() => this.scan(editor.getModel()), 300);
    editor.onDidChangeModel(() => this.scan(editor.getModel()));
    editor.onDidChangeModelContent(refresh);
    this.scan(editor.getModel());
    editor.__gutterRunAt = (line) => !!this.markAt(editor.getModel(), line);
    editor.onMouseDown((e) => {
      if (e.target.type !== monaco.editor.MouseTargetType.GUTTER_LINE_DECORATIONS) return;
      const model = editor.getModel();
      const mark = model && e.target.position ? this.markAt(model, e.target.position.lineNumber) : null;
      if (mark) this.open(model, mark, { x: e.event.posx, y: e.event.posy });
    });
  }

  markAt(model, line) { return model ? (this.marks.get(model.uri.toString()) || []).find((m) => m.line === line) || null : null; }

  /** The module (apps/<id>/ or games/<id>/) a file is in, when it has a Run configuration. */
  moduleOf(path) {
    const m = /^(?:games|apps)\/([^/]+)\//.exec(path);
    return m && this.app.runner?.config('run:' + m[1]) ? m[1] : null;
  }

  /** The game that runs the scenes of a file: its own module, or (a sample of an extension project) the extension's example game <id>-demo. */
  gameOf(path) {
    const own = this.moduleOf(path);
    if (own) return own;
    const e = /^extensions\/([^/]+)\//.exec(path);
    return e && this.app.runner?.config('run:' + e[1] + '-demo') ? e[1] + '-demo' : null;
  }

  scan(model) {
    if (!model || model.isDisposed() || model.getLanguageId() !== 'php') return;
    const key = model.uri.toString();
    const marks = [];
    const path = pathOf(model.uri);
    if (this.moduleOf(path)) {
      model.getLinesContent().forEach((text, i) => { const m = COMMAND.exec(text); if (m) marks.push({ line: i + 1, name: m[2] }); });
    }
    if (this.gameOf(path)) {
      const lines = model.getLinesContent();
      const ns = lines.map((l) => NAMESPACE.exec(l)).find(Boolean)?.[1];
      lines.forEach((text, i) => {
        const m = SCENE_CLASS.exec(text);
        if (m && ns) marks.push({ line: i + 1, name: m[1], scene: `${ns}\\${m[1]}` });
      });
    }
    this.marks.set(key, marks);
    this.decorations.set(key, model.deltaDecorations(this.decorations.get(key) || [], marks.map((m) => ({
      range: new monaco.Range(m.line, 1, m.line, 1),
      options: { linesDecorationsClassName: 'run-gutter', linesDecorationsTooltip: m.scene ? `Run the scene ${m.name} (only this scene)` : `Run or debug the command ${m.name}` },
    }))));
  }

  key(path, name) { return `runargs:${this.app.projectKey}:${path}:${name}`; }

  /** The run configuration of the module with the command and its remembered arguments added to it. */
  config(model, mark) {
    const path = pathOf(model.uri);
    const base = this.app.runner.config('run:' + this.moduleOf(path));
    const args = splitArgs(prefs.get(this.key(path, mark.name), ''));
    return { ...base, id: base.id + ':' + mark.name, label: `${base.group} ${mark.name}${args.length ? ' ' + args.join(' ') : ''}`, command: [...base.command, mark.name, ...args] };
  }

  /** The scene's run configuration: its game, started on that scene. */
  sceneConfig(model, mark) {
    const game = this.gameOf(pathOf(model.uri));
    const base = this.app.runner.config('run:' + game);
    return { config: { ...base, id: base.id + ':' + mark.name, label: `${game}: ${mark.name}` }, env: { JUMPHP_SCENE: mark.scene } };
  }

  open(model, mark, at) {
    const path = pathOf(model.uri);
    if (mark.scene) {
      const { config, env } = this.sceneConfig(model, mark);
      menu([
        { label: `Run '${mark.name}'`, icon: 'play', action: () => this.app.runner.start(config, env) },
        this.app.debugger ? { label: `Debug '${mark.name}'`, icon: 'debug', action: () => this.app.debugger.debug(config, env) } : null,
      ].filter(Boolean), at);
      return;
    }
    const shown = () => this.config(model, mark).label;
    menu([
      { label: `Run '${shown()}'`, icon: 'play', action: () => this.app.runner.start(this.config(model, mark)) },
      this.app.debugger ? { label: `Debug '${shown()}'`, icon: 'debug', action: () => this.app.debugger.debug(this.config(model, mark)) } : null,
      { label: 'Arguments…', action: async () => {
        const value = await prompt(`Arguments of ${mark.name}`, { value: prefs.get(this.key(path, mark.name), ''), label: 'Options and values after the command name (quotes group words)', placeholder: "'Buy milk' --verbose", ok: 'Save' });
        if (value !== null && value !== undefined) prefs.set(this.key(path, mark.name), String(value).trim());
      } },
    ].filter(Boolean), at);
  }
}
