using UnityEngine;

namespace JevzGames.Api
{
    public sealed class JevzGamesLauncherBridge : MonoBehaviour
    {
        [SerializeField] private string fallbackBaseUrl = "http://jevzgames.local";
        [SerializeField] private string fallbackGameSlug = "";
        [SerializeField] private bool createClientIfMissing = true;

        public JevzGamesApiClient Client { get; private set; }

        private void Awake()
        {
            Client = JevzGamesApiClient.Instance;
            if (Client == null && createClientIfMissing)
            {
                GameObject clientObject = new GameObject("JevzGames API Client");
                Client = clientObject.AddComponent<JevzGamesApiClient>();
            }

            if (Client != null)
            {
                Client.Configure(fallbackBaseUrl, "", fallbackGameSlug);
                Client.ApplyLauncherContext();
            }
        }

        public void UnlockAchievement(string achievementCode)
        {
            if (Client != null)
            {
                Client.UnlockAchievement(achievementCode);
            }
        }
    }
}
