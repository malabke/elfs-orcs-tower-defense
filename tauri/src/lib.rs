//! JumPHP's Tauri runtime: a C library that PHP loads with FFI (engine/src/Tauri/TauriRuntime.php).
//!
//! PHP is the program. It calls `jt_run()` from its main thread: Tauri builds the app and runs its native event
//! loop there (GTK on Linux), and calls PHP back with `frame()` at every frame (`tick_hz` times a second, and at
//! once when something happens), exactly like the browser engine calls `jumphp_frame()`. During a frame PHP
//! reads what happened with `jt_poll()` (a JSON array of events) and asks for things with `jt_send()` (one JSON
//! command each); the commands run on the main thread right after the frame. `jt_run()` returns the exit code
//! when PHP asks to quit (`{"cmd":"app.exit"}`).
//!
//! Nothing here decides for PHP: closing a window or the app is an event PHP answers (its default handlers
//! close / quit). The pages are served from a folder chosen at run time (`root`), not compiled in, so one
//! library serves every app.
//!
//! C API (include/jumphp_tauri.h):
//!   int         jt_run(const char *config_json, void (*frame)(void));
//!   const char *jt_poll(void);                // owned by the library until the next jt_poll()
//!   void        jt_send(const char *command_json);
//!   const char *jt_version(void);

use std::borrow::Cow;
use std::collections::{HashMap, VecDeque};
use std::ffi::{c_char, CStr, CString};
use std::panic::{catch_unwind, AssertUnwindSafe};
use std::path::{Component, Path, PathBuf};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::Duration;

use serde_json::{json, Value};
use tauri::utils::assets::{AssetKey, CspHash};
use tauri::{AppHandle, Assets, Emitter, LogicalPosition, LogicalSize, Manager, RunEvent, Runtime, WebviewUrl, WebviewWindowBuilder, WindowEvent, Wry};
use tauri_plugin_dialog::{DialogExt, FilePath, MessageDialogButtons, MessageDialogKind};
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_opener::OpenerExt;
use tauri_plugin_clipboard_manager::ClipboardExt;
use tauri::menu::{CheckMenuItem, IsMenuItem, Menu, MenuItem, MenuItemKind, PredefinedMenuItem, Submenu};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri_plugin_global_shortcut::{GlobalShortcutExt, Shortcut, ShortcutState};

const VERSION: &str = concat!(env!("CARGO_PKG_VERSION"), "\0");

/// The JavaScript every page gets before its own scripts: window.jumphp (engine/web/... has the same API for apps).
const BRIDGE_JS: &str = include_str!("bridge.js");

type FrameFn = extern "C" fn();

static EVENTS: Mutex<VecDeque<Value>> = Mutex::new(VecDeque::new());
static COMMANDS: Mutex<VecDeque<Value>> = Mutex::new(VecDeque::new());
static FRAME: OnceLock<FrameFn> = OnceLock::new();
static HANDLE: OnceLock<AppHandle<Wry>> = OnceLock::new();
static FRAME_PENDING: AtomicBool = AtomicBool::new(false);
static RUNNING: AtomicBool = AtomicBool::new(false);
static STARTED: AtomicBool = AtomicBool::new(false);
static TICK_US: AtomicU64 = AtomicU64::new(16_667);
/// when the last frame ended (µs since START): the ticker leaves the loop some idle time after it, or GTK's
/// lower-priority work (painting the WebViews, their IPC) starves when frames are long (a hybrid game's)
static LAST_END_US: AtomicU64 = AtomicU64::new(0);
static START: OnceLock<std::time::Instant> = OnceLock::new();

fn now_us() -> u64 {
    START.get_or_init(std::time::Instant::now).elapsed().as_micros() as u64
}
/// global shortcuts registered by PHP: their id -> the accelerator PHP gave
static SHORTCUTS: OnceLock<Mutex<HashMap<u32, String>>> = OnceLock::new();

fn shortcuts() -> &'static Mutex<HashMap<u32, String>> {
    SHORTCUTS.get_or_init(|| Mutex::new(HashMap::new()))
}

thread_local! {
    /// what the last jt_poll() returned: alive until the next call (PHP copies it at once)
    static POLLED: std::cell::RefCell<CString> = std::cell::RefCell::new(CString::default());
    static IN_FRAME: std::cell::Cell<bool> = const { std::cell::Cell::new(false) };
}

fn push_event(event: Value) {
    if let Ok(mut q) = EVENTS.lock() {
        q.push_back(event);
    }
    request_frame();
}

/// A frame as soon as the loop is free (coalesced: one pending at a time).
fn request_frame() {
    if !RUNNING.load(Ordering::SeqCst) || FRAME_PENDING.swap(true, Ordering::SeqCst) {
        return;
    }
    if let Some(handle) = HANDLE.get() {
        if handle.run_on_main_thread(do_frame).is_err() {
            FRAME_PENDING.store(false, Ordering::SeqCst);
        }
    }
}

/// On the main thread: PHP's frame, then the commands it sent.
fn do_frame() {
    FRAME_PENDING.store(false, Ordering::SeqCst);
    if !RUNNING.load(Ordering::SeqCst) || IN_FRAME.with(|f| f.replace(true)) {
        return;
    }
    if let Some(frame) = FRAME.get() {
        frame();
    }
    IN_FRAME.with(|f| f.set(false));
    LAST_END_US.store(now_us(), Ordering::SeqCst);
    if let Some(handle) = HANDLE.get() {
        let commands: Vec<Value> = COMMANDS.lock().map(|mut q| q.drain(..).collect()).unwrap_or_default();
        for command in commands {
            let name = command.get("cmd").and_then(Value::as_str).unwrap_or("").to_string();
            // a panic in a plugin (a missing system library...) must not cross into PHP
            let result = catch_unwind(AssertUnwindSafe(|| run_command(handle, &name, &command)))
                .unwrap_or_else(|_| Err(format!("{name}: the native side panicked")));
            if let Err(e) = result {
                match command.get("id") {
                    // a command PHP waits for: its Task fails
                    Some(id) if !id.is_null() => push_event(json!({"type": "result", "id": id, "error": e})),
                    _ => push_event(json!({"type": "error", "cmd": name, "message": e})),
                }
            }
        }
    }
}

