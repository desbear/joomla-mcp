# 評估：官網內容問答嵌入非 Joomla 網站

> 狀態：**已實作（Ask the Site 0.11.0，2026-10-16）**；產品 token（8.1）的格式待與 BizForm 對齊，預設關閉。原評估 2026-10-15；第 7、8 節 2026-10-16 補充。結論：**可行**，建議「載入腳本＋iframe」的方式（類似 Intercom、LiveChat 的做法），約 3–4 天。

## 1. 現況：哪些部分已經可以搬出去

| 部分 | 現況 | 嵌入時 |
|---|---|---|
| 前端 | `ask.js`、`ask.css` 不依賴 Joomla 的前端程式；設定都從 HTML 的 `data-` 屬性讀取 | 可直接重用 |
| 提問 | `ask.stream` 用 SSE 串流；**不需要 Joomla 登入、session 或 CSRF token**，頻率限制依訪客位址 | 可直接重用 |
| 評分 | `ask.feedback` 以 HMAC 參照碼驗證，不需要 cookie | 可直接重用 |
| 外觀 | 0.7.3 的配色變數、主題 CSS | 依嵌入設定套用 |
| 內容 | 回答依 Joomla 站的索引；也可以用「外部網站」功能（RSS／Sitemap／爬取）索引對方網站 | 非 Joomla 網站也能「用自己的內容回答」 |

需要調整的部分：
- Joomla 的「HTTP 標頭」外掛預設只允許網站被自己框住（`frame-ancestors 'self'`、`X-Frame-Options`），嵌入頁要改成只允許設定的網域。
- 「目前所在頁面」的脈絡目前來自 Joomla 文章 ID；嵌入時改由載入腳本傳入對方頁面的標題與網址。
- 浮動按鈕的位置記憶（cookie）改存在對方網站的 localStorage（由載入腳本處理）。
- 點選來源後的換頁轉場：來源是 Joomla 站的頁面時開新分頁；來源是對方網站的頁面時，請對方頁面換頁。

## 2. 三種方式比較

| 方式 | 做法 | 優點 | 缺點 |
|---|---|---|---|
| A. 純 iframe | 對方貼一段 `<iframe>` | 最簡單 | 只能固定在一塊區域；浮動按鈕、全螢幕對話、拖曳都做不到 |
| B. 純 JS 注入 | 對方貼 `<script>`，直接把按鈕與對話畫在對方頁面 | 最自然 | 會受對方網站的 CSS 影響（需要 Shadow DOM 隔離）；要開放跨網域請求（CORS）；對方網站的程式可以讀到對話內容 |
| **C. 載入腳本＋iframe（建議）** | 對方貼一行 `<script>`；它在頁面角落放一個小 iframe 當浮動按鈕，打開對話時把 iframe 放大成全螢幕 | 樣式完全隔離；請求都在 Joomla 站自己的網域內（不需要 CORS）；對方網站讀不到對話內容；浮動、全螢幕、拖曳都能做 | 需要一個小的訊息協定（postMessage）處理放大、縮小、換頁 |

## 3. 建議做法（方式 C）

### 3.1 對方網站只要貼一行

```html
<script src="https://www.example.com/media/mod_mcp_ask/js/embed.js" data-key="pk_live_xxxx" async></script>
```

### 3.2 Joomla 端新增

1. **嵌入頁**：`index.php?option=com_mcpask&view=embed&key=…`，只輸出問答的按鈕與對話（不載入網站樣板），依嵌入設定決定模式、配色、語言。
2. **嵌入金鑰管理**（官網內容問答 → 嵌入）：每把金鑰設定
   - 允許嵌入的網域（決定 `frame-ancestors` 標頭；其他網域框住會被瀏覽器擋下）
   - 外觀：按鈕模式（浮動／圖示）、配色（網站配色或另選）、小寵物
   - 內容範圍：用哪些來源回答（Joomla 站、某些分類、某個外部網站）
   - 次數限制（每把金鑰每天）、啟用的 Agent
   - 使用統計
