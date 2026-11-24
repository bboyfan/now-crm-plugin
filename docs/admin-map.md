# NOW CRM — 後台畫面、路由與動作對照

這份文件給「要改後台畫面的人」看，目的是不用每次重新翻 `Menu.php`、`Actions.php` 與
`Campaigns.php`。領域模型與寄送佇列請搭配 `docs/architecture.md`；設計基準見
`docs/design-system.md`（以 Contacts 列表與詳情為實作基準）；測試環境請看 `docs/testing.md`。
後續功能的參考、設計切片與手動驗收流程，見 [CRM 功能設計工作指南](crm-ux-playbook.md)。

技術邊界：Contacts／Lists／Tags 用 PHP 原生 admin 頁；Campaign 編輯器用 TypeScript＋
Gutenberg 原生套件；Abandoned Cart 用輕量 TypeScript 編譯成一般 JS；REST 的權限、驗證與
持久化一律由 PHP 負責。

## 1. 選單與畫面

固定順序由 `Admin\Menu::order_submenu()` 控制；所有子頁都掛在 top-level
`nowcrm-dashboard` 下。

| 選單 | URL | Renderer | Capability | 現況 | 目標 | 切片 |
|---|---|---|---|---|---|---|
| 總覽 | `admin.php?page=nowcrm-dashboard` | `DashboardPage` | `nowcrm_manage_contacts` | 聯絡人、佇列與購物車快照；新增聯絡人趨勢、近期聯絡人／電子報、啟用中的自動化與快速入口 | 有建立權限才顯示新增電子報；不在此執行 worker | 6 |
| 報表 | `admin.php?page=nowcrm-reports` | `ReportsPage` | `nowcrm_manage_contacts` | 聯絡人／電子報／自動化／訂單營收／購物車；日期與比較共用控制項，電子報保留單封明細及 CSV | 保留 `campaign_id` 深層連結，不改資料歸因 | 6 |
| 聯絡人 | `admin.php?page=nowcrm` | `ContactsPage` | `nowcrm_manage_contacts` | 聯絡人分頁（所有聯絡人／名單／標籤／分眾）；工具列「更多操作」（匯出符合篩選／選取、重設表格偏好）、「匯入」、「新增聯絡人」；新增抽屜（稱謂、姓名、Email、電話、生日、可收合地址、名單／標籤、自訂欄位、狀態）；緊湊篩選列：搜尋姓名／Email／電話／`#ID`（Enter 送出）、狀態即時套用、名單與標籤多選 popover（同類 OR、跨類 AND）、進階篩選預設關閉（內嵌套用／清除／儲存為分眾）；表格預設 10 筆，頁尾筆數 10／20／50／100／150，聯絡人欄為本機首字母＋姓名＋次要 Email，名單與標籤分欄 chips，狀態含圓點；檢視選項（精簡、訂單數／累計／建立時間／ID 欄位，存 localStorage）；選取後才顯示底部批次列（全選本頁、選取全部符合、只選本頁、依操作顯示欄位）；匯出頁以 `scope`／`contact_ids` 預填 | 維持列表為主要操作面；名單／標籤與批次契約不變 | 5 |
| 聯絡人詳情 | `admin.php?page=nowcrm-contact&id=<id>` | `ContactDetailPage` | `nowcrm_manage_contacts` | 聯絡人標頭、上一筆／下一筆、狀態、名單與標籤；`tab=overview\|emails\|purchases\|notes`。概覽行內編輯基本資料、地址與自訂欄位；Email 頁有篩選、唯讀預覽及一次性寄送；購買頁保留 Woo 商品明細及已計入的訂單統計；筆記頁含狀態歷程 | 保留既有資料與稽核契約 | 3 |
| 公司 | 已從產品 UI 移除 | — | — | 公司資料表與既有 automation／CSV 設定保留，不再有專屬 CRUD 畫面 | — | — |
| 名單 | `admin.php?page=nowcrm-lists` | `TermsPage::render_lists` | `nowcrm_manage_contacts` | 預設最新 10 筆；搜尋、排序、分頁、新增／編輯對話框、逐筆或選取後刪除、更多操作內的 CSV 匯入／匯出。已訂閱數與總人數連到各自的聯絡人結果 | — | 4 |
| 標籤 | `admin.php?page=nowcrm-tags` | `TermsPage::render_tags` | `nowcrm_manage_contacts` | 與名單共用表格及新增、編輯、刪除、CSV 流程，只處理標籤資料 | — | 4 |
| 分眾 | `admin.php?page=nowcrm-segments`、`&segment=custom:<id>` | `SegmentsPage` | `nowcrm_manage_contacts` | 在聯絡人分區搜尋、依類型與狀態篩選；建立、編輯及預覽各有入口。預覽顯示規則與樣本成員 | 系統分眾不可編輯；只有同一受眾的條件才連到聯絡人 | 6 |
| 表單 | `admin.php?page=nowcrm-forms`、`&form=<id>` | `FormsPage`／`FormEditorPage` | `nowcrm_manage_contacts` | 名稱與短碼、狀態、欄位與送出概況在列表；建立、表單設定和送出紀錄各有入口 | 使用 NOW 原生表單，不依賴 Fluent Forms | 6 |
| 電子報 | `admin.php?page=nowcrm-campaigns` | `CampaignListPage` | `nowcrm_manage_contacts` ＋物件權限 | 標題下顯示受眾、定期資訊和列操作；狀態旁顯示排程，保留收件人與開信／點擊率。草稿、寄送結束及已取消的電子報可勾選移到回收桶 | 四步工作區不變 | 2 |
| 定期寄送 | `admin.php?page=nowcrm-recurring`、`&campaign=<id>`、`&campaign=<id>&edit=content`、`&campaign=<id>&tab=settings` | `RecurringListPage`／`CampaignPage`（`RecurringPage` 仍提供 admin-post 動作與列表排程輸入） | `nowcrm_manage_contacts` ＋物件權限 | 列表只含定期家長：名稱（排程摘要）、頻率、下次產生、已產生數、狀態、更新時間；搜尋、狀態 counts、內嵌 `new=1` 建立草稿、選取後批次刪除。詳情與內容網址都渲染同一個工作區，只有三個分頁：郵件內容／設定／寄送紀錄；沒有上一步／下一步。郵件內容在主旨與預覽文字下方內嵌 Gutenberg 或 Raw HTML；設定含排程、收件人、其他設定與操作；寄送紀錄列出最近 20 筆子電子報。 | 定期家長與子電子報不再出現在「電子報」列表 | 2 |
| 電子報建立／編輯 | `post-new.php?post_type=nowcrm_campaign`、`post.php?post=<id>&action=edit` | 舊入口導回 `CampaignPage` 工作區 | `edit_post` | Campaign 正文在「內容」步驟內嵌 Gutenberg（同源 iframe） | 四步流程不變 | 2 |
| 自動化 | `admin.php?page=nowcrm-automations` | `AutomationListPage` | `nowcrm_manage_contacts` | 搜尋、狀態篩選及分頁；執行數字連到執行紀錄。勾選後可改為草稿／啟用／暫停或刪除。Email 步驟抽屜沿用同一個 Gutenberg 畫布（patterns、design tokens、Smartcodes），區塊內容隨抽屜 lazy mount，套用範本／預覽／測試信沿用共用工具 | 批次走原有狀態驗證 | B |
| 序列 | `admin.php?page=nowcrm-sequences` | `SequenceListPage` | `nowcrm_manage_contacts` | 搜尋、狀態篩選及分頁；可勾選刪除，保留個別序列的啟用、暫停、複製、加入受眾及序列追蹤。Email 步驟沿用同一個 Gutenberg 畫布（patterns、tokens、Smartcodes） | 刪除沿用既有追蹤與排程清理 | C |
| 電子報範本 | `admin.php?page=nowcrm-templates`、`&template=<id>` | `TemplateListPage`／`TemplatePage` | `nowcrm_manage_contacts` ＋物件權限 | 列表可勾選刪除或另選全部符合篩選的項目。範本頁直接編輯 Gutenberg 或 Raw HTML，可預覽尚未儲存的內容及寄送測試；Gutenberg 使用同一組 patterns、design tokens 與 Smartcodes | 套用複製已發佈範本，不連動來源 | A |
| 智慧連結 | `admin.php?page=nowcrm-smart-links`、`&edit=<id>` | `SmartLinksPage` | `nowcrm_manage_contacts` | 搜尋、分頁、目的地、狀態、名單／標籤動作與點擊數；新增和編輯按需開啟 | 顯示使用網址，不顯示或觸發已簽章連結 | 6 |
| 購物車放棄 | `admin.php?page=nowcrm-cart`、`&cart=<id>`、`&tab=settings` | `CartPage` | `nowcrm_manage_contacts`；設定需 `nowcrm_manage_settings` | 狀態、Email 篩選與分頁；單筆顯示商品快照、活動、訂單歸因、來源自動化執行及寄送紀錄；設定頁管理捕捉、時程、owner，並連到 owner 自動化編輯 Email | 提醒信內容只在自動化 Email 步驟；設定頁不再有第二個 HTML 編輯器；owner 失效只顯示原因、不改回預設；不顯示復原權杖，不提供手動寄信 | 6 |
| 寄送紀錄 | `admin.php?page=nowcrm-email`、`&job=<id>`、`&tab=diagnostics` | `EmailLogPage` | `nowcrm_manage_contacts`；診斷需 `nowcrm_manage_settings` | 列表和詳情分開顯示佇列與供應商送達；診斷顯示 worker 上次成功／下次排程與 SNS 回條彙總；抑制摘要獨立分頁 | 診斷唯讀、不顯示 SNS 原始資料；手動 worker 獨立提交；已有回條的未明 Job 不重排 | 6 |
| 設定 | `admin.php?page=nowcrm-settings`、`&tab=email`、`&tab=fields`、`&tab=system` | `SettingsPage`／`CustomFields` | `nowcrm_manage_settings` | 一般與 Woo、寄送與 SES、自訂欄位、系統狀態分頁；寄送與 SES 管理 transport、Configuration Set 與 Topic ARN | 每個表單只寫自己的設定範圍；寄送驗收連往寄送診斷 | 6 |
| 同意與偏好 | `admin.php?page=nowcrm-consent` | `ConsentSettings` | `nowcrm_manage_settings` | 雙重確認、偏好中心名單、個別名單覆寫與合規限制分卡；尚未設定時不公開任何名單 | 儲存不觸發寄送；各來源套用同意政策；名單可見範圍不會改寫退訂或抑制 | 6 |
| Webhooks | `admin.php?page=nowcrm-webhooks`、`&webhook=<id>&view=detail` | `WebhooksPage` | `nowcrm_manage_settings` | 端點、傳送紀錄、入站分頁；新增與編輯各自開啟，端點詳情列該端點的傳送紀錄 | 簽章密鑰不放在列表；既有 `webhook=<id>` 編輯網址保留 | 6 |
| API 金鑰 | `admin.php?page=nowcrm-api-keys`、`&view=new` | `ApiKeysPage` | 既有設定與 WordPress 權限 | 先列現有金鑰，按需建立；新值只顯示一次 | 沿用 WordPress Application Passwords 與既有撤銷權限 | 6 |
| 工具 | `admin.php?page=nowcrm-tools`、`&tab=diagnostics`、`&tab=woo` | `ToolsPage` | `nowcrm_manage_settings` | 診斷分頁為預設（SNS 端點、版本、資料表）；Woo 分頁先呈現通知 owner 與已知衝突，再提供確認後的切換。FluentCRM 遷移只在 WP-CLI；舊 `tab=migration` 網址回到診斷 | CSV 沿用聯絡人匯入；Woo 對帳／歷史回填、資料保留與 FluentCRM 遷移留在 CLI | 6 |