// --- the pages: a folder on disk, read at run time ------------------------------------------------------------

struct FolderAssets {
    root: PathBuf,
    /// JumPHP's own pages (config "engine_pages"), served under /__jumphp/ (the embedded game's page)
    engine: Option<PathBuf>,
}

impl FolderAssets {
    fn path(&self, key: &str) -> Option<PathBuf> {
        let key = key.trim_start_matches('/');
        let (root, key) = match (key.strip_prefix("__jumphp/"), &self.engine) {
            (Some(rest), Some(engine)) => (engine, rest),
            _ => (&self.root, key),
        };
        let rel = Path::new(key);
        // never outside the folder
        if rel.components().any(|c| !matches!(c, Component::Normal(_))) {
            return None;
        }
        let path = root.join(rel);
        path.is_file().then_some(path)
    }
}

// --- the embedded game's frames (ui: hybrid, app.embed): PHP hands each picture over, the page fetches it -----------

static FRAME_NO: AtomicU64 = AtomicU64::new(0);

fn frame_data() -> &'static Mutex<Vec<u8>> {
    static DATA: OnceLock<Mutex<Vec<u8>>> = OnceLock::new();
    DATA.get_or_init(|| Mutex::new(Vec::new()))
}

/// jumphpframe://localhost/frame?after=<n> (Windows: http://jumphpframe.localhost/...): the newest picture (a BMP),
/// 204 while it is still picture n.
fn frame_response(request: &tauri::http::Request<Vec<u8>>) -> tauri::http::Response<Vec<u8>> {
    let current = FRAME_NO.load(Ordering::SeqCst);
    let after = request
        .uri()
        .query()
        .and_then(|q| q.split('&').find_map(|kv| kv.strip_prefix("after=")))
        .and_then(|v| v.parse::<u64>().ok());
    let builder = tauri::http::Response::builder()
        .header("Access-Control-Allow-Origin", "*")
        .header("Access-Control-Expose-Headers", "X-Frame")
        .header("Cache-Control", "no-store")
        .header("X-Frame", current.to_string());
    if after == Some(current) || current == 0 {
        return builder.status(204).body(Vec::new()).unwrap_or_default();
    }
    let body = frame_data().lock().map(|d| d.clone()).unwrap_or_default();
    builder.status(200).header("Content-Type", "image/bmp").body(body).unwrap_or_default()
}

impl<R: Runtime> Assets<R> for FolderAssets {
    fn get(&self, key: &AssetKey) -> Option<Cow<'_, [u8]>> {
        let key = key.as_ref();
        let path = self.path(key).or_else(|| {
            // "/" and folders: their index.html
            let index = format!("{}/index.html", key.trim_end_matches('/'));
            self.path(&index)
        })?;
        std::fs::read(path).ok().map(Cow::Owned)
    }

    fn iter(&self) -> Box<dyn Iterator<Item = (Cow<'_, str>, Cow<'_, [u8]>)> + '_> {
        Box::new(std::iter::empty())
    }

    fn csp_hashes(&self, _html_path: &AssetKey) -> Box<dyn Iterator<Item = CspHash<'_>> + '_> {
        Box::new(std::iter::empty())
    }
}

// --- JS -> PHP: window.jumphp.invoke(name, payload) --------------------------------------------------------------

#[tauri::command]
fn jumphp_invoke(webview: tauri::Webview<Wry>, id: u64, name: String, payload: Value) {
    push_event(json!({"type": "invoke", "window": webview.label(), "id": id, "name": name, "payload": payload}));
}

#[tauri::command]
fn jumphp_emit(webview: tauri::Webview<Wry>, name: String, payload: Value) {
    push_event(json!({"type": "page", "window": webview.label(), "name": name, "payload": payload}));
}

// --- PHP -> Tauri: the commands --------------------------------------------------------------------------------

fn str_of<'a>(c: &'a Value, key: &str) -> Option<&'a str> {
    c.get(key).and_then(Value::as_str)
}

fn f64_of(c: &Value, key: &str) -> Option<f64> {
    c.get(key).and_then(Value::as_f64)
}

fn bool_of(c: &Value, key: &str) -> Option<bool> {
    c.get(key).and_then(Value::as_bool)
}

fn window(handle: &AppHandle<Wry>, c: &Value) -> Result<tauri::WebviewWindow<Wry>, String> {
    let label = str_of(c, "window").unwrap_or("main");
    handle.get_webview_window(label).ok_or_else(|| format!("no window '{label}'"))
}

fn js_value(v: &Value) -> String {
    // JSON is JavaScript, except two line separators that JSON allows in strings
    v.to_string().replace('\u{2028}', "\\u2028").replace('\u{2029}', "\\u2029")
}

fn path_string(p: FilePath) -> String {
    match p.into_path() {
        Ok(path) => path.to_string_lossy().into_owned(),
        Err(e) => format!("{e}"),
    }
}

// --- window / application menus ------------------------------------------------------------------------------
// The items' ids are "\u{1e}<window>\u{1f}<item>" (window "*": the application's menu), so their clicks are told
// apart from the tray's ("<tray>\u{1f}<item>") and PHP gets {type: menu, window, item, checked}.