3. **載入腳本 `embed.js`**（約 3 KB）：建立 iframe、處理放大與縮小、記住按鈕位置、把對方頁面的標題與網址傳入、依指示換頁或開新分頁。
4. **postMessage 協定**：`open`、`close`、`resize`、`navigate`、`page-context`、`handoff`（轉接真人客服時，在對方頁面載入 LiveChat）；雙方都檢查來源網域。

### 3.3 Agent 與嵌入

- RSForm 評估、分析報告：在 iframe 裡照常運作，送出到 Joomla 站的 RSForm。
- LiveChat 轉接：由載入腳本在對方頁面載入 LiveChat 小工具（交接內容一樣帶入）。

## 4. 安全

- 金鑰是公開的（出現在對方網頁原始碼），真正的保護是「允許的網域」加上每把金鑰的次數限制。
- 對方網站無法讀取 iframe 內的對話；iframe 也不讀取對方網站的資料，只接收載入腳本傳入的頁面標題與網址。
- 嵌入頁不需要 Joomla 登入；後台設定一樣需要「選項」權限。

## 5. 估計

| 項目 | 估計 |
|---|---|
| 嵌入頁、`frame-ancestors` 標頭處理 | 0.5 天 |
| 嵌入金鑰管理頁（網域、外觀、內容範圍、限制、統計） | 1 天 |
| `embed.js` 與 postMessage 協定（放大縮小、位置記憶、換頁、頁面脈絡） | 1 天 |
| 在純 HTML 網站、WordPress 測試；文件與範例 | 0.5–1 天 |

之後可以再包成 WordPress 外掛（只是把那一行 `<script>` 加到頁面，加一個設定頁），約 0.5 天。

## 6. 建議的順序

先完成 Agent（RSForm 評估、分析報告、LiveChat 轉接），再做嵌入：嵌入是把整個問答（含 Agent）包出去，先完成內容，嵌入時一次測試完整功能。

## 7. 傳入頁面資訊（2026-10-16 補充）

iframe 讀不到對方頁面；由載入腳本以 postMessage 傳入，雙方都檢查來源網域。

- 自動：頁面標題、網址、語言、meta description。
- 由對方指定：`McpAsk.setContext({ product: 'Vital CRM', section: '客戶匯入', plan: '企業版' })`，告訴問答使用者正在哪個功能。
- 網址是已索引的頁面（例如 support 文章以 Peer 方式索引）時，直接對到該篇，效果同模組的「目前頁面」；否則把標題與節錄當參考資料（限制長度，經過與問題相同的檢查，視為資料而非指令）。

## 8. 使用者身分（2026-10-16 補充）

**不接受未簽章的 email**：載入腳本直接傳入的 email 任何人都能偽造，會被拿來冒名留言、填單、讓報告寄到別人信箱。兩種做法可並存：

### 8.1 A：產品後端簽發短效 token（產品內，免點選）

BizForm、CRM 等產品（.NET）後端本來就知道登入者（帳號中心）。產品以「嵌入金鑰的密鑰」簽一個 JWT（HS256），交給載入腳本：

```html
<script src="https://（官網網域）/media/mod_mcp_ask/js/embed.js" data-key="pk_live_xxxx" data-user="eyJhbGciOi…" async></script>
```

內容：`aud`（嵌入金鑰）、`email`、`name`、`iat`、`exp`（10 分鐘內）、`jti`（防重放）。gsscloud 驗證簽章與期限後，以 email 對到 gsscloud 帳號（帳號中心的帳號 gsscloud 都有）。找不到時當訪客處理，不自動建立帳號。密鑰只放在產品後端，可在嵌入管理頁更換。

C# 範例（不需額外套件；.NET Framework 4.x 可把 `JsonSerializer` 換成 Newtonsoft.Json，或改用 `System.IdentityModel.Tokens.Jwt`）：

