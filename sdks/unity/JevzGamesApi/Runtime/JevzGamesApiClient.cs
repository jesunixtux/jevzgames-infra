using System;
using System.Collections;
using System.Text;
using UnityEngine;
using UnityEngine.Networking;

namespace JevzGames.Api
{
    public sealed class JevzGamesApiClient : MonoBehaviour
    {
        public static JevzGamesApiClient Instance { get; private set; }

        [Header("JevzGames API")]
        [SerializeField] private string baseUrl = "http://jevzgames.local";
        [SerializeField] private string clientToken = "";
        [SerializeField] private string gameSlug = "";

        [Header("Launcher")]
        [SerializeField] private bool readLauncherEnvironment = true;
        [SerializeField] private bool keepPresenceInGame = true;
        [SerializeField] private bool showAchievementToasts = true;

        public string BaseUrl => baseUrl;
        public string ClientToken => clientToken;
        public string GameSlug => gameSlug;
        public bool IsConfigured => !string.IsNullOrWhiteSpace(baseUrl)
                                    && !string.IsNullOrWhiteSpace(clientToken)
                                    && !string.IsNullOrWhiteSpace(gameSlug);

        private void Awake()
        {
            if (Instance != null && Instance != this)
            {
                Destroy(gameObject);
                return;
            }

            Instance = this;
            DontDestroyOnLoad(gameObject);

            if (readLauncherEnvironment)
            {
                ApplyLauncherContext();
            }
        }

        private void Start()
        {
            if (keepPresenceInGame && IsConfigured)
            {
                SetPresence("in_game");
            }
        }

        private void OnApplicationQuit()
        {
            if (keepPresenceInGame && !string.IsNullOrWhiteSpace(clientToken))
            {
                SetPresence("offline");
            }
        }

        public void Configure(string apiBaseUrl, string bearerToken, string slug)
        {
            if (!string.IsNullOrWhiteSpace(apiBaseUrl))
            {
                baseUrl = apiBaseUrl.TrimEnd('/');
            }

            if (!string.IsNullOrWhiteSpace(bearerToken))
            {
                clientToken = bearerToken;
            }

            if (!string.IsNullOrWhiteSpace(slug))
            {
                gameSlug = slug;
            }
        }

        public void ApplyLauncherContext()
        {
            Configure(
                FirstNonEmpty(Environment.GetEnvironmentVariable("JEVZGAMES_API_BASE"), ArgValue("--jevzgames-api=")),
                FirstNonEmpty(Environment.GetEnvironmentVariable("JEVZGAMES_CLIENT_TOKEN"), ArgValue("--jevzgames-token=")),
                FirstNonEmpty(Environment.GetEnvironmentVariable("JEVZGAMES_GAME_SLUG"), ArgValue("--jevzgames-game="))
            );
        }

        public Coroutine ListAchievements(Action<bool, string, AchievementsData> callback)
        {
            return StartCoroutine(PostJson(
                "/api/client/achievements/list/",
                "{\"game_slug\":\"" + JsonEscape(gameSlug) + "\"}",
                (success, message, json) =>
                {
                    AchievementsEnvelope envelope = FromJson<AchievementsEnvelope>(json);
                    callback?.Invoke(success && envelope != null && envelope.success, messageFrom(envelope, message), envelope?.data);
                }
            ));
        }

        public Coroutine UnlockAchievement(string achievementCode, Action<bool, string, UnlockAchievementData> callback = null)
        {
            string payload = "{\"game_slug\":\"" + JsonEscape(gameSlug) + "\",\"achievement_code\":\"" + JsonEscape(achievementCode) + "\"}";
            return StartCoroutine(PostJson(
                "/api/client/achievements/unlock/",
                payload,
                (success, message, json) =>
                {
                    UnlockAchievementEnvelope envelope = FromJson<UnlockAchievementEnvelope>(json);
                    bool ok = success && envelope != null && envelope.success;
                    UnlockAchievementData data = envelope?.data;
                    if (ok && showAchievementToasts && data != null && data.just_unlocked && data.toast != null && data.toast.enabled)
                    {
                        JevzGamesAchievementToast.Show(data.toast.title, data.toast.description, data.toast.image_url, data.toast.points);
                    }

                    callback?.Invoke(ok, messageFrom(envelope, message), data);
                }
            ));
        }