fn menu_items() -> &'static Mutex<HashMap<String, MenuItemKind<Wry>>> {
    static ITEMS: OnceLock<Mutex<HashMap<String, MenuItemKind<Wry>>>> = OnceLock::new();
    ITEMS.get_or_init(|| Mutex::new(HashMap::new()))
}

fn build_menu_items(handle: &AppHandle<Wry>, prefix: &str, items: &[Value]) -> Result<Vec<MenuItemKind<Wry>>, String> {
    let s = |e: tauri::Error| e.to_string();
    let mut out = Vec::new();
    for item in items {
        let text = str_of(item, "text");
        let enabled = bool_of(item, "enabled").unwrap_or(true);
        let kind = if bool_of(item, "separator").unwrap_or(false) {
            MenuItemKind::Predefined(PredefinedMenuItem::separator(handle).map_err(s)?)
        } else if let Some(role) = str_of(item, "role") {
            MenuItemKind::Predefined(
                match role {
                    "quit" => PredefinedMenuItem::quit(handle, text),
                    "about" => PredefinedMenuItem::about(handle, text, None),
                    "copy" => PredefinedMenuItem::copy(handle, text),
                    "cut" => PredefinedMenuItem::cut(handle, text),
                    "paste" => PredefinedMenuItem::paste(handle, text),
                    "select_all" => PredefinedMenuItem::select_all(handle, text),
                    "undo" => PredefinedMenuItem::undo(handle, text),
                    "redo" => PredefinedMenuItem::redo(handle, text),
                    "minimize" => PredefinedMenuItem::minimize(handle, text),
                    "maximize" => PredefinedMenuItem::maximize(handle, text),
                    "fullscreen" => PredefinedMenuItem::fullscreen(handle, text),
                    "hide" => PredefinedMenuItem::hide(handle, text),
                    "hide_others" => PredefinedMenuItem::hide_others(handle, text),
                    "show_all" => PredefinedMenuItem::show_all(handle, text),
                    "close_window" => PredefinedMenuItem::close_window(handle, text),
                    "services" => PredefinedMenuItem::services(handle, text),
                    other => return Err(format!("unknown menu role '{other}'")),
                }
                .map_err(s)?,
            )
        } else if let Some(children) = item.get("items").and_then(Value::as_array) {
            let built = build_menu_items(handle, prefix, children)?;
            let refs: Vec<&dyn IsMenuItem<Wry>> = built.iter().map(|k| k as &dyn IsMenuItem<Wry>).collect();
            MenuItemKind::Submenu(Submenu::with_items(handle, text.unwrap_or(""), enabled, &refs).map_err(s)?)
        } else {
            let id = format!("{prefix}{}", str_of(item, "id").unwrap_or(""));
            let label = text.or_else(|| str_of(item, "id")).unwrap_or("");
            let accel = str_of(item, "accelerator");
            let kind = match bool_of(item, "checked") {
                Some(checked) => MenuItemKind::Check(CheckMenuItem::with_id(handle, id.clone(), label, enabled, checked, accel).map_err(s)?),
                None => MenuItemKind::MenuItem(MenuItem::with_id(handle, id.clone(), label, enabled, accel).map_err(s)?),
            };
            if let Ok(mut map) = menu_items().lock() {
                map.insert(id, kind.clone());
            }
            kind
        };
        out.push(kind);
    }
    Ok(out)
}

fn on_menu(id: &str) {
    let Some(rest) = id.strip_prefix('\u{1e}') else { return };
    let Some((window, item)) = rest.split_once('\u{1f}') else { return };
    let checked = menu_items().lock().ok().and_then(|m| m.get(id).and_then(|k| k.as_check_menuitem().and_then(|c| c.is_checked().ok())));
    push_event(json!({"type": "menu", "window": window, "item": item, "checked": checked}));
}