```csharp
using System;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

public static class AskToken
{
    static string B64(byte[] bytes) => Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    public static string Create(string embedKey, string secret, string email, string name)
    {
        var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        var header = B64(JsonSerializer.SerializeToUtf8Bytes(new { alg = "HS256", typ = "JWT" }));
        var payload = B64(JsonSerializer.SerializeToUtf8Bytes(new
        {
            aud = embedKey, email, name, iat = now, exp = now + 600, jti = Guid.NewGuid().ToString("N")
        }));

        using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
        var signature = B64(hmac.ComputeHash(Encoding.ASCII.GetBytes(header + "." + payload)));

        return header + "." + payload + "." + signature;
    }
}
```

token 過期而頁面還開著時，載入腳本會向產品指定的網址（`data-user-refresh`，產品自己的端點，帶產品自己的登入 cookie）要一個新的。

### 8.2 B：「用帳號中心登入」小視窗（support 等沒有登入的網站）

iframe 內放登入按鈕，開小視窗走 gsscloud 既有的帳號中心 CAS 登入；已登入帳號中心時一閃即關。小視窗把短效 token 交回 iframe（只存在該分頁）。需要使用者點一下：Safari、Chrome 在 iframe 中封鎖第三方 cookie，無法在 iframe 內靜默檢查登入狀態。

support 用 B：問答服務與個資都在 gsscloud；support 只貼一行 `<script>`，不接 CAS、不存個資；support 文章以 Peer 方式讓 gsscloud 索引。

### 8.3 知道身分後

RSForm 填單帶入姓名與 Email、LiveChat 轉接帶入客戶資料、分析報告寄到驗證過的信箱、問答紀錄記到該會員、頻率限制依人計算、依會員開放內容。

### 8.4 帳號中心現況（2026-10-16）

- 官網以 External Login 的 CAS 登入外掛接帳號中心（CAS 協定 v3）：email 同時當帳號，名稱於每次登入同步，自動註冊、自動登出。
- 帳號中心另外提供 OIDC（authorization code＋PKCE、`prompt=none`、RS256 等簽章、`email`／`name` 等 claims）。
- 8.2 的小視窗兩種做法：
  1. **沿用 gsscloud 現有 CAS 登入（建議先試）**：小視窗開 gsscloud 的嵌入登入頁 → External Login 照常轉帳號中心 → 回到 gsscloud（第一方）已登入 → 該頁簽發短效 token、以 postMessage 交給開啟它的 iframe 後關閉。不需帳號中心改設定，只要帳號中心的服務登錄（service registry）允許這個 gsscloud 網址（gsscloud 現在的登入已在用，網域通常已允許）。
  2. OIDC（code＋PKCE）：需請帳號中心為嵌入登錄一個 OIDC client 與回呼網址；好處是不經過 gsscloud 的 Joomla 登入 session。
- 即使用 `prompt=none`，在 iframe 內靜默檢查仍會被第三方 cookie 限制擋下（帳號中心的登入 cookie 在 iframe 中是第三方 cookie），所以還是需要使用者點一下開小視窗。

### 8.5 確認結果（2026-10-16）

1. 帳號中心的服務登錄**允許** gsscloud 網域下的網址：8.4 做法 1（小視窗沿用 gsscloud 現有 CAS 登入）可以直接做，不需請帳號中心改設定。
2. 產品端：
   - **BizForm**：.NET Framework 與 .NET（Core）兩個版本都有，**已經有類似的換 token 機制**（BizForm 表單本身可嵌入其他產品並持續更新 token）。技術細節待問；8.1 的 token 格式可能改為對齊 BizForm 現有機制，而不是另訂。
   - **Vital CRM**：.NET Core，目前看起來沒有這種 token 機制，待問。在 CRM 加上之前，CRM 內的嵌入先用 8.2 的小視窗登入（點一下）。

### 8.6 待問

- BizForm 換 token 機制的格式（簽章方式、內容欄位、有效期、更新方式），以決定 gsscloud 端如何驗證。
- Vital CRM 是否可加 8.1 的簽 token 與更新端點。