五個工作流程列表共用 `WorkflowCatalog` 的標頭、狀態筆數、搜尋、分頁（預設 10 筆）和選取列。
全選本頁只勾可操作的列；沒有勾選時不顯示批次操作。五個列表各保留自己的計數及寫入契約。

- 自動化列表可搜尋、依狀態篩選、分頁和批次操作；「新增自動化」開右側抽屜選觸發事件。
  單筆啟用／暫停／刪除返回原篩選，複製成功則開啟新草稿。
- 自動化編輯是 `nowcrm-automation&id=<id>`；`nowcrm-automation-runs&id=<id>` 可依狀態及
  聯絡人查找執行、展開逐步事件並檢視 A/B 分支結果。取消不撤回已排入佇列的信。
  可重試已確認失敗的步驟；動作結果不明時保留事件供查核，不重做該動作。
- 序列編輯是 `nowcrm-sequence&id=<id>`；`nowcrm-sequence-runs&id=<id>` 顯示逐步寄送統計。
  兩頁共用 880px 表單工作區（`assets/css/form-workspace.css`，`Menu::enqueue_assets()` 只在
  `nowcrm-sequence`／`nowcrm-sequence-runs`／`nowcrm-recurring` 載入）：標頭為標題＋`·` 分隔的
  中繼行（步驟數／受眾／狀態，追蹤頁為序列名稱／受眾／狀態）＋右側次要按鈕，內容為
  `nowcrm-panel` 卡片，序列編輯器底部是貼齊視窗的儲存列；追蹤頁統計以 chips 呈現。
  延遲仍存 `delay_seconds`，新佇列以步驟 UUID 歸戶；舊佇列只按歷史位置另列。
  序列開信／點擊追蹤預設關閉，開啟後只追蹤新入列且寄送時仍啟用的項目；測試信不計。

### 自動化詳情工作區

`nowcrm-automation&id=<id>` 由 `AutomationEditorPage` 輸出；專用樣式由
`Menu::enqueue_assets()` 依頁面載入。主區是觸發摘要與步驟，右側是自動化設定與執行概況。

- 標頭提供狀態與執行紀錄、暫停或啟用、更多操作及儲存。`[data-save-state]` 提示是否有未儲存變更；
  離開有未儲存變更的頁面時會提醒。
- 觸發卡顯示觸發名稱及說明。按「編輯觸發」聚焦觸發事件；切換事件會立即顯示適用的篩選，
  不適用的欄位不送出。留空的適用篩選仍表示不限制。
- 步驟卡：`[data-action="edit"]` 開原生右側抽屜編輯；上移／下移／移除保留在卡片。
  抽屜移入**同一個** `.nowcrm-step-card__body` 節點，不重建欄位；關閉只保留頁面草稿，
  仍須按頁首「儲存」才會寫入。無 JavaScript 時仍可送出基本欄位。
- 根層與各分支共用分類步驟選擇器。新增動作放在該列表的第一個「結束流程」前，
  避免新增後到不了；既有順序不會自動改寫。根層入口是 `[data-testid="automation-add-step"]`。
- 等待與目標逾時：畫面用 `data-field="delay_value"`＋`delay_unit`（分／小時／天／秒）與
  `goal_timeout_value`／`goal_timeout_unit`，儲存仍只讀隱藏的 `delay_seconds`／
  `goal_timeout`；伺服器用最大整除單位初始化（3661 秒維持秒）。`goal_mode=optional` 時
  逾時欄位停用。
- 按「儲存」或 `Ctrl`／`Cmd`+`S` 送出流程與設定，不改伺服器上的啟用狀態。「啟用」才送
  `save_status=active`，同一次儲存目前的編輯並啟用。暫停／轉草稿／複製／刪除仍走各自的
  表單，不會順便儲存未提交的編輯。
- 刻意與 FluentCRM 不同：沒有 sticky note／自動化筆記（NOW 沒有 notes 欄位，本切片不加
  migration）、沒有每次編輯即時存檔（避免重排 ordinal 影響既有執行）、沒有 Pro A/B 報告與
  自動化標籤；A/B 只看既有執行紀錄的分支結果。

### 隱藏頁與選單行為

- `nowcrm-contact` 是隱藏頁：以空 parent 註冊 `admin_page_nowcrm-contact`，再
  `remove_submenu_page( '', Menu::CONTACT_SLUG )`。
- `Menu::prepare_contact_detail()` 在 `admin_init` 設定 `$GLOBALS['title']`，避免 WP 7.1
  的 `strip_tags( null )` deprecation。
- `Menu::filter_parent_file()` 讓 hidden page 與電子報相關畫面（`post.php`／`post-new.php`／
  CPT 列表）的 parent 保持 `nowcrm-dashboard`；`Menu::filter_submenu_file()` 讓「聯絡人」與
  「電子報」高亮。
- 電子報自製頁（`admin.php?page=nowcrm-campaigns`）由 `CampaignListPage` 負責；CPT 產生的
  `edit.php?post_type=nowcrm_campaign` 子選單在 `order_submenu()` 內移除，選單只留自製頁。
- 定期寄送只有一個可見頁 `nowcrm-recurring`：列表、`campaign=<id>` 詳情與
  `campaign=<id>&edit=content` 內容工作區都由 `RecurringListPage::render()` 分派，沒有隱藏子頁。
  兩種網址都渲染同一個 `CampaignPage` 工作區（`#nowcrm-campaign-workspace`），
  詳情不再使用 `form-workspace.css`；`CampaignPage::enqueue()` 以定期列表 `RecurringPage::list_url()`
  作為返回網址並帶入 `recurring: true`。列表維持 catalog 樣式，只有列表載入 `form-workspace.css`。
- 「電子報」列表與總覽的最近電子報查詢都加入 `Recurring::exclude_meta_query()`；因此帶
  `_nowcrm_recurring_status` 的定期家長與帶 `_nowcrm_recurring_parent` 的子電子報只出現在
  「定期寄送」。批次電子報刪除契約不變。
- 電子報編輯器（`post.php`／`post-new.php`）由 `filter_submenu_file()` 映射到
  `nowcrm-campaigns`，所以側欄仍標在「電子報」。
- 聯絡人／名單／標籤在 WP 側欄維持直接連結；`nowcrm-lists`／`nowcrm-tags`
  以 NOW 專屬 CSS 內縮，呈現 Contacts 群組。分眾是隱藏路由（空 parent 註冊後移除），
  只從 CRM 內的四個 tabs 進入，高亮仍留在「聯絡人」。
- WordPress 側欄把定期寄送、序列、範本、智慧連結、寄送紀錄列在「電子報」下方並內縮；
  「自動化」維持獨立入口。舊 URL 不變；自動化編輯及紀錄頁高亮「自動化」，
  範本內容編輯器高亮「電子報範本」。
- `nowcrm-sequence`／`nowcrm-sequence-runs` 是隱藏頁：空 parent 註冊後移除空 parent 選單；
  `prepare_contact_detail()` 設定標題，`filter_parent_file()`／`filter_submenu_file()` 讓側欄留在「序列」。

### 營運與管理入口

