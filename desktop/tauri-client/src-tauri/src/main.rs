#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::env;

const DEFAULT_REMOTE_URL: &str = "https://lyralinkai.com/chat";
const DEFAULT_LOCAL_HOST: &str = "127.0.0.1";
const DEFAULT_LOCAL_PORT: &str = "37991";

fn local_mode_enabled() -> bool {
    env::args().any(|arg| arg == "--local")
        || env::var("LYRALINK_MODE")
            .map(|v| v.eq_ignore_ascii_case("local"))
            .unwrap_or(false)
}

fn remote_app_url() -> String {
    env::var("LYRALINK_APP_URL").unwrap_or_else(|_| DEFAULT_REMOTE_URL.to_string())
}

fn local_app_url() -> String {
    let host = env::var("LYRALINK_LOCAL_HOST").unwrap_or_else(|_| DEFAULT_LOCAL_HOST.to_string());
    let port = env::var("LYRALINK_LOCAL_PORT").unwrap_or_else(|_| DEFAULT_LOCAL_PORT.to_string());
    format!("http://{}:{}/chat", host, port)
}

fn main() {
    let local_mode = local_mode_enabled();
    let app_url = if local_mode {
        local_app_url()
    } else {
        remote_app_url()
    };

    let context = tauri::generate_context!();

    tauri::Builder::default()
        .setup(move |app| {
            let main = app
                .get_window("main")
                .ok_or("missing main window from tauri.conf.json")?;

            let js_url = serde_json::to_string(&app_url)
                .map_err(|_| "failed to serialize app URL")?;
            main.eval(&format!("window.location.replace({});", js_url))?;

            Ok(())
        })
        .run(context)
        .expect("error while running tauri application");
}