        public Coroutine SetPresence(string status, Action<bool, string, PresenceData> callback = null)
        {
            string payload = "{\"status\":\"" + JsonEscape(status) + "\",\"game_slug\":\"" + JsonEscape(gameSlug) + "\"}";
            return StartCoroutine(PostJson(
                "/api/client/presence/",
                payload,
                (success, message, json) =>
                {
                    PresenceEnvelope envelope = FromJson<PresenceEnvelope>(json);
                    callback?.Invoke(success && envelope != null && envelope.success, messageFrom(envelope, message), envelope?.data);
                }
            ));
        }

        private IEnumerator PostJson(string path, string json, Action<bool, string, string> callback)
        {
            if (!IsConfigured && path != "/api/client/presence/")
            {
                callback?.Invoke(false, "JevzGamesApiClient is not configured.", "");
                yield break;
            }

            string url = baseUrl.TrimEnd('/') + path;
            byte[] body = Encoding.UTF8.GetBytes(json);
            using (UnityWebRequest request = new UnityWebRequest(url, UnityWebRequest.kHttpVerbPOST))
            {
                request.uploadHandler = new UploadHandlerRaw(body);
                request.downloadHandler = new DownloadHandlerBuffer();
                request.SetRequestHeader("Content-Type", "application/json");
                request.SetRequestHeader("Authorization", "Bearer " + clientToken);

                yield return request.SendWebRequest();

                bool success = request.result == UnityWebRequest.Result.Success;
                string text = request.downloadHandler != null ? request.downloadHandler.text : "";
                string message = success ? "OK" : request.error;
                ApiEnvelope envelope = FromJson<ApiEnvelope>(text);
                if (envelope != null && !string.IsNullOrWhiteSpace(envelope.message))
                {
                    message = envelope.message;
                }

                callback?.Invoke(success, message, text);
            }
        }

        private static T FromJson<T>(string json) where T : class
        {
            if (string.IsNullOrWhiteSpace(json))
            {
                return null;
            }

            try
            {
                return JsonUtility.FromJson<T>(json);
            }
            catch
            {
                return null;
            }
        }

        private static string messageFrom(ApiEnvelope envelope, string fallback)
        {
            return envelope != null && !string.IsNullOrWhiteSpace(envelope.message) ? envelope.message : fallback;
        }

        private static string FirstNonEmpty(string first, string second)
        {
            return !string.IsNullOrWhiteSpace(first) ? first : second;
        }

        private static string ArgValue(string prefix)
        {
            string[] args = Environment.GetCommandLineArgs();
            foreach (string arg in args)
            {
                if (arg.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                {
                    return arg.Substring(prefix.Length);
                }
            }

            return "";
        }

        private static string JsonEscape(string value)
        {
            return (value ?? "")
                .Replace("\\", "\\\\")
                .Replace("\"", "\\\"")
                .Replace("\n", "\\n")
                .Replace("\r", "\\r");
        }
    }

    [Serializable]
    public class ApiEnvelope
    {
        public bool success;
        public string message;
    }

    [Serializable]
    public sealed class AchievementsEnvelope : ApiEnvelope
    {
        public AchievementsData data;
    }

    [Serializable]
    public sealed class UnlockAchievementEnvelope : ApiEnvelope
    {
        public UnlockAchievementData data;
    }

    [Serializable]
    public sealed class PresenceEnvelope : ApiEnvelope
    {
        public PresenceData data;
    }

    [Serializable]
    public sealed class AchievementsData
    {
        public GameSummary game;
        public AchievementInfo[] achievements;
    }

    [Serializable]
    public sealed class UnlockAchievementData
    {
        public GameSummary game;
        public AchievementInfo achievement;
        public bool just_unlocked;
        public AchievementToastData toast;
    }

    [Serializable]
    public sealed class PresenceData
    {
        public PresenceInfo presence;
    }

    [Serializable]
    public sealed class GameSummary
    {
        public int id;
        public string name;
        public string slug;
        public string status;
        public string current_version;
    }

    [Serializable]
    public sealed class AchievementInfo
    {
        public int id;
        public string title;
        public string description;
        public string image_path;
        public string image_url;
        public string locked_image_path;
        public string locked_image_url;
        public int points;
        public float goal_value;
        public float progress_value;
        public float progress_percent;
        public bool unlocked;
        public string unlocked_at;
    }

    [Serializable]
    public sealed class AchievementToastData
    {
        public bool enabled;
        public string position;
        public string title;
        public string description;
        public string image_url;
        public int points;
    }

    [Serializable]
    public sealed class PresenceInfo
    {
        public string status;
        public bool connected;
        public int game_id;
        public string game_slug;
        public string game_name;
        public string last_seen_at;
        public string source;
    }
}