- **總覽／報表**：總覽回答「現在有什麼」，用實際數字連往工作列表；報表回答「這段期間發生什麼」，保留日期、比較與歸因口徑，不把工作佇列當成行銷成效。
- **聯絡人／表單**：分眾仍在聯絡人分區，因為它是受眾規則，不是另一份聯絡人資料；表單是站上收集入口，保留獨立選單。
- **電子報**：智慧連結與寄送紀錄跟定期寄送、序列、範本放同一組。Fluent 把 Smart Link 放設定；NOW 放在使用這些連結的郵件工具旁，也讓只有聯絡人權限的使用者找得到。
- **購物車放棄**：入口先看購物車與結果，不把店家組態擋在列表前。設定頁只管理捕捉與時程；提醒信內容跟 FluentCRM 一樣留在 owner 自動化的 Email 步驟，設定頁只提供連結，報表只統計。
- **設定／工具**：設定是長期政策（寄送、Woo、自訂欄位、同意與偏好、Webhooks、API 金鑰）；工具是管理員明確觸發的資料表修復與 Woo 切換。FluentCRM 遷移只在 WP-CLI。權限與既有網址不變。

對照版本為本機 FluentCRM Core + Pro 3.3.1。`AdminMenu.php` 的 Contacts／Emails／Forms／Reports
分組與 `Settings.js` 的設定分區用於核對入口；寄送紀錄參考 Emails → All Activities，不將 Settings
的 System Logs 誤當成收件者紀錄。Woo 購物車對照 Core `AbandonCartController`／`SettingsController`
及 Pro Woo driver，NOW 仍只顯示自己能證實的提醒與訂單歸因。

不等同完整 Fluent 平台：已移除的公司 UI 不復原；不搬入第三方表單編輯器、獨立 Email Patterns
庫、其他電商／訊息渠道、AI／MCP、授權管理或一鍵清空資料庫。WP roles、NOW capabilities、Woo
訂單和 NOW 寄送與抑制規則仍是各自的資料來源；不複製 Fluent 的 UI、資產、API 或資料格式。

### Woo 訂單頁聯絡人面板

- `OrderContactPanel` 在 `add_meta_boxes`（優先序 99）掛 side／low 面板；畫面同時接受
  HPOS（`woocommerce_page_wc-orders`、`admin_page_wc-orders`）與舊版 `shop_order`，
  `$object` 為 `WC_Order` 或 `WP_Post` 都能解析。
- 顯示條件依序為：NOW 聯絡人權限 → `nowcrm_order_contact_panel_allowed` filter →
  訂單類型是 `shop_order` → 可編輯該訂單 → 能解析到聯絡人。任一不符就不註冊 metabox。
- 解析順序是**已存的 `order_links` 關聯 → 帳單 Email 精確比對**。關聯指向已刪除的聯絡人時
  直接隱藏，不以同 Email 的新聯絡人回填；Email 比對命中時面板標示「尚未建立 NOW 關聯」。
- 面板只讀：不建立聯絡人、不改 `wp_user_id`、不重算訂單。內容為身份、狀態、計入訂單數／
  累計消費／最近訂單、本單行銷同意、名單與標籤（各最多 3 個，其餘 `+N`）、抑制狀態，
  以及連到聯絡人頁「訂單」分頁的連結。
- 樣式只在訂單編輯畫面載入：`admin.css` ＋ `order-panel.css`，由 `Menu::enqueue_assets()`
  判斷畫面後載入，其他 NOW 頁面不受影響。

### 總覽與報表操作

- 總覽預設最近 30 天，可切換 7／30／90 天；「在聯絡人報表查看」保留明確日期。
  聯絡人／佇列／購物車的數字是目前快照，不隨趨勢期間改變，也不會觸發寄送或重試。
- 最近電子報表格顯示該封電子報的累計數；從該列開啟報表時，報表預設近 30 天，請按需要調整期間。
- 報表一次只載入目前分頁：聯絡人、電子報、自動化、訂單與營收，或購物車。日期以站台時區
  解讀，資料庫使用半開 UTC 範圍，最多 366 天。切換主題保留日期及比較，不帶不適用的篩選。
  「上一個月／季／年」取本期起日所在日曆期的前一個完整期間，不會與本期重疊；相鄰等長比較則使用前一段期間。
- 聯絡人成長依 `created_at`，名單、標籤篩選使用目前的成員關係，不推測過去的名單組成。
  狀態與分佈快照獨立於日期。電子報以交付與事件各自的時間統計；已交付不等於送達，
  開信可能是預取。電子報 CSV 匯出本期、目前篩選的完整結果，不只匯出目前頁。
- 自動化報表的執行依保存中的 `started_at`，結果是現在的狀態；重新進入可能重用同一筆執行，
  不是完整進入歷史。訂單僅使用已計入的 `order_links`，金額依幣別分組，不推算電子報營收。
  購物車以目前仍存在且曾在期間內放棄的購物車為群組。郵件建立訂單與已付款成交分列；金額只加總 Woo 標記已付款且計入營收的訂單淨額，不因重複放棄而多計。
- 手動驗收：從總覽切換 7 天並開啟報表；切換主題後確認日期仍在。套用自訂比較並展開每日數值；
  由電子報表打開單封、回到同期間、核對 CSV，再以窄視窗確認長表格可橫捲。

## 2. Admin-post 動作

所有變更都要求 capability＋nonce；`Actions::register()` 只在 `is_admin()` 註冊。

| Action | Handler | 成功後導向 |
|---|---|---|
| `nowcrm_save_settings` | `Actions::save_settings` | 依驗證過的設定範圍回一般、郵件或購物車設定 |
| `nowcrm_create_contact` | `Actions::create_contact` | 聯絡人詳情；`create_mode=another` 回聯絡人列表並重開抽屜；驗證失敗回列表並保留草稿 |
| `nowcrm_save_contact` | `Actions::save_contact`（`Actions::persist_contact()`） | 概覽；只儲存檔案欄位與自訂值 |
| `nowcrm_change_contact_status` | `Actions::change_contact_status` | 目前聯絡人分頁；寫入狀態歷程，不寄信 |
| `nowcrm_change_contact_term` | `Actions::change_contact_term` | 目前聯絡人分頁；只新增或移除指定關聯 |
| `nowcrm_save_custom_fields` | `Admin\CustomFields::handle_save_settings` | 回設定的自訂欄位分頁；成功或驗證錯誤都留在該處 |
| `nowcrm_delete_contact` | `Actions::delete_contact` | 聯絡人列表 |
| `nowcrm_save_contact_note` | `ContactNotes::save_note` | 聯絡人詳情的筆記面板 |
| `nowcrm_delete_contact_note` | `ContactNotes::delete_note` | 聯絡人詳情的筆記面板 |
| `nowcrm_bulk_delete_contact_notes` | `ContactNotes::bulk_delete_notes` | 聯絡人詳情的筆記面板 |
| `nowcrm_send_contact_email` | `ContactEmailPanel::send_one_off` | 聯絡人詳情的 Email 歷程面板（`nowcrm_email_notice=queued` 或錯誤碼） |
| `nowcrm_create_term` | `Actions::create_term` | 名單或標籤頁（依 type） |
| `nowcrm_update_term` | `Actions::update_term` | 名單或標籤頁（依 type） |
| `nowcrm_delete_term` | `Actions::delete_term` | 名單或標籤頁（依原 type） |
| `nowcrm_delete_terms` | `Actions::delete_terms` | 名單或標籤頁（依 type；批次，最多 200 筆） |
| `nowcrm_import_terms` | `Actions::import_terms` | 名單或標籤頁（依 type；CSV 最多 500 列；相同 slug 更新名稱，保留說明與關聯） |
| `nowcrm_export_terms` | `TermsPage::export` | GET 下載目前型別的 CSV；保留搜尋範圍，nonce 依型別驗證 |
| `nowcrm_retry_job` | `Actions::retry_job` | 回原列表或單筆詳情，保留列表篩選和頁碼；只重新排入符合狀態與類型的項目 |
| `nowcrm_run_queue` | `Actions::run_queue` | 寄送紀錄 → 佇列診斷；需設定權限，仍受寄送總開關限制 |
| `nowcrm_repair_schema` | `Actions::repair_schema` | 回工具的診斷分頁；不刪資料 |
| `nowcrm_campaign_action` | `Campaigns::handle_action` | 依 campaign action |
| `nowcrm_create_campaign` | `CampaignListPage::handle_create` | 原生編輯器（`post.php`）；缺標題／失敗回到 `new=1` |
| `nowcrm_campaign_mode` | `Campaigns::handle_mode_switch` | 原編輯器 |
| `nowcrm_toggle_woo_processing` | `Woo\ProcessingHandoff::toggle` | 回工具的 Woo 交接分頁 |
| `nowcrm_workflow_email_apply`／`_preview`／`_test` | `WorkflowEmail` | 回傳 JSON；套用及預覽不寫資料。測試只把內容快照排入 `test`，收件人固定為目前登入者。本機只接受 `capture` transport |
| `nowcrm_bulk_campaigns`／`nowcrm_bulk_automations`／`nowcrm_bulk_sequences`／`nowcrm_bulk_delete_templates` | `WorkflowCatalogActions`（範本由 `TemplateListPage` 呼叫） | 保留搜尋、狀態、分頁，顯示實際處理及略過筆數；全選符合條件僅適用於範本，每次最多 100 筆 |
| `nowcrm_create_recurring` | `RecurringListPage::handle_create` | 建立草稿並存未啟用排程，導向定期詳情（`nowcrm_notice=recurring_created`）；驗證失敗回 `new=1` 並以 per-user transient 保留輸入 |
| `nowcrm_bulk_recurring` | `RecurringListPage::handle_bulk` | 只處理定期家長；有子電子報在排程／寄送／暫停中時整筆略過。成功後保留搜尋、狀態、分頁並呼叫 `Recurring::sync()` |
| `nowcrm_save_recurring`／`nowcrm_recurring_status`／`nowcrm_generate_recurring`／`nowcrm_duplicate_recurring`／`nowcrm_delete_recurring` | `RecurringPage` | 只作用於定期家長；能力＋nonce＋物件權限。啟用重用 `Recurring::save`，缺主旨或正文時不改狀態。刪除先檢查子電子報狀態，再刪子電子報與家長，最後 `Recurring::sync()` |
| `nowcrm_cancel_automation_run`／`nowcrm_retry_automation_run` | `AutomationWorkflowActions` | 回同一個自動化的執行紀錄、保留有效篩選；只作用於該自動化下與操作相符的單筆狀態 |

