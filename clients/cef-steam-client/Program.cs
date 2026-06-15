using System.Diagnostics;
using System.IO.Compression;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using CefSharp;
using CefSharp.WinForms;

namespace JevzGames.CefClient;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();

        var settings = new CefSettings
        {
            CachePath = Path.Combine(ClientPaths.Root, "cef-cache"),
            LogSeverity = LogSeverity.Warning,
        };
        Cef.Initialize(settings, performDependencyCheck: true, browserProcessHandler: null);

        Application.Run(new LauncherForm());
        Cef.Shutdown();
    }
}

internal sealed class LauncherForm : Form
{
    private readonly ChromiumWebBrowser _browser;

    public LauncherForm()
    {
        Text = "JevzGames Client";
        Width = 1180;
        Height = 760;
        MinimumSize = new Size(980, 620);

        _browser = new ChromiumWebBrowser("about:blank")
        {
            Dock = DockStyle.Fill,
        };
        _browser.JavascriptObjectRepository.Settings.LegacyBindingEnabled = false;
        _browser.JavascriptObjectRepository.Register("jevz", new LauncherBridge(), isAsync: true, options: BindingOptions.DefaultBinder);
        Controls.Add(_browser);

        Load += (_, _) => _browser.LoadHtml(LauncherHtml.Page, "http://jevzgames-client.local/");
    }
}

