fn main() {
    // Windows: comctl32 is loaded at its first use, after the runtime activated version 6 of it (lib.rs,
    // common_controls): a DLL loaded by php.exe can not import TaskDialogIndirect directly.
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("windows") {
        println!("cargo:rustc-link-arg=/DELAYLOAD:comctl32.dll");
        println!("cargo:rustc-link-lib=delayimp");
    }
    tauri_build::build()
}