`Actions::render_notice()` 只顯示 allow-list 的 `nowcrm_notice` code；新增流程請走同一套，
不要直接輸出 `$_GET`。

### 自訂欄位、筆記與購買明細

- 定義：`CustomFieldDefinitions` 存 `nowcrm_contact_fields`（有序）與
  `nowcrm_contact_field_groups`；型別為 `text`、`textarea`、`number`、`select-one`、
  `select-multi`、`radio`、`checkbox`、`date`、`date_time`。設定頁送出完整清單：
  `fields[i][order|label|type|group|group_new|options|delete]` 與
  `groups[i][order|title|delete]`；數字排序、勾選刪除、底部空白列新增、選項每行一個。
- 儲存：`CustomFields::handle_save_settings` 先驗證欄位與群組，再寫兩個 option；沒有欄位
  引用的群組會移除。`slug` 建立後固定，改名稱或群組不動既有值。值存在
  `contact_field_values`，`date` 為 `Y-m-d`，`date_time` 為本地 `Y-m-d H:i:s`，多選為 JSON 陣列。
- 聯絡人表單：每欄一個 `nowcrm_cf_present[]` 隱藏標記＋`nowcrm_cf[slug]`（多選加 `[]`）。
  `Actions::persist_contact()` 先驗證自訂值，再寫基本資料及自訂值；沒有標記時不更動自訂值。
  概覽表單不更動狀態或名單／標籤關聯；這些動作各有獨立表單。刪除聯絡人時一併刪除自訂值。
- 筆記：`ContactNotes` 面板（`NoteRepository`）以標題旁「新增筆記」開啟右側抽屜；編輯沿用同一表單。
  支援搜尋、型別篩選（筆記／通話／Email／會議／報價／發票／交易／回饋／社群）、日期時間、
  逐筆與批次刪除；notice 用 `nowcrm_note_notice`／`nowcrm_note_deleted`。
- Email 歷程：`ContactEmailPanel`（`ContactHistory`）以 `contact_id`（或無 `contact_id` 但
  收件者等於目前 Email）界定範圍，列出電子報／購物車／測試／一次性 Job；篩選用
  `email_status`／`email_kind`、分頁用 `email_paged`、唯讀預覽用 `email_preview`。預覽只
  還原凍結快照，不注入開信像素、不建立退訂權杖。一次性寄送建立 idempotent `custom` Job
  （`request_id`＝表單 UUID），寄送時佇列仍要求 `subscribed` 並檢查抑制名單；未開啟寄送時
  只累積佇列。不提供刪除：Job 是稽核紀錄，事件與退訂權杖都指向它。
- 購買明細：`PurchaseHistory::enrich()` 從實際 Woo 訂單取商品名稱、選項、數量與行金額；
  訂單金額口徑用 `order_links.net_total`／`currency`，只有 `is_counted` 進訂單數與累計。

## 3. Campaign／電子報契約

- CPT：`Campaigns::POST_TYPE = nowcrm_campaign`；`show_in_menu = nowcrm-dashboard`，但
  `_add_post_type_submenus` 產生的子選單會在 `Menu::order_submenu()` 移除，改由
  `admin.php?page=nowcrm-campaigns`（`CampaignListPage`）擁有選單位置。
- 模式：`MODE_BLOCKS`（Gutenberg 內容）／`MODE_HTML`（Raw HTML）。
- 狀態：`draft`、`scheduled`、`sending`、`paused`、`sent`、`cancelled`。
- Meta：`_nowcrm_status`、`_nowcrm_mode`、`_nowcrm_html`、`_nowcrm_subject`、
  `_nowcrm_preheader`、`_nowcrm_from_name`、`_nowcrm_from_address`、`_nowcrm_reply_to`、
  `_nowcrm_footer_text`、`_nowcrm_audience`、`_nowcrm_utm`、`_nowcrm_tracking`、`_nowcrm_design`、
  `_nowcrm_schedule_at`、`_nowcrm_campaign_snapshot`、`_nowcrm_audience_max_id`、
  `_nowcrm_recipient_total`、`_nowcrm_campaign_cursor`、`_nowcrm_enqueue_done`、
  `_nowcrm_sent_at`、`_nowcrm_sent_count`。
- Hooks：`nowcrm_campaign_enqueue`、`nowcrm_campaign_start`、`nowcrm_campaign_sweep`。
- 重要方法：`state()`、`mode()`、`can_send()`、`template()`、`build_snapshot()`、
  `persist_fields()`、`schedule()`、`start()`、`pause/resume/cancel()`、`enqueue_batch()`、
  `maybe_finalize()`、`sweep()`、`progress()`、`test_send()`。
- 受眾草稿：`{scope,include,exclude,segment?,filters?}`。
  - `scope`：`all`／`rules`／`segments`／`advanced`。`include`／`exclude` 是 `{list,tag,segment}`
    規則列，同列 AND、列間 OR；`segment` 可為整數 ID、`custom:<id>` 或 `system:<key>`，歷史整數引用照舊。
  - 排除條件在所有模式都取 NOT；只寄 `subscribed` 且未全域抑制的聯絡人。
  - `segments` 只用單一 `segment`；`advanced` 只用 `filters`：`{version:1,groups}`，
    `Condition {provider,field,operator,value}`，群組間 OR、群組內 AND。
  - 空或缺少 qualifier 可以儲存，但命中 0 人，絕不放大為全部；`validate()` 只在儲存、預覽、檢查、
    確認時驗證作用中的規則。已儲存的無效進階條件保留供修復；編譯過期引用時 fail closed。
  - 預覽與佇列用同一份 selection；確認成功後才把受眾寫進凍結的 `snapshot`。