fn run_command(handle: &AppHandle<Wry>, name: &str, c: &Value) -> Result<(), String> {
    let id = c.get("id").cloned().unwrap_or(Value::Null);
    let e = |r: tauri::Result<()>| r.map_err(|e| e.to_string());
    match name {
        "window.create" => {
            let label = str_of(c, "window").unwrap_or("main").to_string();
            let url = str_of(c, "url").unwrap_or("index.html");
            let url = if url.starts_with("http://") || url.starts_with("https://") {
                WebviewUrl::External(url.parse().map_err(|e| format!("url: {e}"))?)
            } else {
                WebviewUrl::App(url.trim_start_matches('/').into())
            };
            let mut b = WebviewWindowBuilder::new(handle, &label, url)
                .title(str_of(c, "title").unwrap_or("JumPHP"))
                .inner_size(f64_of(c, "width").unwrap_or(960.0), f64_of(c, "height").unwrap_or(640.0))
                .resizable(bool_of(c, "resizable").unwrap_or(true))
                .fullscreen(bool_of(c, "fullscreen").unwrap_or(false))
                .maximized(bool_of(c, "maximized").unwrap_or(false))
                .decorations(bool_of(c, "decorations").unwrap_or(true))
                .always_on_top(bool_of(c, "always_on_top").unwrap_or(false))
                .visible(bool_of(c, "visible").unwrap_or(true))
                .initialization_script(BRIDGE_JS);
            if let (Some(w), Some(h)) = (f64_of(c, "min_width"), f64_of(c, "min_height")) {
                b = b.min_inner_size(w, h);
            }
            if bool_of(c, "center").unwrap_or(true) {
                b = b.center();
            }
            if let (Some(x), Some(y)) = (f64_of(c, "x"), f64_of(c, "y")) {
                b = b.position(x, y);
            }
            if let Some(dir) = str_of(c, "data_directory") {
                b = b.data_directory(PathBuf::from(dir));
            }
            if let Some(icon) = str_of(c, "icon") {
                match tauri::image::Image::from_path(icon) {
                    Ok(img) => b = b.icon(img).map_err(|e| e.to_string())?,
                    Err(err) => push_event(json!({"type": "error", "cmd": name, "message": format!("icon {icon}: {err}")})),
                }
            }
            let devtools = bool_of(c, "devtools").unwrap_or(false);
            b = b.devtools(devtools);
            let w = b.build().map_err(|e| e.to_string())?;
            #[cfg(any(debug_assertions, feature = "devtools"))]
            if devtools {
                w.open_devtools();
            }
            let _ = w;
            Ok(())
        }
        "window.set_title" => e(window(handle, c)?.set_title(str_of(c, "title").unwrap_or(""))),
        "window.set_size" => e(window(handle, c)?.set_size(LogicalSize::new(f64_of(c, "width").unwrap_or(800.0), f64_of(c, "height").unwrap_or(600.0)))),
        "window.set_position" => e(window(handle, c)?.set_position(LogicalPosition::new(f64_of(c, "x").unwrap_or(0.0), f64_of(c, "y").unwrap_or(0.0)))),
        "window.center" => e(window(handle, c)?.center()),
        "window.set_fullscreen" => e(window(handle, c)?.set_fullscreen(bool_of(c, "on").unwrap_or(true))),
        "window.set_resizable" => e(window(handle, c)?.set_resizable(bool_of(c, "on").unwrap_or(true))),
        "window.set_always_on_top" => e(window(handle, c)?.set_always_on_top(bool_of(c, "on").unwrap_or(true))),
        "window.show" => e(window(handle, c)?.show()),
        "window.hide" => e(window(handle, c)?.hide()),
        "window.focus" => e(window(handle, c)?.set_focus()),
        "window.maximize" => e(window(handle, c)?.maximize()),
        "window.unmaximize" => e(window(handle, c)?.unmaximize()),
        "window.minimize" => e(window(handle, c)?.minimize()),
        // destroy(): no CloseRequested again (PHP already decided)
        "window.close" => e(window(handle, c)?.destroy()),
        "window.navigate" => {
            let w = window(handle, c)?;
            let url = str_of(c, "url").unwrap_or("index.html");
            let js = format!("location.href = {}", js_value(&json!(url)));
            e(w.eval(&js))
        }
        "webview.eval" => e(window(handle, c)?.eval(str_of(c, "js").unwrap_or(""))),
        // PHP -> page: window.jumphp.on(name, fn (payload)) in the page
        "webview.emit" => {
            let js = format!(
                "window.jumphp && window.jumphp.__dispatch({}, {})",
                js_value(&json!(str_of(c, "name").unwrap_or(""))),
                js_value(c.get("payload").unwrap_or(&Value::Null))
            );
            match str_of(c, "window") {
                Some(_) => e(window(handle, c)?.eval(&js)),
                None => {
                    for (_, w) in handle.webview_windows() {
                        let _ = w.eval(&js);
                    }
                    Ok(())
                }
            }
        }
        // the answer of an invoke(): resolves (ok) or rejects its promise in the page
        "invoke.resolve" => {
            let js = format!(
                "window.jumphp && window.jumphp.__resolve({}, {}, {})",
                js_value(&id),
                if bool_of(c, "ok").unwrap_or(true) { "true" } else { "false" },
                js_value(c.get("result").unwrap_or(&Value::Null))
            );
            e(window(handle, c)?.eval(&js))
        }
        "dialog.message" | "dialog.confirm" => {
            let kind = match str_of(c, "kind").unwrap_or("info") {
                "warning" => MessageDialogKind::Warning,
                "error" => MessageDialogKind::Error,
                _ => MessageDialogKind::Info,
            };
            let ok = str_of(c, "ok").map(str::to_string);
            let cancel = str_of(c, "cancel").map(str::to_string);
            let buttons = if name == "dialog.confirm" {
                match (ok, cancel) {
                    (Some(o), Some(n)) => MessageDialogButtons::OkCancelCustom(o, n),
                    _ => MessageDialogButtons::OkCancel,
                }
            } else {
                match ok {
                    Some(o) => MessageDialogButtons::OkCustom(o),
                    None => MessageDialogButtons::Ok,
                }
            };
            let mut d = handle.dialog().message(str_of(c, "message").unwrap_or("")).kind(kind).buttons(buttons);
            if let Some(t) = str_of(c, "title") {
                d = d.title(t);
            }
            if let Ok(w) = window(handle, c) {
                d = d.parent(&w);
            }
            d.show(move |answer| push_event(json!({"type": "dialog", "id": id, "result": answer})));
            Ok(())
        }
        "dialog.open" | "dialog.save" => {
            let mut d = handle.dialog().file();
            if let Some(t) = str_of(c, "title") {
                d = d.set_title(t);
            }
            if let Some(dir) = str_of(c, "directory") {
                d = d.set_directory(dir);
            }
            if let Some(n) = str_of(c, "file_name") {
                d = d.set_file_name(n);
            }
            if let Some(filters) = c.get("filters").and_then(Value::as_object) {
                for (label, exts) in filters {
                    let exts: Vec<String> = exts.as_array().map(|a| a.iter().filter_map(|x| x.as_str().map(str::to_string)).collect()).unwrap_or_default();
                    let refs: Vec<&str> = exts.iter().map(String::as_str).collect();
                    d = d.add_filter(label, &refs);
                }
            }
            if let Ok(w) = window(handle, c) {
                d = d.set_parent(&w);
            }
            let reply = move |result: Value| push_event(json!({"type": "dialog", "id": id, "result": result}));
            if name == "dialog.save" {
                d.save_file(move |p| reply(p.map(|p| Value::String(path_string(p))).unwrap_or(Value::Null)));
            } else if bool_of(c, "folder").unwrap_or(false) {
                d.pick_folder(move |p| reply(p.map(|p| Value::String(path_string(p))).unwrap_or(Value::Null)));
            } else if bool_of(c, "multiple").unwrap_or(false) {
                d.pick_files(move |ps| reply(ps.map(|ps| Value::Array(ps.into_iter().map(|p| Value::String(path_string(p))).collect())).unwrap_or(Value::Null)));
            } else {
                d.pick_file(move |p| reply(p.map(|p| Value::String(path_string(p))).unwrap_or(Value::Null)));
            }
            Ok(())
        }
        "notification.show" => {
            let mut n = handle.notification().builder().title(str_of(c, "title").unwrap_or(""));
            if let Some(body) = str_of(c, "body") {
                n = n.body(body);
            }
            n.show().map_err(|e| e.to_string())
        }
        "clipboard.write" => handle.clipboard().write_text(str_of(c, "text").unwrap_or("").to_string()).map_err(|e| e.to_string()),
        "clipboard.read" => {
            let text = handle.clipboard().read_text().map_err(|e| e.to_string())?;
            push_event(json!({"type": "result", "id": id, "result": text}));
            Ok(())
        }
        "menu.set" => {
            // window "*" (or none): the application's menu (macOS: the menu bar; elsewhere: every window's)
            let target = str_of(c, "window").unwrap_or("*");
            let prefix = format!("\u{1e}{target}\u{1f}");
            if let Ok(mut map) = menu_items().lock() {
                map.retain(|k, _| !k.starts_with(&prefix));
            }
            let items = c.get("menu").and_then(Value::as_array).cloned().unwrap_or_default();
            let built = build_menu_items(handle, &prefix, &items)?;
            let refs: Vec<&dyn IsMenuItem<Wry>> = built.iter().map(|k| k as &dyn IsMenuItem<Wry>).collect();
            let menu = Menu::with_items(handle, &refs).map_err(|e| e.to_string())?;
            if target == "*" || cfg!(target_os = "macos") {
                handle.set_menu(menu).map(|_| ()).map_err(|e| e.to_string())
            } else {
                window(handle, c)?.set_menu(menu).map(|_| ()).map_err(|e| e.to_string())
            }
        }
        "menu.remove" => {
            let target = str_of(c, "window").unwrap_or("*");
            if target == "*" || cfg!(target_os = "macos") {
                handle.remove_menu().map(|_| ()).map_err(|e| e.to_string())
            } else {
                window(handle, c)?.remove_menu().map(|_| ()).map_err(|e| e.to_string())
            }
        }
        "menu.set_item" => {
            let full = format!("\u{1e}{}\u{1f}{}", str_of(c, "window").unwrap_or("*"), str_of(c, "item").unwrap_or(""));
            let kind = menu_items().lock().ok().and_then(|m| m.get(&full).cloned()).ok_or("no such menu item")?;
            let s = |e: tauri::Error| e.to_string();
            if let Some(item) = kind.as_menuitem() {
                if let Some(v) = bool_of(c, "enabled") { item.set_enabled(v).map_err(s)?; }
                if let Some(v) = str_of(c, "text") { item.set_text(v).map_err(s)?; }
            } else if let Some(item) = kind.as_check_menuitem() {
                if let Some(v) = bool_of(c, "enabled") { item.set_enabled(v).map_err(s)?; }
                if let Some(v) = str_of(c, "text") { item.set_text(v).map_err(s)?; }
                if let Some(v) = bool_of(c, "checked") { item.set_checked(v).map_err(s)?; }
            }
            Ok(())
        }
        "tray.create" => {
            let tray_id = str_of(c, "tray").unwrap_or("main").to_string();
            let _ = handle.remove_tray_by_id(&tray_id);
            let mut b = TrayIconBuilder::with_id(&tray_id);
            match str_of(c, "icon").map(tauri::image::Image::from_path) {
                Some(Ok(img)) => b = b.icon(img),
                Some(Err(err)) => return Err(format!("tray icon: {err}")),
                None => {
                    if let Some(img) = handle.default_window_icon() {
                        b = b.icon(img.clone());
                    }
                }
            }
            if let Some(t) = str_of(c, "tooltip") {
                b = b.tooltip(t);
            }
            if let Some(t) = str_of(c, "title") {
                b = b.title(t);
            }
            if let Some(items) = c.get("menu").and_then(Value::as_array) {
                let menu = Menu::new(handle).map_err(|e| e.to_string())?;
                for item in items {
                    if item.get("separator").and_then(Value::as_bool).unwrap_or(false) {
                        menu.append(&PredefinedMenuItem::separator(handle).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
                    } else {
                        let item_id = str_of(item, "id").unwrap_or("");
                        let text = str_of(item, "text").unwrap_or(item_id);
                        let enabled = bool_of(item, "enabled").unwrap_or(true);
                        let entry = MenuItem::with_id(handle, format!("{tray_id}\u{1f}{item_id}"), text, enabled, None::<&str>).map_err(|e| e.to_string())?;
                        menu.append(&entry).map_err(|e| e.to_string())?;
                    }
                }
                b = b.menu(&menu).show_menu_on_left_click(bool_of(c, "menu_on_left_click").unwrap_or(true));
            }
            b.on_menu_event(|_app, event| {
                let raw = event.id().as_ref().to_string();
                if let Some((tray, item)) = raw.split_once('\u{1f}') {
                    push_event(json!({"type": "tray", "tray": tray, "event": "menu", "item": item}));
                }
            })
            .on_tray_icon_event(|tray, event| {
                if let TrayIconEvent::Click { button, button_state: MouseButtonState::Up, .. } = event {
                    let button = match button {
                        MouseButton::Left => "left",
                        MouseButton::Right => "right",
                        MouseButton::Middle => "middle",
                    };
                    push_event(json!({"type": "tray", "tray": tray.id().as_ref(), "event": "click", "button": button}));
                }
            })
            .build(handle)
            .map_err(|e| e.to_string())?;
            Ok(())
        }
        "tray.set_tooltip" => {
            let tray = handle.tray_by_id(str_of(c, "tray").unwrap_or("main")).ok_or("no such tray icon")?;
            tray.set_tooltip(str_of(c, "tooltip")).map_err(|e| e.to_string())
        }
        "tray.remove" => {
            handle.remove_tray_by_id(str_of(c, "tray").unwrap_or("main"));
            Ok(())
        }
        "shortcut.register" => {
            let accel = str_of(c, "accelerator").unwrap_or("").to_string();
            let shortcut: Shortcut = accel.parse().map_err(|e| format!("shortcut '{accel}': {e}"))?;
            handle.global_shortcut().register(shortcut).map_err(|e| format!("shortcut '{accel}': {e}"))?;
            if let Ok(mut map) = shortcuts().lock() {
                map.insert(shortcut.id(), accel);
            }
            Ok(())
        }
        "shortcut.unregister" => {
            let accel = str_of(c, "accelerator").unwrap_or("");
            let shortcut: Shortcut = accel.parse().map_err(|e| format!("shortcut '{accel}': {e}"))?;
            if let Ok(mut map) = shortcuts().lock() {
                map.remove(&shortcut.id());
            }
            handle.global_shortcut().unregister(shortcut).map_err(|e| e.to_string())
        }
        "shortcut.unregister_all" => {
            if let Ok(mut map) = shortcuts().lock() {
                map.clear();
            }
            handle.global_shortcut().unregister_all().map_err(|e| e.to_string())
        }
        "opener.url" => handle.opener().open_url(str_of(c, "url").unwrap_or(""), None::<&str>).map_err(|e| e.to_string()),
        "opener.path" => handle.opener().open_path(str_of(c, "path").unwrap_or(""), None::<&str>).map_err(|e| e.to_string()),
        "opener.reveal" => handle.opener().reveal_item_in_dir(str_of(c, "path").unwrap_or("")).map_err(|e| e.to_string()),
        // the page's Tauri events (listen() in JS), for pages using @tauri-apps/api
        "app.emit" => e(handle.emit(str_of(c, "name").unwrap_or(""), c.get("payload").cloned().unwrap_or(Value::Null))),
        "app.tick" => {
            let hz = f64_of(c, "hz").unwrap_or(60.0).clamp(1.0, 1000.0);
            TICK_US.store((1_000_000.0 / hz) as u64, Ordering::SeqCst);
            Ok(())
        }
        "app.exit" => {
            handle.exit(c.get("code").and_then(Value::as_i64).unwrap_or(0) as i32);
            Ok(())
        }
        _ => Err(format!("unknown command '{name}'")),
    }
}

fn window_event(label: &str, event: &WindowEvent) -> Option<Value> {
    Some(match event {
        WindowEvent::CloseRequested { .. } => json!({"type": "window", "window": label, "event": "close-requested"}),
        WindowEvent::Destroyed => json!({"type": "window", "window": label, "event": "destroyed"}),
        WindowEvent::Focused(on) => json!({"type": "window", "window": label, "event": if *on { "focus" } else { "blur" }}),
        WindowEvent::Resized(size) => json!({"type": "window", "window": label, "event": "resized", "width": size.width, "height": size.height}),
        WindowEvent::Moved(pos) => json!({"type": "window", "window": label, "event": "moved", "x": pos.x, "y": pos.y}),
        WindowEvent::DragDrop(tauri::DragDropEvent::Drop { paths, .. }) => json!({"type": "window", "window": label, "event": "drop",
            "paths": paths.iter().map(|p| p.to_string_lossy().into_owned()).collect::<Vec<_>>()}),
        _ => return None,
    })
}

fn config_of(json: &str) -> Value {
    serde_json::from_str(json).unwrap_or_else(|_| json!({}))
}

fn run(config: Value) -> Result<i32, String> {
    let mut context: tauri::Context<Wry> = tauri::generate_context!();
    let root = PathBuf::from(str_of(&config, "root").unwrap_or("."));
    if let Some(id) = str_of(&config, "identifier") {
        context.config_mut().identifier = id.to_string();
    }
    if let Some(n) = str_of(&config, "product_name") {
        context.config_mut().product_name = Some(n.to_string());
    }
    if let Some(v) = str_of(&config, "version") {
        context.config_mut().version = Some(v.to_string());
        context.package_info_mut().version = v.parse().unwrap_or_else(|_| context.package_info().version.clone());
    }
    let engine = str_of(&config, "engine_pages").map(PathBuf::from);
    context.set_assets(Box::new(FolderAssets { root, engine }));
    if let Some(hz) = f64_of(&config, "tick_hz") {
        TICK_US.store((1_000_000.0 / hz.clamp(1.0, 1000.0)) as u64, Ordering::SeqCst);
    }

    let app = tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_clipboard_manager::init())
        .plugin(
            tauri_plugin_global_shortcut::Builder::new()
                .with_handler(|_app, shortcut, event| {
                    let accel = shortcuts().lock().ok().and_then(|m| m.get(&shortcut.id()).cloned()).unwrap_or_default();
                    let state = if event.state() == ShortcutState::Pressed { "pressed" } else { "released" };
                    push_event(json!({"type": "shortcut", "accelerator": accel, "state": state}));
                })
                .build(),
        )
        .on_menu_event(|_app, event| on_menu(event.id().as_ref()))
        .register_uri_scheme_protocol("jumphpframe", |_ctx, request| frame_response(&request))
        .invoke_handler(tauri::generate_handler![jumphp_invoke, jumphp_emit])
        .setup(|app| {
            let _ = HANDLE.set(app.handle().clone());
            RUNNING.store(true, Ordering::SeqCst);
            push_event(json!({"type": "ready"}));
            // the frames: one every tick (coalesced with the ones events ask for)
            std::thread::spawn(|| {
                while RUNNING.load(Ordering::SeqCst) {
                    let tick = TICK_US.load(Ordering::SeqCst);
                    std::thread::sleep(Duration::from_micros(tick));
                    // at least a quarter of a tick (3 ms or more) idle since the last frame ended
                    let gap = (tick / 4).max(3_000);
                    let idle = now_us().saturating_sub(LAST_END_US.load(Ordering::SeqCst));
                    if idle < gap {
                        std::thread::sleep(Duration::from_micros(gap - idle));
                    }
                    request_frame();
                }
            });
            Ok(())
        })
        .build(context)
        .map_err(|e| e.to_string())?;

    let code = app.run_return(|_handle, event| match event {
        RunEvent::WindowEvent { label, event, .. } => {
            if let WindowEvent::CloseRequested { api, .. } = &event {
                // PHP decides (its default handler closes the window)
                api.prevent_close();
            }
            if let Some(ev) = window_event(&label, &event) {
                push_event(ev);
            }
        }
        RunEvent::ExitRequested { code, api, .. } => {
            if code.is_none() {
                // the last window is gone: PHP decides (its default handler quits)
                api.prevent_exit();
                push_event(json!({"type": "exit-requested"}));
            }
        }
        RunEvent::Resumed => push_event(json!({"type": "resumed"})),
        _ => {}
    });
    RUNNING.store(false, Ordering::SeqCst);
    Ok(code)
}

