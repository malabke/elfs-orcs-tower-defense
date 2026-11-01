/* JumPHP's Tauri runtime (libjumphp_tauri): what PHP loads with FFI (engine/src/Tauri/TauriRuntime.php).
 * All calls on the process' main thread. See tauri/src/lib.rs and docs/tauri.md. */
#ifndef JUMPHP_TAURI_H
#define JUMPHP_TAURI_H
#include <stddef.h>

/* Runs the app (native event loop on this thread) until PHP sends {"cmd":"app.exit"}; returns the exit code,
 * negative when it could not start. frame() is called at every frame: PHP reads jt_poll() and calls jt_send(). */
int jt_run(const char *config_json, void (*frame)(void));
/* The events since the last call: a JSON array, valid until the next call. */
const char *jt_poll(void);
/* One command, a JSON object {"cmd": "window.set_title", ...}: run after the current frame. */
void jt_send(const char *command_json);
/* The embedded game's newest picture (ui: hybrid, app.embed): a BMP, served as jumphpframe://localhost/frame. */
void jt_frame(const unsigned char *data, size_t len);
const char *jt_version(void);

#endif