- 工作區與預覽契約：
  - 建立表單只送 `title`／`mode`，成功一律導向 `CampaignPage::url()`；範本選擇移到「內容」步驟。
  - 四步驟：內容 → 主旨與設定 → 收件人 → 檢查與寄送；重新整理或回到工作區預設停在「內容」。
  - Gutenberg 直接內嵌在「內容」步驟：`admin.php?page=nowcrm-content-editor&target=campaign&id=<id>&embed=1`。
    直接開電子報的內容編輯網址但不帶 `embed=1` 會導回電子報工作區。iframe 與父層同源，
    由 `window.nowcrmEditorBridge` 提供 blocks／ready／dirty／revision；父層只用同一個受
    revision 保護的 `PUT /campaigns/{id}` 儲存，儲存成功後才把送出的 blocks 標記為已儲存。
  - 草稿變更 5 秒後自動儲存，仍有未存變更時每 60 秒再寫一次；失敗退回 60 秒重試，不會每 5 秒重送。
    寫入期間新增的文字或 blocks 不會被回應覆蓋，下一次自動／手動儲存再送出。
  - 切換編輯方式前會先以一次 PUT 保存目前正文與新 mode；失敗時保留原畫面與輸入，另一種正文不轉換、不丟棄。
  - `DummyPost::ensure()` 直接比對來源標題／正文與工作貼文，不同就同步；不依時間戳，也不另存指紋。Raw HTML 不建立工作貼文。
  - `POST /campaigns/{id}/preview`：body 只能有 `html` 或 `blocks` 其中一種（`blocks` 可帶 `design`）。
    僅草稿、唯讀，重用 `Campaigns::snapshot()`、`EmailRenderer` 與既有 CSP；預覽不寫入草稿，
    錯誤與載入狀態顯示在預覽彈窗。非草稿只用 GET。
  - `draft.design` 存於 `_nowcrm_design`（`body`／`content`／`heading`／`text`／`link`，只接受 strict hex，
    空字串代表預設），只套用 blocks 正文；切到 Raw HTML 不刪除，寄送快照會凍結當下設計。
    顯式區塊顏色優先於全域顏色；只有 font-size 的 style 仍會補上全域顏色。
  - 內容步驟只留單一全寬編輯區；「編輯方式」旁的預覽按钮以目前未儲存內容開啟桌面／手機彈窗。
    Gutenberg 內嵌編輯器高度為 `clamp(640px, 90vh, 1400px)`，與 FluentCRM 編輯器同級（約 90% 視窗高）。
    Raw HTML 使用 WordPress 內建 CodeMirror（`wp_enqueue_code_editor`，HTML 模式、行號、自動換行、關閉 lint），
    左側原始碼、右側同高即時預覽；預覽以 600ms debounce 呼叫唯讀 `/preview`，不儲存草稿。
  - 收件人步驟面板與該步驟頁尾同寬 800px 並置中；四種模式是原生 radio 組成的文字列（無圓點，
    選中為深色底線，箭頭鍵可切換），說明文字放在對應區塊，不再置於模式列下方。
    包含／排除在沒有資料時各顯示一列不寫入受眾的空白草稿列；「＋ 新增一列」在表格下方，
    操作欄只有圖示移除鈕（含區塊與列號的可讀名稱）。空白列只提示「不會影響結果」，不表示排除所有人。
    進階空白時顯示虛擬群組與「＋ 新增條件」，按「＋ 或」才建立下一個群組；非名單模式把排除列
    收進可折疊區塊，已有排除列時自動展開。人數固定在置中列，數字用徽章；進階尚未按「篩選」、
    載入中或錯誤時顯示破折號，不會先顯示錯誤人數。
  - Gutenberg 的 Document 分頁註冊 `PluginDocumentSettingPanel`：加入範本（iframe 經 bridge 請求父層
    彈窗，重用既有套用 POST）、匯入 HTML、全域設計、Smartcodes（PHP catalog 在地化，只列實際支援的 campaign token）。
  - 「匯入 HTML」貼上完整文件或片段後，依文件順序擷取 `h1`–`h6`、`p`、`ul`／`ol`、`blockquote`、
    `figure`／`img`、`hr`、獨立連結與 button-like 連結，轉成原生 `core/*` 區塊，不產生 `core/html`。
    表格骨架不保留，內容會拆成段落／圖片；可選擇取代目前內容或附加到最後。匯入後沿用既有 bridge 與
    revision 儲存流程，使用者可繼續在 Gutenberg 視覺編輯。需要像素級原始版面時仍應使用 Raw HTML 模式。
  - `EmailRenderer::render_blocks()` 不再直接使用 `do_blocks()`；`Email\BlockRenderer` 把 `core/columns`／`core/column`
    轉成 `<table><tr><td>`、`core/group` 轉成 table cell（背景與 padding inline）、`core/buttons`／`core/button` 轉成
    table cell 按鈕，並為 columns 加上 `nowcrm-columns--stack` 與 `@media (max-width:600px)` 堆疊規則。
    葉節點（paragraph／heading／image／list／quote／table）保留內容並把 block 的顏色、字級、行高、間距 inline 化；
    不再是 web 的 flex 版面。blocks 模式的外框也不再對所有 `td` 加框線與 padding。
    Gutenberg 可能留下 `var:preset|…` 或 `var(--wp--preset--…)` 宣告；`BlockRenderer::render()` 會丟掉這些
    未解析的宣告，只留下已換算的 inline CSS。
  - 區塊樣式只對 `nowcrm_editor` 註冊三個 section pattern：`nowcrm/hero`、`nowcrm/product-grid`、`nowcrm/cta`，
    分類為「NOW CRM 電子報」。pattern 只用 core blocks；商品卡圖片區目前是可直接替換的灰色 group，
    避免插入未指定 `src` 的圖片造成寄送破圖。
  - `Email\DesignTokens` 提供固定 px 的字級（small 14、medium 16、large 20、x-large 26、xx-large 34、display 42）、
    間距（10–80px）與 sans／serif 字型。編輯器用同名 preset slugs，並以 `:root` CSS 覆寫 theme 的 rem／clamp；
    `block_editor_settings_all` 先帶入 top-level `fontSizes`，前端再於 global-styles renderer 覆寫後補回
    `__experimentalFeatures`，確保字級／間距／字型面板與郵件輸出使用同一組值。
  - 段落／標題的 `font-family` preset 由 renderer 換成實際系統字型 stack；spacing preset 換成 px。
  - 工作區取消 1080px 上限；內嵌 Gutenberg canvas 固定最寬 680px 並置中，兩側保留灰色留白（可直接在灰色區滾動頁面），
    實際郵件卡片仍維持 600px。工作貼文不支援 title，且隱藏文件列的 `editor-document-bar__post-title`，
    內嵌編輯器不再出現任何標題輸入。
  - 套用範本只覆寫範本模式的正文；另一種模式已存的正文保留。
  - 頁尾未設定時使用預設文字：寄件者名稱換行後接 `{{unsubscribe_url}} 或 {{manage_url}}`；
    渲染時分別成為「取消訂閱」與「管理訂閱偏好」連結，兩個 token 都屬合規允許的 smartcode；
    沒有管理偏好網址時，管理連結退回取消訂閱網址，不會把原始 token 印進信件。
  - Raw HTML 若含 `<!DOCTYPE` 或 `<html>`，視為完整文件：保留 `<head>`／`<meta>`／`<style>`／條件註解，
    不再包一層 `nowcrm-card`、不改寫 CSS、不加第二個頁尾；只在前述文件沒有任何取消訂閱／管理連結時，
    於 `</body>` 前補一個合規頁尾；完全沒有 charset 宣告時補 `<meta charset="utf-8">`。HTML 片段仍套用 NOW 外框與頁尾。
  - 完整文件支援 FluentCRM 的 `##crm.first_name##`、`##crm.unsubscribe_url##`、
    `##crm.manage_subscription_url##`、`##web_preview_url##`，以及對應的 `{{crm.*}}` 形式；
    這些 token 也列入合規允許清單，遷移的 FC 電子報可直接通過寄送前檢查。
  - 草稿 `GET /campaigns/{id}` 另回 `advanced`：`{fields,operators,providers,limits}`；`fields` 以
    `provider.field` 為鍵，`options` 為 value→label，`lookup:true` 表示選項需另查。目錄只帶 UI
    metadata，不含 SQL。受眾預覽／估算是即時計算，`snapshot` 只在確認成功時凍結。
- REST（`nowcrm/v1`）：
  - `GET`／`POST /campaigns/{id}/preview`（POST body 僅 `html` 或 `blocks` + 選配 `design`：草稿未儲存內容，唯讀）
  - `GET /campaigns/{id}/recipient-options?field=<provider.field>&search=&selected=<id,id>`（草稿唯讀；
    只回 `{options:[{value,label}]}`，未知欄位 422）
  - `POST /campaigns/{id}/recipients`（body `{audience,page?,count_only?}`；草稿唯讀，回
    `{count,page,per_page,recipients:[{id,email,name}]}`，每頁 20 筆並夾到最後一頁）。
    `count_only:true` 只回 `{count}`，不讀名單列；簡單模式（全部／名單／標籤／動態分眾）由前端
    自動重算，進階篩選要按「篩選」才查。估算一律不寫入、不動 revision 與 snapshot。
  - `POST /campaigns/estimate`
  - `GET /campaigns/{id}/editor`
  - `POST /campaigns/{id}/settings`
  - `POST /campaigns/{id}/estimate`
  - `POST /campaigns/{id}/action`
  - `POST /campaigns/{id}/test`
  - 公開：`cart/track`、`open/{token}`、`click/{token}`、`ses/sns`
- 佇列：`EmailJobRepository::enqueue/search/get/campaign_stats()`、`Queue::run()`、
  `EmailDispatcher`、`Tracking::inject()`；`wp_mail()` 回 true 只代表 `accepted`。

**結構限制**：`includes/Campaign/Campaigns.php` 目前約 2,280 行，混合 CPT、REST、寄送狀態與
畫面。新功能不要繼續往這支堆；新畫面與流程放到新的 Admin／Campaign class，逐步拆出
CPT／REST／寄送狀態。

### 名單／標籤 REST 與生命週期

- 路由（`nowcrm/v1`，全部需要 `nowcrm_manage_contacts`）：
  - `GET|POST /lists`、`GET|POST /tags`：清單（分頁／搜尋／排序）與建立。
  - `GET|PUT|DELETE /lists/{id}`、`GET|PUT|DELETE /tags/{id}`：單筆讀取、更新、刪除；
    型別由路徑固定，不能用 request 內容切換。
  - `GET /lists/by-slug/{slug}`、`GET /tags/by-slug/{slug}`：以 slug 取單筆。
  - `POST /lists/bulk`、`POST /tags/bulk`：最多 500 筆；相同 slug 只更新名稱，原有說明與
    聯絡人關聯保留。
  - `POST /lists/bulk-delete`、`POST /tags/bulk-delete`：最多 200 個 ID；只刪除符合路徑型別的項目。
- 錯誤對照：`400` 參數／格式、`401` 未登入、`403` 缺少權限、`404` 找不到或型別不符、
  `409` slug 重複、`500` DB 寫入失敗。
- 生命週期：`nowcrm_term_created`、`nowcrm_term_updated`、`nowcrm_term_deleted`，參數為
  `( int $id, string $type )`；只在寫入成功後觸發，批次操作逐筆回報實際變更的項目。
- 後台 HTML 與 REST 共用 `TermRepository`，不是兩套行為。

## 4. 常用 repository／service

