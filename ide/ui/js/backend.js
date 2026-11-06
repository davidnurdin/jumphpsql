// The IDE's link to its PHP side, the same API on both targets:
//   desktop app (games/ide): window.jumphp (Tauri), PHP in the same process (JumPHP\Tauri\App)
//   web IDE (jumphp.net/ide/<project>): fetch() to the playground, config in window.JUMPHP_IDE
//     {backend: 'web', api: '/api/ide/<slug>', csrf: '...', project: {...}, user: {...}, playUrl: '...'}
//
//   await backend.invoke('file.read', {path})   -> the command's result (rejects with its error message)
//   const off = backend.on('run.output', fn)    -> PHP's events (desktop: pushed; web: carried by the answers)

const config = window.JUMPHP_IDE || {};
const listeners = new Map();

function dispatch(event, payload) {
  for (const fn of listeners.get(event) || []) {
    try { fn(payload); } catch (e) { console.error(e); }
  }
}

const native = typeof window.jumphp !== 'undefined' && window.jumphp.native;

/** An error message may end with "[[actions:[...]]]": the buttons jumphp.net attached to its error; they go to `error.actions`. */
function withActions(e) {
  const m = /\n?\[\[actions:(\[.*\])\]\]\s*$/s.exec(String(e?.message ?? e));
  if (!m) return e instanceof Error ? e : new Error(String(e));
  const error = new Error(String(e.message).slice(0, m.index));
  try { error.actions = JSON.parse(m[1]); } catch { /* no buttons */ }
  return error;
}

export const backend = {
  kind: native ? 'native' : 'web',
  config,

  async invoke(name, payload = {}) {
    if (native) {
      try { return await window.jumphp.invoke('ide', { name, payload }); } catch (e) { throw withActions(e); }
    }
    const response = await fetch(`${config.api}/invoke`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf || '' },
      credentials: 'same-origin',
      body: JSON.stringify({ name, payload }),
    });
    let data;
    try { data = await response.json(); } catch { throw new Error(`HTTP ${response.status}`); }
    for (const [event, eventPayload] of data.events || []) dispatch(event, eventPayload);
    if (!response.ok || data.error) throw new Error(data.error || `HTTP ${response.status}`);
    for (const fn of this.after) {
      try { fn(name, payload, data.result); } catch (e) { console.error(e); }
    }
    return data.result;
  },

  /** Called after each command that succeeded (web): fn(name, payload, result). The web IDE's plugins follow the saves. */
  after: new Set(),

  on(event, fn) {
    if (!listeners.has(event)) {
      listeners.set(event, new Set());
      if (native) window.jumphp.on(event, (payload) => dispatch(event, payload));
    }
    listeners.get(event).add(fn);
    return () => listeners.get(event).delete(fn);
  },

  send(event, payload = {}) {
    if (native) window.jumphp.send(event, payload);
  },
};