public sealed class LauncherBridge
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = false,
    };

    private readonly HttpClient _http = new();
    private ClientState _state;

    public LauncherBridge()
    {
        Directory.CreateDirectory(ClientPaths.Root);
        Directory.CreateDirectory(ClientPaths.Games);
        Directory.CreateDirectory(ClientPaths.Downloads);
        _state = ClientState.Load();
    }

    public string GetState()
    {
        return ToJson(new
        {
            ok = true,
            baseUrl = _state.BaseUrl,
            hasToken = !string.IsNullOrWhiteSpace(_state.ClientToken),
            installRoot = ClientPaths.Games,
        });
    }

    public async Task<string> Config(string baseUrl)
    {
        try
        {
            _state.BaseUrl = NormalizeBaseUrl(baseUrl);
            _state.Save();
            using var response = await _http.GetAsync(Url("/api/client/config/"));
            return await ResponseJson(response);
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    public async Task<string> Login(string baseUrl, string identity, string password)
    {
        try
        {
            _state.BaseUrl = NormalizeBaseUrl(baseUrl);
            var payload = new
            {
                identity,
                password,
                client_name = "JevzGames CEF Local",
            };
            using var response = await PostJson("/api/client/login/", payload, token: null);
            var json = await ResponseJson(response);
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.TryGetProperty("success", out var success) && success.GetBoolean())
            {
                var token = document.RootElement.GetProperty("data").GetProperty("client_token").GetString();
                _state.ClientToken = token ?? "";
                _state.Save();
            }

            return json;
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    public async Task<string> Logout()
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(_state.ClientToken))
            {
                using var response = await PostJson("/api/client/logout/", new { }, _state.ClientToken);
                await ResponseJson(response);
            }

            _state.ClientToken = "";
            _state.Save();
            return ToJson(new { success = true, message = "OK", data = new { } });
        }
        catch (Exception exception)
        {
            _state.ClientToken = "";
            _state.Save();
            return Error(exception.Message);
        }
    }

    public async Task<string> Library()
    {
        try
        {
            RequireToken();
            using var response = await PostJson("/api/client/library/", new { }, _state.ClientToken);
            return await ResponseJson(response);
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    public async Task<string> Inventory()
    {
        try
        {
            RequireToken();
            using var response = await PostJson("/api/client/inventory/", new { }, _state.ClientToken);
            return await ResponseJson(response);
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    public async Task<string> Redeem(string code)
    {
        try
        {
            RequireToken();
            using var response = await PostJson("/api/client/redeem/", new { code }, _state.ClientToken);
            return await ResponseJson(response);
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    public async Task<string> InstallGame(string gameSlug, string gameName, string downloadUrl, string version, string executablePath, string checksum)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(gameSlug) || string.IsNullOrWhiteSpace(downloadUrl) || string.IsNullOrWhiteSpace(executablePath))
            {
                throw new InvalidOperationException("La build no tiene datos instalables.");
            }

            var installDir = GameInstallDir(gameSlug);
            var archivePath = Path.Combine(ClientPaths.Downloads, SafeName(gameSlug) + "-" + SafeName(version) + ".zip");
            Directory.CreateDirectory(installDir);
            Directory.CreateDirectory(ClientPaths.Downloads);

            using (var response = await _http.GetAsync(AbsoluteUrl(downloadUrl), HttpCompletionOption.ResponseHeadersRead))
            {
                response.EnsureSuccessStatusCode();
                await using var remote = await response.Content.ReadAsStreamAsync();
                await using var local = File.Create(archivePath);
                await remote.CopyToAsync(local);
            }

            if (!string.IsNullOrWhiteSpace(checksum))
            {
                var actual = Sha256(archivePath);
                if (!string.Equals(actual, checksum, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException("Checksum SHA-256 invalido. La descarga no coincide.");
                }
            }

            SafeExtractZip(archivePath, installDir);
            var executable = ResolveExecutable(installDir, executablePath);
            var manifest = new InstalledGame(gameSlug, gameName, version, installDir, executablePath, DateTimeOffset.UtcNow);
            manifest.Save();

            return ToJson(new
            {
                success = true,
                message = "installed",
                data = new
                {
                    game_slug = gameSlug,
                    install_dir = installDir,
                    executable,
                    version,
                },
            });
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    public string LaunchGame(string gameSlug, string executablePath)
    {
        try
        {
            var installDir = GameInstallDir(gameSlug);
            var executable = ResolveExecutable(installDir, executablePath);
            var startInfo = new ProcessStartInfo
            {
                FileName = executable,
                WorkingDirectory = Path.GetDirectoryName(executable) ?? installDir,
                UseShellExecute = true,
            };
            Process.Start(startInfo);

            return ToJson(new { success = true, message = "launched", data = new { executable } });
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    public string IsInstalled(string gameSlug, string executablePath)
    {
        try
        {
            var installDir = GameInstallDir(gameSlug);
            var executable = ResolveExecutable(installDir, executablePath);
            var manifest = InstalledGame.Load(gameSlug);
            return ToJson(new
            {
                success = true,
                message = "OK",
                data = new
                {
                    installed = File.Exists(executable),
                    install_dir = installDir,
                    version = manifest?.Version,
                },
            });
        }
        catch
        {
            return ToJson(new { success = true, message = "OK", data = new { installed = false } });
        }
    }

    public string OpenInstallFolder(string gameSlug)
    {
        try
        {
            var installDir = GameInstallDir(gameSlug);
            Directory.CreateDirectory(installDir);
            Process.Start(new ProcessStartInfo
            {
                FileName = installDir,
                UseShellExecute = true,
            });
            return ToJson(new { success = true, message = "OK", data = new { install_dir = installDir } });
        }
        catch (Exception exception)
        {
            return Error(exception.Message);
        }
    }

    private async Task<HttpResponseMessage> PostJson(string path, object payload, string? token)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, Url(path))
        {
            Content = new StringContent(ToJson(payload), Encoding.UTF8, "application/json"),
        };
        if (!string.IsNullOrWhiteSpace(token))
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }

        return await _http.SendAsync(request);
    }

    private static async Task<string> ResponseJson(HttpResponseMessage response)
    {
        var body = await response.Content.ReadAsStringAsync();
        if (string.IsNullOrWhiteSpace(body))
        {
            return Error("Respuesta vacia del servidor.");
        }

        return body;
    }

    private Uri Url(string path)
    {
        return new Uri(_state.BaseUrl.TrimEnd('/') + "/" + path.TrimStart('/'));
    }

    private Uri AbsoluteUrl(string value)
    {
        if (Uri.TryCreate(value, UriKind.Absolute, out var uri))
        {
            return uri;
        }

        return Url(value);
    }

    private static string NormalizeBaseUrl(string baseUrl)
    {
        baseUrl = string.IsNullOrWhiteSpace(baseUrl) ? "http://jevzgames.local" : baseUrl.Trim();
        if (!baseUrl.StartsWith("http://", StringComparison.OrdinalIgnoreCase) && !baseUrl.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
        {
            baseUrl = "http://" + baseUrl;
        }

        return baseUrl.TrimEnd('/');
    }

    private void RequireToken()
    {
        if (string.IsNullOrWhiteSpace(_state.ClientToken))
        {
            throw new InvalidOperationException("Inicia sesion primero.");
        }
    }

    private static string GameInstallDir(string gameSlug)
    {
        return Path.Combine(ClientPaths.Games, SafeName(gameSlug));
    }

    private static string ResolveExecutable(string installDir, string executablePath)
    {
        executablePath = executablePath.Replace('\\', Path.DirectorySeparatorChar).Replace('/', Path.DirectorySeparatorChar);
        if (Path.IsPathRooted(executablePath) || executablePath.Contains(".."))
        {
            throw new InvalidOperationException("Ruta de ejecutable invalida.");
        }

        var fullPath = Path.GetFullPath(Path.Combine(installDir, executablePath));
        var root = Path.GetFullPath(installDir);
        if (!fullPath.StartsWith(root, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException("Ruta de ejecutable fuera de la instalacion.");
        }

        if (!File.Exists(fullPath))
        {
            throw new FileNotFoundException("No se encontro el ejecutable instalado.", fullPath);
        }

        return fullPath;
    }

    private static void SafeExtractZip(string archivePath, string destination)
    {
        using var archive = ZipFile.OpenRead(archivePath);
        var root = Path.GetFullPath(destination);
        foreach (var entry in archive.Entries)
        {
            var target = Path.GetFullPath(Path.Combine(destination, entry.FullName));
            if (!target.StartsWith(root, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException("El zip intenta escribir fuera de la carpeta de instalacion.");
            }

            if (entry.FullName.EndsWith("/", StringComparison.Ordinal) || entry.FullName.EndsWith("\\", StringComparison.Ordinal))
            {
                Directory.CreateDirectory(target);
                continue;
            }

            Directory.CreateDirectory(Path.GetDirectoryName(target) ?? destination);
            entry.ExtractToFile(target, overwrite: true);
        }
    }

    private static string Sha256(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    }

    private static string SafeName(string value)
    {
        var builder = new StringBuilder();
        foreach (var c in value.ToLowerInvariant())
        {
            builder.Append(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' ? c : '-');
        }

        var result = builder.ToString().Trim('-');
        return string.IsNullOrWhiteSpace(result) ? "game" : result;
    }

    private static string ToJson(object value)
    {
        return JsonSerializer.Serialize(value, JsonOptions);
    }

    private static string Error(string message)
    {
        return ToJson(new { success = false, message, data = new { } });
    }
}

internal static class ClientPaths
{
    public static readonly string Root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "JevzGamesClient");
    public static readonly string Games = Path.Combine(Root, "games");
    public static readonly string Downloads = Path.Combine(Root, "downloads");
    public static readonly string StateFile = Path.Combine(Root, "client-state.json");
}

internal sealed class ClientState
{
    public string BaseUrl { get; set; } = "http://jevzgames.local";
    public string ClientToken { get; set; } = "";

    public static ClientState Load()
    {
        try
        {
            if (File.Exists(ClientPaths.StateFile))
            {
                var state = JsonSerializer.Deserialize<ClientState>(File.ReadAllText(ClientPaths.StateFile));
                if (state is not null)
                {
                    return state;
                }
            }
        }
        catch
        {
        }

        return new ClientState();
    }

    public void Save()
    {
        Directory.CreateDirectory(ClientPaths.Root);
        File.WriteAllText(ClientPaths.StateFile, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
    }
}

internal sealed record InstalledGame(string Slug, string Name, string Version, string InstallDir, string ExecutablePath, DateTimeOffset InstalledAt)
{
    public void Save()
    {
        File.WriteAllText(ManifestPath(Slug), JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
    }

    public static InstalledGame? Load(string slug)
    {
        var path = ManifestPath(slug);
        if (!File.Exists(path))
        {
            return null;
        }

        return JsonSerializer.Deserialize<InstalledGame>(File.ReadAllText(path));
    }

    private static string ManifestPath(string slug)
    {
        var directory = Path.Combine(ClientPaths.Games, slug);
        Directory.CreateDirectory(directory);
        return Path.Combine(directory, ".jevzgames-install.json");
    }
}