| 需求 | 入口 |
|---|---|
| 聯絡人搜尋／聚合 | `ContactRepository::search()`、`aggregate()`、`aggregate_many()` |
| 聯絡人狀態 | `Status::all()/label()/is_valid()`、`StatusHistoryRepository` |
| 聯絡人檔案欄位 | `ProfileFields::fields()/keys()/defaults()/normalize()`、`ContactProfileFields::render()` |
| 名單／標籤 | `TermRepository::paginate()`、`all($type)`、`create()`／`create_many()`、`update()`、`delete()`／`delete_many()`、`for_contact()` |
| 自訂欄位 | `CustomFieldDefinitions::all()/save_with_groups()`、`CustomFieldValues::prepare()`→`commit()`、`Admin\CustomFields` |
| 聯絡人筆記 | `NoteRepository`（`kinds()`、`search()`）、`ContactNotes` |
| 訂單關聯／金額 | `OrderLinkRepository`、`OrderAccounting`、`PurchaseHistory::enrich()` |
| Email job／統計 | `EmailJobRepository`、`Queue`、`EmailRenderer`、`Tracking` |
| 購物車 | `Cart\CartRepository`、`Recovery`、`Reminder`、`Sweeper` |
| 設定／schema／權限 | `Settings`、`Schema`、`Capabilities` |

## 5. WordPress admin 陷阱（已踩過）

- `add_submenu_page( '', ... )` 會讓 `get_admin_page_title()` 找不到頁面，WP 7.1 在
  `admin-header.php` 對 `null` 呼叫 `strip_tags()`；用 `admin_init` 補 `$GLOBALS['title']`。
- hidden page 若留在 `$submenu['']`，`get_admin_page_parent()` 會把 `parent_file` 設成空字串，
  top-level 選單就不會是 current；註冊後 `remove_submenu_page( '', $slug )`。
- `submenu_file` filter 不要回傳 `(string) null`。`isset( $submenu_file )` 會變成 true，
  WordPress 只做精確比對，當頁就不會高亮；原值保留 null。
- CPT 子選單由 `_add_post_type_submenus` 在 `admin_menu` priority 10 加入；自訂排序要在
  priority 20 做。
- `post-new.php?post_type=nowcrm_campaign` 的 `submenu_file` 不是自製頁 slug；需要映射到
  `nowcrm-campaigns`，否則側欄會高亮錯的項目或全部取消高亮。
- CPT 子選單在 `admin_menu` priority 20 移除後，`post.php`／`post-new.php` 的 `parent_file`
  也要由 `filter_parent_file()` 指回 `nowcrm-dashboard`，top-level 選單才會保持展開。
- 本機 Tern 的 campaign Gutenberg canvas 在 `iframe[name="editor-canvas"]`；iframe 內文字
  不在 top document。Gutenberg 預覽另開 tab 時，用 REST `editor` 回傳的 `preview_url`。

## 6. 營運工作區手動驗收

用本機合成資料，寄送維持 `capture`；不在正式站操作或匯入。依 [設計基準](design-system.md)
先確認列表首屏可直接閱讀資料，新增或設定不擋在前面。再抽查：

1. `nowcrm-dashboard` 的聯絡人、佇列與購物車摘要進對應篩選；電子報累計與近 30 天報表分別標示。`nowcrm-reports` 切換分頁保留日期；3 月對上一個月只比較 2 月，不與本期重疊。
2. `nowcrm-cart` 從搜尋結果的第 2 頁開單筆，進 `nowcrm-automation-runs&id=<automation_id>&run_id=<run_id>` 與 `nowcrm-email&job=<id>`，再返回原篩選；購物車提醒用 `capture` 驗證，不寄真信。
3. `nowcrm-settings&tab=email` 儲存不覆寫 Woo 或購物車。`nowcrm-webhooks` 的單筆傳送紀錄只顯示該端點；送出不合法設定時，可在原表單修正，簽章密鑰不回填。
4. 從設定開啟 `nowcrm-tools` 的 FluentCRM 遷移指引：可看到 WP-CLI 預演、套用、續跑及查狀態指令；後台沒有可觸發遷移的表單。
5. `nowcrm-segments`／`nowcrm-forms` 從篩選列表開單筆，返回保留原條件；表單送出紀錄可直達對應聯絡人。`nowcrm-smart-links` 的重複代稱顯示錯誤且保留草稿。

## 7. 驗證

```bash
# PHP 語法
docker compose run --rm -T cli sh -c 'find /var/www/html/wp-content/plugins/now-crm -name "*.php" -not -path "*/node_modules/*" -print0 | xargs -0 -n1 php -l'

# 受影響測試
cd plugins/now-crm && ./tests/bin/run-tests.sh --filter CampaignTest

# 全部測試（交接前）
cd plugins/now-crm && ./tests/bin/run-tests.sh

# 前端（有改 src/ 才需要）
npm run build && npm run check-types
```

- 小改動先跑受影響測試／lint；不要每次重跑全套。
- 畫面驗收開本機後台對應 URL；使用者已表明不需要每次截圖。
- 不要把測試資料或本機設定寫進預設；不要寄真實信。

## 8. 自動化（後端與後台已實作）

後端程式在 `includes/Automation/`，資料表 `automations`、`automation_steps`、
`automation_runs`、`automation_run_events`（schema 2.3.0）。後台列表／編輯器／執行紀錄已接好；
不要重寫引擎或直接改 run 指標。

固定鍵（不可改名）：

| 類別 | 鍵 |
|---|---|
| 觸發 | `contact_created`、`contact_updated`、`contact_status_changed`、`term_attached`、`term_detached`、`csv_imported`、`order_completed`、`order_refunded`、`order_cancelled`、`cart_abandoned`、`contact_birthday`、`wp_login` |
| 動作 | `apply_list`、`remove_list`、`apply_tag`、`remove_tag`、`set_status`、`add_to_sequence`；`apply_company`／`detach_company` 僅保留給既有已儲存步驟，新步驟不再提供 |
| 步驟種類 | `action`、`wait`、`email`、`condition`、`split`、`goal`、`end` |
| 目標事件（goal 用） | `term_attached`、`term_detached`、`status_changed` |
| 分支 | `yes`、`no`（`condition`）；`a`、`b`（`split`）；`timeout`（`goal`） |

持久化入口是 `AutomationRepository::save()`。輸入形狀：

```php
[
  'name' => '...', 'status' => 'draft|active|paused',
  'trigger_key' => 'order_completed',
  'trigger_config' => ['statuses'=>[], 'sources'=>[], 'term_ids'=>[], 'term_types'=>[], 'conditions'=>plan],
  'entry_config' => ['run_once'=>false, 'force_run'=>false],
  'steps' => [
    ['kind'=>'action', 'action_key'=>'apply_tag', 'config'=>['term_id'=>12]],
    ['kind'=>'wait', 'delay_seconds'=>3600],
    ['kind'=>'condition', 'config'=>['conditions'=>plan], 'yes'=>[...], 'no'=>[...]],
    ['kind'=>'split', 'config'=>['weight_a'=>50, 'weight_b'=>50], 'a'=>[...], 'b'=>[...]],
    ['kind'=>'goal', 'config'=>['event'=>'term_attached|term_detached', 'term_ids'=>[12]]],
    ['kind'=>'goal', 'config'=>['event'=>'status_changed', 'statuses'=>['transactional']]],
    ['kind'=>'email', 'config'=>['subject'=>'...', 'mode'=>'html|blocks', 'content'=>'...', 'preheader'=>'...']],
    ['kind'=>'end'],
  ],
]
```

- 條件計畫直接使用 `AdvancedFilters` 的 typed plan；不要另做一套運算子。
- `split` 是原生 A/B 分流步驟，權重 `weight_a`／`weight_b` 為 0–100 的整數且必須合計 100
  （兩者都省略時存 50／50）。分支鍵固定 `a`／`b`，可放巢狀的 action／email／wait／condition／
  split／goal 步驟；分支跑完會接回 split 之後的步驟，分支為空時直接接回。
  分派以 `automation_id` 與 `contact_id` 的穩定雜湊取 1–100 的整數點，`point <= weight_a`
  走 `a`，其餘走 `b`；同一聯絡人重跑不會換邊。選中的分支在首次執行時寫入該步驟的
  run event payload（`kind`、`branch`、`weight_a`、`weight_b`、`point`），重試沿用同一筆
  事件，因此重送或鎖重入都不會改派；執行紀錄的步驟事件會顯示選中的分支。
- Goal／benchmark 讀取原生事件：`term_attached`、`term_detached`、`status_changed`
  （對應 `contact_status_changed`）。必經目標未達成時停在 `waiting`；沒有逾時則 `next_run_at=NULL`，
  設定 `timeout_seconds` 時到期走 `timeout` 分支，分支留空則直接續行。選用目標不阻擋流程。
  逾時前符合設定的事件讓執行恢復為 `active`，由排程器續行，不在觸發 hook 內執行動作。
- 事件若在 run 抵達 goal 前已發生，會用一次便宜查詢（目前名單／標籤連結、目前聯絡人狀態）
  判斷是否已達成；達成則直接前進並在事件 payload 記 `satisfied=true`。名單／標籤多選採
  「任一」語意，與現有觸發篩選一致。
- 一般等待以 `next_run_at` 排程；在根層或分支的最後一步也會到期才完成。
- 執行中改動步驟時，`last_completed_step_id` 會依 ordinal 重指；若 goal 步驟被移除，
  原本停在該 goal 的 run 不會再被事件喚醒（維持 `waiting`，待人工處理）。
- `run_once=false`（預設）允許完成後重新進入：同一個 `(automation, contact)` run 列會重置並清空
  舊事件。`run_once=true` 完成後永久阻擋同一聯絡人。購物車 Trigger 例外：來源身分是
  `cart:<cart_id>:<version>`，同一購物車重播只命中原 Run，同 Contact 的另一台購物車獨立，
  見 §12。