// --- the C API --------------------------------------------------------------------------------------------------

/// Runs the app until PHP asks to quit; returns its exit code (negative: it could not start, see stderr).
/// Once per process (the native event loop can be created once).
#[no_mangle]
pub extern "C" fn jt_run(config_json: *const c_char, frame: Option<FrameFn>) -> i32 {
    let Some(frame) = frame else { return -2 };
    if STARTED.swap(true, Ordering::SeqCst) {
        eprintln!("[jumphp-tauri] jt_run() can only be called once per process");
        return -3;
    }
    let config = if config_json.is_null() { json!({}) } else { config_of(&unsafe { CStr::from_ptr(config_json) }.to_string_lossy()) };
    let _ = FRAME.set(frame);
    #[cfg(windows)]
    {
        webview2::ensure();
        common_controls::pin_module();
        common_controls::activate();
    }
    match catch_unwind(AssertUnwindSafe(|| run(config))) {
        Ok(Ok(code)) => code,
        Ok(Err(e)) => {
            eprintln!("[jumphp-tauri] {e}");
            -1
        }
        Err(_) => {
            eprintln!("[jumphp-tauri] panic in the native runtime");
            RUNNING.store(false, Ordering::SeqCst);
            -1
        }
    }
}

/// The events since the last call, as a JSON array. The string stays valid until the next call.
#[no_mangle]
pub extern "C" fn jt_poll() -> *const c_char {
    let events: Vec<Value> = EVENTS.lock().map(|mut q| q.drain(..).collect()).unwrap_or_default();
    let text = CString::new(Value::Array(events).to_string()).unwrap_or_default();
    POLLED.with(|p| {
        *p.borrow_mut() = text;
        p.borrow().as_ptr()
    })
}

