// window.jumphp in every page of a JumPHP desktop app (injected before the page's own scripts).
//
//   const r = await jumphp.invoke('greet', {name: 'Ada'});    // App::command('greet', fn (array $p) => ...) in PHP
//   jumphp.on('tick', (payload) => ...);                      // $app->emit('tick', [...]) / $window->emit(...) in PHP
//   jumphp.send('clicked', {x: 1});                           // $app->on('page:clicked', fn (array $p) => ...)
(function () {
  if (window.jumphp) return;
  const pending = new Map();
  const listeners = new Map();
  let next = 1;
  const call = (cmd, args) => window.__TAURI_INTERNALS__.invoke(cmd, args);

  window.jumphp = {
    native: true,
    invoke(name, payload) {
      const id = next++;
      return new Promise((resolve, reject) => {
        pending.set(id, {resolve, reject});
        call('jumphp_invoke', {id, name: String(name), payload: payload === undefined ? null : payload})
          .catch((e) => { pending.delete(id); reject(e); });
      });
    },
    send(name, payload) {
      return call('jumphp_emit', {name: String(name), payload: payload === undefined ? null : payload});
    },
    on(name, fn) {
      if (!listeners.has(name)) listeners.set(name, new Set());
      listeners.get(name).add(fn);
      return () => this.off(name, fn);
    },
    off(name, fn) {
      const set = listeners.get(name);
      if (set) set.delete(fn);
    },
    __resolve(id, ok, value) {
      const p = pending.get(id);
      if (!p) return;
      pending.delete(id);
      if (ok) {
        p.resolve(value);
      } else {
        const e = new Error(value && value.message ? value.message : String(value));
        e.data = value;
        p.reject(e);
      }
    },
    __dispatch(name, payload) {
      const set = listeners.get(name);
      if (!set) return;
      for (const fn of Array.from(set)) {
        try { fn(payload); } catch (e) { console.error(e); }
      }
    },
  };
  window.dispatchEvent(new Event('jumphp:ready'));
  // PHP learns the page is there (Window 'loaded', and what `bin/jumphp app:test` checks)
  const loaded = () => window.jumphp.send('__loaded', location.href).catch(() => {});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loaded); else loaded();
})();