- 排程鉤子 `nowcrm_automation_tick`（每分鐘）；只有存在 `active` 自動化時才排程。
- Email 以 `kind='automation'` 寫入既有 `email_jobs`。`Email\Queue` 發送前重驗訂閱、抑制與
  快照地址是否仍為目前的聯絡人地址；不合格就略過，不改寄新地址，也不注入 campaign 追蹤。
- 目標沒有「任一／全部」切換；分流不自動選勝出者，執行紀錄只顯示各分支進入與完成數。
- 只改名稱、觸發或進入條件時，步驟定義相同就保留原步驟 ID、執行指標及事件對應。
  實際變更步驟時才替換步驟，執行中的指標以 ordinal 就近重指，過去的事件列仍保留。
- 一般 Email 步驟只排給 `subscribed` 聯絡人；`transactional` 聯絡人會記為 skipped。購物車
  Trigger 的 Email 步驟例外：`subscribed` 與 `transactional` 都可以寄，因為那是購物車專用
  的提醒政策，不是行銷放寬。

## 9. 自動化 UI（已建立）

| 畫面 | URL | Renderer | Capability |
|---|---|---|---|
| 自動化列表 | `admin.php?page=nowcrm-automations` | `AutomationListPage::render` | `nowcrm_manage_contacts` |
| 編輯器（隱藏頁） | `admin.php?page=nowcrm-automation&id=<id>` | `AutomationEditorPage::render` | `nowcrm_manage_contacts` |
| 執行紀錄（隱藏頁） | `admin.php?page=nowcrm-automation-runs&id=<id>` | `AutomationRunsPage::render` | `nowcrm_manage_contacts` |

Admin-post 動作（全部要求 capability＋nonce）：

| Action | 行為 |
|---|---|
| `nowcrm_create_automation` | 由列表新增抽屜選觸發事件，再建立含預設 `end` 的草稿並開啟編輯器 |
| `nowcrm_save_automation` | 儲存名稱／觸發／進入條件／觸發篩選與 `steps_json`；repository 重驗。只在明確提交 `save_status=active` 時啟用 |
| `nowcrm_set_automation_status` | `active`／`paused`／`draft`；列表或編輯器可呼叫 |
| `nowcrm_duplicate_automation` | 複製 automations、steps 與設定為新草稿 |
| `nowcrm_delete_automation` | 刪除 automation、steps、runs、run events |

編輯器行為：

- 步驟卡片可加入、上移／下移、移除。支援 `action`、`wait`、`email`、`condition`、`split`、`goal`、`end`。
- 目標卡片有事件選單（`data-field="goal_event"`）與依事件切換的名單／標籤多選
  （`data-field="goal_term_ids"`）或狀態多選（`data-field="goal_statuses"`）；未選取時前端
  標紅並阻止送出，後端也會拒絕。
- 條件卡片有 `yes`／`no` 兩個分支容器，分支內可再加任意支援的步驟（含巢狀條件）。
- 瀏覽器只在使用者送出時把卡片序列化成一個 `steps_json`；沒有 JavaScript 時會保留原本的
  `steps_json`，因此基本欄位仍可儲存。
- 條件編輯器提供欄位、運算子與值的表單，也保留進階 JSON；兩種入口都存同一份
  `AdvancedFilters` plan，後端會重驗。
- UI 的觸發篩選只暴露狀態、名單／標籤、類型與來源；`trigger_config.conditions`（進階篩選 plan）
  仍由 repository/API 支援，尚未做專屬 UI。
- 狀態操作是獨立表單，避免與主編輯表單巢狀。刪除有確認對話框。
- 列表的執行中、等待中、完成筆數各連到對應的執行狀態；執行紀錄沿用 Contacts 表格，
  窄畫面可橫捲，A/B 摘要置於主要紀錄下方。

## 10. 序列（後端與後台已實作）

路由：

| 畫面 | URL | Renderer | Capability |
|---|---|---|---|
| 序列列表 | `admin.php?page=nowcrm-sequences` | `SequenceListPage::render` | `nowcrm_manage_contacts` |
| 編輯器（隱藏頁） | `admin.php?page=nowcrm-sequence&id=<id>` | `SequenceEditorPage::render` | `nowcrm_manage_contacts` |
| 追蹤（隱藏頁） | `admin.php?page=nowcrm-sequence-runs&id=<id>` | `SequenceRunsPage::render` | `nowcrm_manage_contacts` |

Admin-post 動作（全部要求 capability＋nonce）：

| Action | 行為 |
|---|---|
| `nowcrm_create_sequence` | 建立草稿與一封含預留段落的 Gutenberg 郵件，然後開啟序列工作區 |
| `nowcrm_save_sequence` | 儲存名稱／狀態／受眾／`allow_reentry` 與 `steps[]`；repository 再驗證 |
| `nowcrm_set_sequence_status` | `active`／`paused`／`draft`；狀態變更會同步 cron |
| `nowcrm_duplicate_sequence` | 複製定義為新草稿 |
| `nowcrm_delete_sequence` | 先取消追蹤與未寄出佇列，再刪除 CPT |
| `nowcrm_enroll_sequence` | 加入一批最多 100 位；`after_id` 續傳下一批 |
| `nowcrm_cancel_sequence_enrollment` | 取消單一聯絡人的 active tracker 與 queued jobs |

資料契約：

- CPT `nowcrm_sequence`；meta `_nowcrm_sequence_status`、`_nowcrm_sequence_steps`、
  `_nowcrm_sequence_audience`、`_nowcrm_sequence_allow_reentry`。
- 狀態 `draft`／`active`／`paused`；步驟依 `delay_seconds` 升冪儲存，同延遲為一組。
- 追蹤表 `nowcrm_sequence_trackers`；`active_key=1` 搭配 UNIQUE
  `(sequence_id,contact_id,active_key)`，完成或取消後為 `NULL`，因此保留歷史且只允許一個進行中。
- 佇列 `kind='sequence'`，`job_key='sequence:{sequence_id}:tracker:{tracker_id}:step:{index}'`；
  每步的模板在建立 job 時凍結。寄送仍由 `Email\Queue` 重驗 subscribed／suppression，序列不直接寄信。
- Cron `nowcrm_sequence_tick`（Action Scheduler group `nowcrm`，WP-Cron 每分鐘後備）；
  只有存在 active 序列時排程，`process_due()` 以 `GET_LOCK` 序列化、每批 100、50 秒預算。

UI 行為與 selector：

- 列表 `[data-testid="sequence-list"]`、`[data-sequence-id]`、`[data-testid="sequence-create-name"]`；
  加入結果 `[data-testid="sequence-enrollment-result"]`。
- 編輯器 `[data-testid="sequence-form"]`、`[data-testid="sequence-name"]`、`[data-testid="sequence-status"]`、
  `[data-testid="sequence-steps"]`；追蹤頁 `[data-testid="sequence-runs"]`、`[data-tracker-id]`。
- 受眾以 radio `audience_type` 選擇；名單／標籤使用 `list_term_id`／`tag_term_id`（避免兩個 select 同名）；
  分眾使用系統或已儲存分眾；進階篩選直接重用 `ContactAdvancedFilters::render()`。
- 工作區預設顯示郵件清單，支援新增／上移／下移／刪除；名稱、狀態與受眾在序列設定。
- 新增或編輯時，一次只開一封郵件。主旨與預覽文字在正文上方，區塊格式用 NOW Campaign 同一套
  Gutenberg 內嵌編輯器，Raw HTML 則用獨立文字區。返回清單不會送出或清除未儲存內容。
- Gutenberg 使用序列的私有空白工作文章，不會直接寫回任何步驟；序列只在送出 `steps[]` 時驗證與儲存，
  不接受前端自行宣告可寄送。停用 JavaScript 時保留表單文字區。
- 寄送總開關關閉時，列表、編輯器與追蹤頁都顯示黃色提示：序列仍建立佇列，但不會寄出。
- 已知限制：暫停只停止後續步驟排入佇列；已排入的 job 不會因暫停自動取消，取消追蹤或刪除序列才會取消
  `queued` jobs。`processing`／`sending` 的 job 不強制中斷。

## 11. 定期電子報（獨立後台頁）

定期電子報以一般電子報作為「範本」，每個期間複製一份子電子報。子電子報是獨立草稿，建立後
不再讀取範本內容。定期寄送有獨立入口，不再混在電子報列表或電子報工作區。

畫面：

| 位置 | 行為 |
|---|---|
| 定期寄送 `admin.php?page=nowcrm-recurring` | 只列 `_nowcrm_recurring_status` 存在的家長；標題搜尋、狀態頁籤與筆數、10 筆分頁。`new=1` 內嵌建立表單（名稱、編輯方式、頻率、時間、星期／日期、自動寄送），建立為未啟用草稿。勾選後可批次移至回收桶。 |
| 定期詳情 `admin.php?page=nowcrm-recurring&campaign=<id>` | 與 `edit=content` 相同：`CampaignPage` 工作區，只有 郵件內容／設定／寄送紀錄 三個分頁，沒有上一步／下一步。標頭顯示定期狀態膠囊（啟用中／已暫停／未啟用）與「頻率 · 已產生 N 封」中繼行；返回連結回到定期列表。 |
| 郵件內容分頁 | 主旨與預覽文字在原生 Gutenberg 或 Raw HTML 畫布上方；沿用既有編輯方式、預覽與範本動作，「儲存草稿」只寫草稿與版本。 |
| 設定分頁 | 寄送排程（頻率、每週星期或每月日期、時間、自動寄送）＋「儲存排程」以 `PUT /recurring` 儲存；操作列「立即產生這一期」、「暫停」或「恢復」、「停用」，複製與刪除沿用既有 admin-post nonce 表單與 handler。恢復與立即產生前會先儲存草稿內容；儲存排程只保留目前載入的狀態，不會順便啟用。同一分頁另有收件人規則與其他電子報設定。 |
| 寄送紀錄分頁 | 獨立列出最近 20 筆子電子報（名稱連到子電子報工作區或寄送報告、狀態、建立時間），沒有子電子報時顯示空狀態。 |
| 內容工作區 `admin.php?page=nowcrm-recurring&campaign=<id>&edit=content` | 同一個工作區；舊內容網址直接開啟郵件內容分頁。 |
| 電子報列表與總覽 | 使用 `Recurring::exclude_meta_query()` 排除定期家長與子電子報；子電子報只從寄送紀錄分頁進入。 |

