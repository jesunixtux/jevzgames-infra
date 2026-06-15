namespace JevzGames.CefClient;

internal static class LauncherHtml
{
    public const string Page = """
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JevzGames Client</title>
    <style>
        :root {
            --bg: #101416;
            --panel: #171e21;
            --panel-2: #202a2e;
            --text: #edf3f4;
            --muted: #a4b1b5;
            --border: #314045;
            --accent: #20a7ad;
            --danger: #ff7b72;
            --ok: #8fe3b0;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }
        header {
            height: 62px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 22px;
            border-bottom: 1px solid var(--border);
            background: #11191c;
        }
        .brand { font-weight: 800; font-size: 18px; }
        .shell {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            min-height: calc(100vh - 62px);
        }
        aside {
            border-right: 1px solid var(--border);
            background: #12191c;
            padding: 18px;
            display: grid;
            align-content: start;
            gap: 14px;
        }
        main { padding: 20px; }
        .panel {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel);
            padding: 16px;
        }
        .panel + .panel { margin-top: 14px; }
        .field { display: grid; gap: 6px; margin-bottom: 12px; }
        label { color: var(--muted); font-size: 13px; font-weight: 700; }
        input {
            min-height: 40px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--panel-2);
            color: var(--text);
            padding: 9px 11px;
        }
        button {
            min-height: 38px;
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #001517;
            padding: 0 14px;
            font-weight: 800;
            cursor: pointer;
        }
        button.secondary { background: var(--panel-2); color: var(--text); border: 1px solid var(--border); }
        button:disabled { opacity: .55; cursor: default; }
        h1, h2, h3 { margin: 0 0 10px; line-height: 1.2; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); }
        .tabs { display: grid; gap: 8px; }
        .tabs button { width: 100%; justify-content: flex-start; color: var(--text); }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 14px;
        }
        .card {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel);
            padding: 16px;
            display: grid;
            gap: 10px;
        }
        .row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .spacer { flex: 1; }
        .pill {
            border-radius: 999px;
            background: var(--panel-2);
            color: var(--muted);
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 800;
        }
        .log {
            min-height: 44px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #0c1113;
            color: var(--muted);
            padding: 12px;
            white-space: pre-wrap;
        }
        .hidden { display: none; }
        .error { color: var(--danger); }
        .ok { color: var(--ok); }
    </style>
</head>
<body>
    <header>
        <div class="brand">JevzGames Client</div>
        <div class="row">
            <span id="sessionState" class="muted">Sin sesion</span>
            <button class="secondary" onclick="logout()">Salir</button>
        </div>
    </header>
    <div class="shell">
        <aside>
            <section class="panel">
                <h2>Servidor</h2>
                <div class="field">
                    <label for="baseUrl">Base URL</label>
                    <input id="baseUrl" value="http://jevzgames.local">
                </div>
                <button onclick="loadConfig()">Conectar</button>
            </section>

            <section id="loginPanel" class="panel">
                <h2>Login</h2>
                <div class="field">
                    <label for="identity">Usuario o email</label>
                    <input id="identity" autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">Contrasena</label>
                    <input id="password" type="password" autocomplete="current-password">
                </div>
                <button onclick="login()">Entrar</button>
            </section>

            <nav class="tabs">
                <button class="secondary" onclick="showView('library')">Biblioteca</button>
                <button class="secondary" onclick="showView('store')">Catalogo</button>
                <button class="secondary" onclick="showView('inventory'); loadInventory()">Inventario</button>
                <button class="secondary" onclick="showView('redeem')">Canjear</button>
            </nav>

            <section class="panel">
                <h2>Local</h2>
                <p class="muted" id="installRoot"></p>
            </section>
        </aside>

        <main>
            <section class="panel">
                <h1 id="pageTitle">Biblioteca</h1>
                <p class="muted" id="clientConfig">Conecta al servidor local para cargar config.</p>
                <div id="log" class="log"></div>
            </section>

            <section id="library" class="view panel">
                <h2>Mis juegos</h2>
                <div id="libraryGrid" class="grid"></div>
            </section>

            <section id="store" class="view panel hidden">
                <h2>Catalogo</h2>
                <div id="storeGrid" class="grid"></div>
            </section>

            <section id="inventory" class="view panel hidden">
                <h2>Inventario</h2>
                <div id="inventoryGrid" class="grid"></div>
            </section>

            <section id="redeem" class="view panel hidden">
                <h2>Canjear codigo</h2>
                <div class="row">
                    <input id="redeemCode" placeholder="JVG-XXXX-XXXX">
                    <button onclick="redeem()">Canjear</button>
                </div>
            </section>
        </main>
    </div>

    <script>
        let lastLibrary = null;

        async function bind() {
            await CefSharp.BindObjectAsync("jevz");
            const state = JSON.parse(await jevz.getState());
            document.getElementById("baseUrl").value = state.baseUrl || "http://jevzgames.local";
            document.getElementById("installRoot").textContent = state.installRoot || "";
            setSession(state.hasToken);
            await loadConfig();
            if (state.hasToken) await loadLibrary();
        }

        function setSession(hasToken) {
            document.getElementById("sessionState").textContent = hasToken ? "Sesion activa" : "Sin sesion";
            document.getElementById("sessionState").className = hasToken ? "ok" : "muted";
        }

        function log(message, isError = false) {
            const box = document.getElementById("log");
            box.textContent = message || "";
            box.className = isError ? "log error" : "log";
        }

        function showView(id) {
            for (const view of document.querySelectorAll(".view")) view.classList.add("hidden");
            document.getElementById(id).classList.remove("hidden");
            document.getElementById("pageTitle").textContent = {
                library: "Biblioteca",
                store: "Catalogo",
                inventory: "Inventario",
                redeem: "Canjear"
            }[id] || id;
        }

        async function loadConfig() {
            const payload = JSON.parse(await jevz.config(document.getElementById("baseUrl").value));
            if (!payload.success) {
                log(payload.message, true);
                return;
            }
            document.getElementById("clientConfig").textContent = payload.data.enabled
                ? `${payload.data.name} activo · version minima ${payload.data.min_version}`
                : "El cliente esta deshabilitado en Superroot.";
            log(payload.data.enabled ? "Servidor conectado." : "Activa el cliente en Superroot > Funciones.", !payload.data.enabled);
        }

        async function login() {
            const payload = JSON.parse(await jevz.login(
                document.getElementById("baseUrl").value,
                document.getElementById("identity").value,
                document.getElementById("password").value
            ));
            if (!payload.success) {
                log(payload.message, true);
                return;
            }
            setSession(true);
            document.getElementById("password").value = "";
            log(`Sesion iniciada como ${payload.data.user.username}.`);
            await loadLibrary();
        }

        async function logout() {
            await jevz.logout();
            setSession(false);
            lastLibrary = null;
            renderGames("libraryGrid", []);
            renderGames("storeGrid", []);
            log("Sesion cerrada.");
        }

        async function loadLibrary() {
            const payload = JSON.parse(await jevz.library());
            if (!payload.success) {
                log(payload.message, true);
                return;
            }
            lastLibrary = payload.data;
            renderGames("libraryGrid", payload.data.linked_games || []);
            renderGames("storeGrid", payload.data.catalog || []);
            log("Biblioteca cargada.");
        }

        async function loadInventory() {
            const payload = JSON.parse(await jevz.inventory());
            if (!payload.success) {
                log(payload.message, true);
                return;
            }
            const grid = document.getElementById("inventoryGrid");
            grid.innerHTML = "";
            const items = payload.data.items || [];
            if (!items.length) {
                grid.innerHTML = `<p class="muted">Sin items.</p>`;
                return;
            }
            for (const item of items) {
                const card = document.createElement("article");
                card.className = "card";
                card.innerHTML = `<div class="row"><h3>${esc(item.name)}</h3><span class="spacer"></span><span class="pill">x${item.quantity}</span></div>
                    <p class="muted">${esc(item.game?.name || "Global")} · ${esc(item.item_key)}</p>
                    <p class="muted">${esc(item.item_type || "item")}</p>`;
                grid.appendChild(card);
            }
        }

        async function redeem() {
            const code = document.getElementById("redeemCode").value;
            const payload = JSON.parse(await jevz.redeem(code));
            if (!payload.success) {
                log(payload.message, true);
                return;
            }
            document.getElementById("redeemCode").value = "";
            log("Codigo canjeado.");
            await loadInventory();
            showView("inventory");
        }

        function renderGames(containerId, games) {
            const grid = document.getElementById(containerId);
            grid.innerHTML = "";
            if (!games.length) {
                grid.innerHTML = `<p class="muted">No hay juegos para mostrar.</p>`;
                return;
            }
            for (const game of games) {
                const build = game.install_build || null;
                const slug = game.slug || game.game_slug || "";
                const name = game.name || game.game_name || slug;
                const card = document.createElement("article");
                card.className = "card";
                const buildInfo = build
                    ? `<p class="muted">Build ${esc(build.version)} · ${esc(build.channel)}</p>`
                    : `<p class="muted">Sin build ZIP instalable.</p>`;
                card.innerHTML = `<div class="row"><h3>${esc(name)}</h3><span class="spacer"></span><span class="pill">${esc(game.status || "")}</span></div>
                    <p class="muted">${esc(slug)}</p>
                    ${buildInfo}
                    <div class="row actions"></div>`;
                const actions = card.querySelector(".actions");
                if (build && build.download_url && build.executable_path) {
                    const install = document.createElement("button");
                    install.textContent = "Instalar / actualizar";
                    install.onclick = () => installGame(slug, name, build);
                    actions.appendChild(install);

                    const launch = document.createElement("button");
                    launch.className = "secondary";
                    launch.textContent = "Jugar";
                    launch.onclick = () => launchGame(slug, build.executable_path);
                    actions.appendChild(launch);

                    const folder = document.createElement("button");
                    folder.className = "secondary";
                    folder.textContent = "Carpeta";
                    folder.onclick = () => jevz.openInstallFolder(slug);
                    actions.appendChild(folder);
                }
                grid.appendChild(card);
            }
        }

        async function installGame(slug, name, build) {
            log(`Descargando ${name}...`);
            const payload = JSON.parse(await jevz.installGame(
                slug,
                name,
                build.download_url || "",
                build.version || "0.0.0",
                build.executable_path || "",
                build.checksum || ""
            ));
            if (!payload.success) {
                log(payload.message, true);
                return;
            }
            log(`${name} instalado en ${payload.data.install_dir}`);
        }

        async function launchGame(slug, executablePath) {
            const payload = JSON.parse(await jevz.launchGame(slug, executablePath));
            if (!payload.success) {
                log(payload.message, true);
                return;
            }
            log("Juego ejecutado.");
        }

        function esc(value) {
            return String(value ?? "").replace(/[&<>"']/g, ch => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\"": "&quot;",
                "'": "&#039;"
            }[ch]));
        }

        bind();
    </script>
</body>
</html>
""";
}