/// One command (JSON object with "cmd"), run on the main thread after the current frame.
#[no_mangle]
pub extern "C" fn jt_send(command_json: *const c_char) {
    if command_json.is_null() {
        return;
    }
    let text = unsafe { CStr::from_ptr(command_json) }.to_string_lossy();
    match serde_json::from_str::<Value>(&text) {
        Ok(command) => {
            if let Ok(mut q) = COMMANDS.lock() {
                q.push_back(command);
            }
            // sent outside a frame (from a thread callback...): run it soon
            if !IN_FRAME.with(|f| f.get()) {
                request_frame();
            }
        }
        Err(e) => push_event(json!({"type": "error", "cmd": "", "message": format!("bad command JSON: {e}")})),
    }
}

/// The embedded game's newest picture (a BMP): PHP calls it after each frame it draws.
#[no_mangle]
pub extern "C" fn jt_frame(data: *const u8, len: usize) {
    if data.is_null() {
        return;
    }
    let bytes = unsafe { std::slice::from_raw_parts(data, len) };
    if let Ok(mut d) = frame_data().lock() {
        d.clear();
        d.extend_from_slice(bytes);
    }
    FRAME_NO.fetch_add(1, Ordering::SeqCst);
}

#[no_mangle]
pub extern "C" fn jt_version() -> *const c_char {
    VERSION.as_ptr() as *const c_char
}