REST（`nowcrm/v1/campaigns/<id>/recurring`，權限一律 `Campaigns::can_edit()`）：

| 方法 | 行為 |
|---|---|
| `GET` | 回傳 `campaign_id`、`is_draft`、`is_child`、`recurring`（`Recurring::summary()`）、`children`（最近 20 筆子電子報的 `id`／`title`／`status`／`status_label`／`created`／`url`）與 `child_count` |
| `PUT` | 驗證並儲存 `frequency`（`daily`／`weekly`／`monthly`）、`time`（`HH:MM`）、`day`（每週 `mon..sun`、每月 `1..31`）、`auto_send`；`status` 可省略，省略時保留家長目前狀態（新的一般電子報仍預設 `active`），因此排程儲存不會意外啟用；啟用／暫停走 `POST`。 |
| `POST` | `op` = `pause`／`resume`／`disable`／`generate`；`generate` 成功回 201 並附 `child_id`，同一個期間重複產生回 409 `nowcrm_recurring_duplicate` |

資料契約（全部是 post meta）：

- 範本 `_nowcrm_recurring_status`（`active`／`paused`／`inactive`）、`_nowcrm_recurring_frequency`、
  `_nowcrm_recurring_time`、`_nowcrm_recurring_day`、`_nowcrm_recurring_auto_send`、
  `_nowcrm_recurring_next_run_at`、`_nowcrm_recurring_last_run_at`、`_nowcrm_recurring_error`。
- 子電子報 `_nowcrm_recurring_parent`（範本 ID）、`_nowcrm_recurring_slot`
  （`daily:Y-m-d`／`weekly:<該週第一天>`／`monthly:Y-m`），同一範本同一 slot 只允許一份。
- 子電子報由 `Campaigns::duplicate()` 建立，複製主旨、預覽文字、正文、收件規則、寄件者、
  追蹤、UTM 與頁尾；之後改範本不影響已建立子電子報。
- 啟用需要主旨與正文；暫停可在內容尚未完成時先儲存。只有勾選 `auto_send` 且
  `Delivery::confirm()` 全部規則通過（寄送總開關、合規連結、有效受眾）才會排入寄送，
  否則子電子報留在草稿。

Cron：

- `nowcrm_recurring_tick`（Action Scheduler group `nowcrm`）／WP-Cron 每分鐘 `nowcrm_recurring_minute`，
  只有存在 `active` 範本時排程；`Recurring::run()` 以 `GET_LOCK` 序列化、每批 10 封
  （filter `nowcrm_recurring_batch_limit`）。
- 下次執行時間由站台時區的 `time` 計算：每日／每週（`day` 為星期）／每月（`day` 超過當月天數時
  夾到月底）。距執行時間 30 分鐘內儲存會順延到下一個期間；執行後再順延，避免同一 slot 重複。

後台動作（全部 capability＋nonce＋家長物件權限）：

| Action | 行為 |
|---|---|
| `nowcrm_create_recurring` | 建立電子報草稿並寫入未啟用的定期設定；排程不合法時回建立表單保留輸入 |
| `nowcrm_save_recurring` | 更新名稱、頻率、時間、星期／日期、自動寄送；狀態不由此表單變更 |
| `nowcrm_recurring_status` | `pause`／`resume`／`disable`；恢復需要主旨與正文 |
| `nowcrm_generate_recurring` | 以目前期間建立一封子電子報；重複期間回錯誤 |
| `nowcrm_duplicate_recurring` | 複製內容與排程，副本一律未啟用且沒有子電子報 |
| `nowcrm_delete_recurring`／`nowcrm_bulk_recurring` | 先拒絕仍有排程、寄送或暫停中子電子報的家長，否則將子電子報與家長移到回收桶並同步 cron |

內容工作區使用 `CampaignPage::enqueue()` 的 `listUrl`／`listLabel`／`recurring` 參數，返回定期詳情，
且不顯示寄送步驟。REST 端點保留給相容呼叫；後台畫面不再嵌入定期面板。

## 12. 購物車 ↔ 自動化／執行／寄送／訂單追溯

購物車頁先顯示資料；設定頁只管理捕捉、時間與 owner，提醒信內容在 owner 自動化的 Email 步驟。這一節只列既有頁面與既有 slug，不新增路由。

### 12.1 路徑

| 位置 | URL | 說明 |
|---|---|---|
| 購物車列表 | `admin.php?page=nowcrm-cart`；可加 `&status=<status>`、`&email=<email>`、`&paged=<n>` | 狀態與 Email 精確篩選，保留頁碼。 |
| 購物車詳情 | `admin.php?page=nowcrm-cart&cart=<cart_id>` | 商品快照、活動、訂單歸因、來源自動化執行、提醒與還原。 |
| 購物車設定 | `admin.php?page=nowcrm-cart&tab=settings` | 捕捉、時間、owner，以及前往 owner 自動化編輯 Email 的連結。需要 `nowcrm_manage_settings`。 |
| 自動化 | `admin.php?page=nowcrm-automation&id=<automation_id>` | 檢視 owner 流程或受管理流程的步驟與狀態。 |
| 執行紀錄 | `admin.php?page=nowcrm-automation-runs&id=<automation_id>` | 該流程的 Run 清單；可加 `run_status`／`run_search`。 |
| 單筆執行 | `admin.php?page=nowcrm-automation-runs&id=<automation_id>&run_id=<run_id>` | 精確 Run：來源、購物車連結、步驟事件、經核對的 Job。 |
| 購物車寄送紀錄 | `admin.php?page=nowcrm-email&tab=log&cart_id=<cart_id>` | 該購物車全部 Job；加 `kind=cart` 只看 legacy。 |
| 單筆 Job | `admin.php?page=nowcrm-email&tab=log&job=<job_id>` | 嘗試、狀態、來源、錯誤。不顯示 recovery token。 |
| 聯絡人購買 | `admin.php?page=nowcrm-contact&id=<contact_id>&tab=purchases` | 訂單明細與 counted 金額。 |
| Woo 訂單 | 由 `WC_Order::get_edit_order_url()` 產生（HPOS：`admin.php?page=wc-orders&action=edit&id=<order_id>`；傳統：`post.php?post=<order_id>&action=edit`） | 需要 `edit_shop_order(s)`；沒有權限時只顯示 ID。 |

單筆執行頁的 Job 表只列事件中可核對、且 `payload.run_id`／`automation_id` 與該 Run 相符的 Job。
購物車詳情的「自動化執行」只列以這台購物車為來源的 Run。

### 12.2 手動重現（本機／Staging、capture）

1. 以測試顧客在 Classic 或 Blocks checkout 留下商品與 Email。記下購物車 ID；不要使用正式資料。
2. 讓它達閒置門檻，執行 `wp nowcrm cart sweep`。開 `nowcrm-cart&cart=<id>`：
   狀態 `abandoned`、活動紀錄有 `abandoned`、`自動化執行` 有一筆 Run。
3. 點 Run 進 `nowcrm-automation-runs&id=<automation_id>&run_id=<run_id>`：
   來源為 `cart_abandoned`、`source_ref=cart:<cart_id>`、購物車連結可返回、步驟事件與 Job 表存在。
4. 點 Job 進 `nowcrm-email&job=<job_id>`：狀態應依序為 `queued` 到 `accepted`（capture）；
   `accepted` 只代表 handoff。確認可返回購物車與購物車寄送紀錄。
5. 再次 sweep 或重送同一來源：購物車詳情仍只有同一筆 Run 與同一封 Job；不新增事件。
6. 同 Email 建第二台購物車：兩台各有 Run；取消一台不影響另一台。
7. 建單不付款：狀態轉 `order_pending`，未寄 Job 為 `cancelled`，訂單連結出現，但不顯示挽回。
8. 以有證據的付款完成：轉 `completed`（自然）或 `recovered`（`email_click`），
   `order_paid` 只出現一次；重複 callback 不重複計。
9. owner 失效：暫停目前 owner 流程，再放棄新購物車。詳情沒有 Run，活動紀錄有
   `not_eligible`／`owner_unavailable`；設定頁顯示警告。儲存合格流程或改回預設後才恢復。
10. legacy fence：若同一版本已有 `kind=cart` 的 `queued`／`sending`，新流程不建立 Run，
    活動紀錄為 `legacy_owner`；`accepted`／`unknown` 不重送。
11. 還原連結只在 Staging 測試信中使用；不要從資料庫複製 token 到工單或文件。成功時活動紀錄
    為 `recovery_click` → `cart_restored`，失敗時原購物車與 coupon 保留。
12. 以上是驗收步驟，不是已執行結果。沒有實際輸出就記 **NOT RUN**。

