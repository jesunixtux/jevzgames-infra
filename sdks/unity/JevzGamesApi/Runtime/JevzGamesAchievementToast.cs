using System.Collections;
using UnityEngine;
using UnityEngine.Networking;
using UnityEngine.UI;

namespace JevzGames.Api
{
    public sealed class JevzGamesAchievementToast : MonoBehaviour
    {
        private const float VisibleSeconds = 4f;
        private static JevzGamesAchievementToast instance;

        private Canvas canvas;
        private RectTransform panel;
        private Image icon;
        private Text title;
        private Text description;
        private Text points;
        private Coroutine activeRoutine;

        public static void Show(string title, string description, string imageUrl, int points)
        {
            EnsureInstance().ShowInternal(title, description, imageUrl, points);
        }

        private static JevzGamesAchievementToast EnsureInstance()
        {
            if (instance != null)
            {
                return instance;
            }

            GameObject host = new GameObject("JevzGames Achievement Toast");
            DontDestroyOnLoad(host);
            instance = host.AddComponent<JevzGamesAchievementToast>();
            instance.BuildUi();
            return instance;
        }

        private void BuildUi()
        {
            canvas = gameObject.AddComponent<Canvas>();
            canvas.renderMode = RenderMode.ScreenSpaceOverlay;
            canvas.sortingOrder = 32760;
            gameObject.AddComponent<CanvasScaler>().uiScaleMode = CanvasScaler.ScaleMode.ScaleWithScreenSize;
            gameObject.AddComponent<GraphicRaycaster>();

            GameObject panelObject = new GameObject("Panel");
            panelObject.transform.SetParent(transform, false);
            panel = panelObject.AddComponent<RectTransform>();
            panel.anchorMin = new Vector2(0.5f, 0f);
            panel.anchorMax = new Vector2(0.5f, 0f);
            panel.pivot = new Vector2(0.5f, 0f);
            panel.anchoredPosition = new Vector2(0f, 34f);
            panel.sizeDelta = new Vector2(520f, 86f);
            Image background = panelObject.AddComponent<Image>();
            background.color = new Color(0.05f, 0.06f, 0.07f, 0.92f);

            GameObject iconObject = new GameObject("Icon");
            iconObject.transform.SetParent(panel, false);
            RectTransform iconRect = iconObject.AddComponent<RectTransform>();
            iconRect.anchorMin = new Vector2(0f, 0.5f);
            iconRect.anchorMax = new Vector2(0f, 0.5f);
            iconRect.pivot = new Vector2(0f, 0.5f);
            iconRect.anchoredPosition = new Vector2(14f, 0f);
            iconRect.sizeDelta = new Vector2(58f, 58f);
            icon = iconObject.AddComponent<Image>();
            icon.color = new Color(0.18f, 0.62f, 0.72f, 1f);

            title = CreateText("Title", new Vector2(84f, -18f), 18, FontStyle.Bold);
            description = CreateText("Description", new Vector2(84f, -43f), 14, FontStyle.Normal);
            points = CreateText("Points", new Vector2(420f, -31f), 14, FontStyle.Bold);
            points.alignment = TextAnchor.MiddleRight;

            panel.gameObject.SetActive(false);
        }

        private Text CreateText(string name, Vector2 anchoredPosition, int size, FontStyle style)
        {
            GameObject textObject = new GameObject(name);
            textObject.transform.SetParent(panel, false);
            RectTransform rect = textObject.AddComponent<RectTransform>();
            rect.anchorMin = new Vector2(0f, 1f);
            rect.anchorMax = new Vector2(0f, 1f);
            rect.pivot = new Vector2(0f, 1f);
            rect.anchoredPosition = anchoredPosition;
            rect.sizeDelta = name == "Points" ? new Vector2(84f, 28f) : new Vector2(320f, 24f);

            Text text = textObject.AddComponent<Text>();
            text.font = Resources.GetBuiltinResource<Font>("Arial.ttf");
            text.fontSize = size;
            text.fontStyle = style;
            text.color = Color.white;
            text.horizontalOverflow = HorizontalWrapMode.Wrap;
            text.verticalOverflow = VerticalWrapMode.Truncate;
            return text;
        }

        private void ShowInternal(string toastTitle, string toastDescription, string imageUrl, int pointValue)
        {
            if (activeRoutine != null)
            {
                StopCoroutine(activeRoutine);
            }

            title.text = string.IsNullOrWhiteSpace(toastTitle) ? "Achievement unlocked" : toastTitle;
            description.text = string.IsNullOrWhiteSpace(toastDescription) ? "" : toastDescription;
            points.text = pointValue > 0 ? "+" + pointValue + " pts" : "";
            icon.sprite = null;
            icon.color = new Color(0.18f, 0.62f, 0.72f, 1f);
            panel.gameObject.SetActive(true);
            activeRoutine = StartCoroutine(ToastRoutine(imageUrl));
        }

        private IEnumerator ToastRoutine(string imageUrl)
        {
            if (!string.IsNullOrWhiteSpace(imageUrl))
            {
                using (UnityWebRequest request = UnityWebRequestTexture.GetTexture(imageUrl))
                {
                    yield return request.SendWebRequest();
                    if (request.result == UnityWebRequest.Result.Success)
                    {
                        Texture2D texture = DownloadHandlerTexture.GetContent(request);
                        icon.sprite = Sprite.Create(texture, new Rect(0, 0, texture.width, texture.height), new Vector2(0.5f, 0.5f));
                        icon.color = Color.white;
                    }
                }
            }

            yield return new WaitForSecondsRealtime(VisibleSeconds);
            panel.gameObject.SetActive(false);
            activeRoutine = null;
        }
    }
}