/// Windows: the WebView every window needs (WebView2, Edge's). Windows 11 and an updated Windows 10 have it, a fresh Windows 10 does
/// not: Microsoft's Evergreen bootstrapper (packed in this library, ~1.8 MB, redistribution allowed) installs it silently before the
/// first window, as the apps' installers expect (no page to open, no link to follow). It asks Windows' permission (UAC) itself.
#[cfg(windows)]
mod webview2 {
    use std::os::windows::process::CommandExt;
    use std::process::Command;

    const SETUP: &[u8] = include_bytes!("../assets/MicrosoftEdgeWebview2Setup.exe");
    const CLIENT: &str = "{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}";
    const NO_WINDOW: u32 = 0x0800_0000;

    /// The runtime registers its version ("pv") under EdgeUpdate's clients; 0.0.0.0 = removed.
    fn installed() -> bool {
        let keys = [
            format!(r"HKLM\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{CLIENT}"),
            format!(r"HKLM\SOFTWARE\Microsoft\EdgeUpdate\Clients\{CLIENT}"),
            format!(r"HKCU\Software\Microsoft\EdgeUpdate\Clients\{CLIENT}"),
        ];
        for key in keys {
            let Ok(out) = Command::new("reg").args(["query", &key, "/v", "pv"]).creation_flags(NO_WINDOW).output() else { continue };
            if !out.status.success() {
                continue;
            }
            let text = String::from_utf8_lossy(&out.stdout).to_string();
            let found = text.lines().any(|l| {
                let mut words = l.split_whitespace();
                words.next() == Some("pv") && words.last().map_or(false, |v| v != "0.0.0.0" && !v.is_empty())
            });
            if found {
                return true;
            }
        }
        false
    }

    pub fn ensure() {
        if installed() {
            return;
        }
        let path = std::env::temp_dir().join("MicrosoftEdgeWebview2Setup.exe");
        if std::fs::write(&path, SETUP).is_err() {
            return;
        }
        eprintln!("[jumphp-tauri] WebView2 is missing: installing it (silent)");
        // /silent /install: Microsoft's documented switches for the bootstrapper
        let _ = Command::new(&path).args(["/silent", "/install"]).creation_flags(NO_WINDOW).status();
        let _ = std::fs::remove_file(&path);
    }
}

/// Windows: the native dialogs (TaskDialogIndirect) live in version 6 of comctl32, which Windows only gives a
/// process whose executable asks for it in its manifest (php.exe does not). comctl32 is delay-loaded (build.rs)
/// and this activation context, active on the main thread for the whole run, makes it load version 6.
#[cfg(windows)]
mod common_controls {
    use std::ffi::c_void;
    use std::os::windows::ffi::OsStrExt;

    #[repr(C)]
    #[allow(non_snake_case)]
    struct ActCtxW {
        cbSize: u32,
        dwFlags: u32,
        lpSource: *const u16,
        wProcessorArchitecture: u16,
        wLangId: u16,
        lpAssemblyDirectory: *const u16,
        lpResourceName: *const u16,
        lpApplicationName: *const u16,
        hModule: *mut c_void,
    }

    #[link(name = "kernel32")]
    extern "system" {
        fn CreateActCtxW(actctx: *const ActCtxW) -> *mut c_void;
        fn ActivateActCtx(actctx: *mut c_void, cookie: *mut usize) -> i32;
        fn GetModuleHandleExW(flags: u32, module_name: *const u16, module: *mut *mut c_void) -> i32;
    }

    /// PHP frees the library at its shutdown while threads of the runtime (the ticker, WebView2's) may still run
    /// its code: pinned, it stays loaded until the process ends.
    pub fn pin_module() {
        const FROM_ADDRESS: u32 = 0x4;
        const PIN: u32 = 0x1;
        let mut module = std::ptr::null_mut();
        let anchor = pin_module as *const () as *const u16;
        if unsafe { GetModuleHandleExW(FROM_ADDRESS | PIN, anchor, &mut module) } == 0 {
            eprintln!("[jumphp-tauri] GetModuleHandleExW failed: the library may be unloaded too early");
        }
    }

    const MANIFEST: &str = r#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<assembly xmlns="urn:schemas-microsoft-com:asm.v1" manifestVersion="1.0">
  <dependency>
    <dependentAssembly>
      <assemblyIdentity type="win32" name="Microsoft.Windows.Common-Controls" version="6.0.0.0" processorArchitecture="*" publicKeyToken="6595b64144ccf1df" language="*"/>
    </dependentAssembly>
  </dependency>
</assembly>
"#;

    pub fn activate() {
        let path = std::env::temp_dir().join(format!("jumphp-tauri-common-controls-{}.manifest", std::process::id()));
        if std::fs::write(&path, MANIFEST).is_err() {
            eprintln!("[jumphp-tauri] cannot write {}: native dialogs may not work", path.display());
            return;
        }
        let wide: Vec<u16> = path.as_os_str().encode_wide().chain(std::iter::once(0)).collect();
        let ctx = ActCtxW {
            cbSize: std::mem::size_of::<ActCtxW>() as u32,
            dwFlags: 0,
            lpSource: wide.as_ptr(),
            wProcessorArchitecture: 0,
            wLangId: 0,
            lpAssemblyDirectory: std::ptr::null(),
            lpResourceName: std::ptr::null(),
            lpApplicationName: std::ptr::null(),
            hModule: std::ptr::null_mut(),
        };
        let handle = unsafe { CreateActCtxW(&ctx) };
        let _ = std::fs::remove_file(&path);
        if handle as isize == -1 {
            eprintln!("[jumphp-tauri] CreateActCtxW failed: native dialogs may not work");
            return;
        }
        let mut cookie = 0usize;
        if unsafe { ActivateActCtx(handle, &mut cookie) } == 0 {
            eprintln!("[jumphp-tauri] ActivateActCtx failed: native dialogs may not work");
        }
        // never released: it stays active on this thread until the process ends
    }
}
