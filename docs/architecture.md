# NOW CRM — 架構

本文件說明目前的分層、資料模型、寄送佇列、安全邊界與已知限制。實作以 WordPress／
WooCommerce 原生 API 與本 repo 的程式為準。

## 1. 範圍

本外掛以 WordPress／WooCommerce 原生 API 實作 CRM 工作流程：Contacts／Lists／Tags、
WooCommerce 同步、FluentCRM 唯讀匯入、退訂、SES bounce／complaint、Campaign、
Abandoned Cart、WooCommerce processing 信件交接。**不含** CRM 自訂交易信編輯器、
Automation Builder、外部 CRM SaaS。

資料匯入、真實寄送設定與站台切換都是操作步驟，不會由程式自動執行；見
`docs/operations.md`。外掛不隨附任何站台資料。

Multisite：外掛只在 `nowcrm_allowed_site_ids` 篩選器回傳的 site ID 運作，預設為主站
（ID 1）。其他站台不建立也不讀取 CRM 資料。

## 2. 分層

```
now-crm.php                 標頭、常數、activation/deactivation、plugins_loaded
includes/Plugin.php         容器、Multisite 守門、schema 升級、模組註冊
includes/Support/           Schema、Settings、Capabilities、Multisite、Autoloader
includes/Contacts/          ContactRepository、Status、StatusHistoryRepository
includes/Terms/             Lists／Tags
includes/Orders/            訂單關聯與 counted status 規則
includes/Woo/               OrderSync、CheckoutOptin、ProcessingHandoff、WooReconciler
includes/Email/             Renderer、Dispatcher、JobRepository、Queue、Tracking、Suppression
includes/Campaign/          CPT、受眾、排程、統計
includes/Automation/        TriggerRegistry、Engine、Repository、Run、ActionExecutor、排程
includes/Cart/              捕捉、單一 owner 協調、狀態機、提醒政策、還原
includes/Unsubscribe/       token 與公開退訂頁
includes/Ses/               SNS 驗簽與事件處理
includes/FluentCRM/         唯讀 adapter、FluentCRM 匯入
includes/Admin/             總覽、Campaigns、購物車、佇列、設定、工具
includes/Cli/               WP-CLI
src/                        TypeScript 原始碼（cart tracker、Campaign Gutenberg 面板）
assets/build/               `@wordpress/scripts` 產物與 `.asset.php`（需進版控）
```

模組由 `Plugin::modules()` 註冊，每個模組自己掛 hook。Woo hooks 一律註冊，設定在呼叫時
判斷，避免後台切換後需重新載入。

瀏覽器端使用 TypeScript，建置交給 WordPress 原生 `@wordpress/scripts`：WordPress 套件
（`wp-element`、`wp-components`、`wp-api-fetch` 等）保持 external，不另外打包 React。
`assets/build/*.asset.php` 提供依賴與版本，PHP 以它 enqueue。

前端技術邊界固定如下，不要為了新畫面另立第二套：

| 區域 | 實作 |
|---|---|
| Contacts／Lists／Tags | PHP 原生 admin 頁；伺服器渲染表單與列表，不用 React／Gutenberg。 |
| Campaign 編輯器 | TypeScript＋Gutenberg 原生套件；`src/campaign-editor/` 只負責編輯體驗。 |
| Abandoned Cart | 輕量 TypeScript，編譯成一般 JavaScript，只處理結帳互動；不打包框架。 |
| REST API | PHP 負責權限、欄位驗證／sanitize 與持久化；前端不決定寄送資格或狀態。 |

後台設計基準見 `docs/design-system.md`（Executive Monochrome；以 Contacts 列表與詳情為
實作基準），以單色、1px hairline、4px 圓角與高資訊密度為準。

建置指令：

```bash
npm ci
npm run build
npm run check-types
```

`assets/build/` 進 Git，讓沒有 Node 的站台可直接安裝 ZIP；`node_modules/` 不進版控。

## 3. 資料表（`$wpdb->prefix` + `nowcrm_`）

| 表 | 用途 |
|---|---|
| `contacts` | Email 唯一主檔、五狀態、WP user、來源 |
| `contact_status_events` | 狀態歷史（來源＋備註） |
| `terms` / `contact_terms` | Lists／Tags 與關聯 |
| `order_links` | 一筆 Woo 訂單一列；總額、退款後淨額、counted、consent |
| `email_jobs` | 持久佇列；`job_key` UNIQUE、claim／lease／attempts／狀態；Cart Job 另帶 `cart_id` 與來源鍵 |
| `email_events` | 開信、點擊、退訂、bounce、complaint；`event_key` UNIQUE |
| `email_templates` | 可重用內容（購物車提醒） |
| `suppressions` | 地址級抑制（退訂／hard bounce／complaint／manual） |
| `unsubscribe_tokens` | SHA-256 token、期限、單次使用 |
| `carts` / `cart_events` | cart cycle UNIQUE、狀態／版本、活動、提醒、還原、訂單歸因 |
| `automations` / `automation_steps` | 流程定義與有序步驟；`(automation_id, ordinal)` UNIQUE |
| `automation_runs` | 每次執行；Cart 以 `cart_id`＋`cart_version` 為來源身分，同來源只一筆 |
| `automation_run_events` | 逐步結果與機器原因；`(run_id, step_id)` UNIQUE |
| `ses_events` | SNS 原始事件、MessageId 去重、處理錯誤 |
| `import_runs` | FluentCRM 匯入進度、cursor、報告 |
| `legacy_campaigns` | 歷史 campaign 內容與開啟／點擊統計 |

`Schema::VERSION` 是資料表目標版本；`Schema::install()` 可重複執行，不刪資料。`dbDelta` 建立或更新資料表及索引。
一般請求的 `Schema::maybe_upgrade()` 只比對版本選項，不執行 `SHOW TABLES`。資料表完整性
只在啟用、版本升級，以及工具頁的「檢查並修正資料表」（`nowcrm_repair_schema`）執行。

Schema `2.6.0` 只做增量：`automation_runs` 增加 Cart 來源身分（`cart_id`、來源版本／來源鍵）
與對應唯一鍵；舊 `(automation_id, contact_id)` 唯一鍵只在新唯一鍵建立並驗證後才替換。`dbDelta`
不保證刪除舊索引，因此 migration 要有存在檢查、可重試，且不得刪除既有 Run 或 Event 來換表。
升級後不可原地降級；回復只走備份與另行核准的還原程序，見 `docs/operations.md` §5。

## 4. 寄送核心

1. 建立 job：`EmailJobRepository::enqueue()` 以 `job_key` 去重，重跑不會重寄。
2. 喚醒：Action Scheduler（fallback WP-Cron）只呼叫 `Queue::run()`。
3. claim：單一 SQL `UPDATE ... LIMIT n` 將 `queued` 改為 `sending`，帶 claim token 與 lease；
   兩個 worker 不會拿到同一列。
4. 寄送前重驗：Campaign／Cart 狀態、聯絡人 `subscribed`、suppression、購物車條件。
5. 交給 `wp_mail()`；回傳 true 只記 `accepted`，不是送達證明。
6. 失敗：`failed` 可指數退避重試；結果不明或 lease 過期 → `unknown`，不自動重寄，需人工
   `wp nowcrm email retry`。
7. 節流：`email_throttle_per_second`（預設 15）＋批次上限，避免擠壓 Woo 交易信。

### 4.1 購物車單一 owner 與來源執行身分

責任固定如下，不新增第二條提醒排程：

| 模組 | 擁有 |
|---|---|
| `Cart\CartRepository` | Cart cycle、狀態、版本、活動、訂單歸因；以版本＋cutoff 原子轉態。 |
| `Cart\Automation` | owner 選擇與解析、來源 Run 建立／重用、legacy `kind=cart` fence、來源失效取消。 |
| `TriggerRegistry`＋`Engine`＋`RunRepository` | `cart_abandoned` 觸發到 Run／步驟；一般 Trigger 重入契約不變。 |
| `ActionExecutor`＋`EmailJobRepository`＋`Queue` | Cart Email Job 建立、最後 gate、寄送結果回寫。 |
| `Reminder` | 提醒政策、模板與 recovery URL 組合；不再建立新提醒 Job。 |
| `Cart\Tracker`＋Woo CRUD | 精確訂單連結、待付款狀態、付款證據與退款事件。 |

- owner 預設為受管理流程（`cart_automation_id=0`）。可明確選一個啟用中、`cart_abandoned`、
  且含 Email 步驟的流程。選取失效時不 fallback，只在活動紀錄留下機器原因。
- Cart Run 的來源身分是 `cart:<cart_id>:<version>`。重播 Trigger、重複 sweep 或並行 worker
  只會命中原 Run；同 Email 的另一台購物車是獨立 Run。
- `Engine` 仍以站台層 `GET_LOCK` 防止 worker 重疊；Run 本身沒有 per-run lease。crash 造成的
  不明結果由 Job 的 lease／`unknown` 處理，不盲目重寄。
- Cart 步驟在短暫的 per-cart `GET_LOCK` 內先重驗：Cart 仍是 `abandoned`、`cart_id`＋版本與
  `cart:<id>:<version>` 完全相符、流程仍啟用、Run 仍為 open；通過才在同一個鎖內執行該步驟
  （含 Wait 恢復後的第一步）。鎖忙時不改狀態，下一 tick 重試；已發生的非交易動作不回滾。
  此鎖內不得再取 lifecycle lock。
- 訂單、付款、Lost 等終止轉態會取消該 Cart 所有 open Run 與 queued Job，不再只取消最近
  50／100 筆；同 Email 的另一台 Cart 不受影響。`mark_lost()` 是 Lost 的唯一取消者，Sweeper
  不重複取消。非同步的舊版本失效事件只在 Cart 仍為 active／abandoned 時取消舊來源，不會
  取消目前版本的新 Run。
- `record_activity()` 的 `woo_mutation` 只更新同 session 已存在的 Cart：伺服器 cart_hash
  相同即 no-op、保留原 email 與 client 水位、不建立新 cycle、不復活終止或 `order_pending`。
  快照以 `{items, coupons}` envelope 儲存，cast 仍讀舊版純 list；空快照（`item_count=0`）
  不進入 abandon／reminder／lost due 查詢。
- `run_once` 以 exact automation＋contact 跨一般與 Cart 來源生效（含舊版本與其他 Cart）；
  未開啟時各來源仍各自獨立。來源去重仍先於 `run_once` 判斷。
- legacy `kind=cart` 的 `queued`／`sending` 仍是當前版本的唯一 owner；任何版本的 `sending`
  都是 worker 已 claim 的 handoff，永久阻擋新 owner 且不取消；`accepted`／`unknown`
  保留原 ID 與狀態，不重送。只有舊版本且未 dispatch 的 `queued` 會取消。
- 新流程只產生 `kind=automation` Job，並帶 `cart_id`、來源版本、Run 與 Step。Queue 對
  `automation` 且來源為 `cart:` 的 Job 套用 Cart gate，並把 `suppressed`／`failed`／`unknown`／
  `accepted` 回寫到對應 Run Step 與 Cart 事件。
- Queue 對 legacy `kind=cart` 與新 Cart Job 都在短暫 per-cart 鎖內重驗 claim、Cart 狀態、
  來源版本與收件人，通過後才釋放鎖並交給 transport；鎖忙只延後且不消耗 attempt。授權後
  的 `sending` 由 worker 擁有，建單或取消不得覆寫。Cart 與 campaign 的 `wp_mail` 失敗、
  以及 lease 過期，一律記為 `unknown`，不自動重試。
- Step 顯示 completed 只代表 Job 已排入。`accepted` 是 transport handoff，不是送達。

Campaign 在 schedule／start 當下凍結內容快照與受眾上限（最大 contact ID），寄送中使用
快照，避免編輯中內容影響已排程郵件。`sent` 由 sweep／worker 在佇列歸零後標記。

Campaign 的 Gutenberg 編輯器由 `src/campaign-editor/` 的 TypeScript 外掛提供，使用
`PluginSidebar`、`wp-components`、`wp-api-fetch`；PHP 的 `nowcrm/v1/campaigns/{id}/*`
REST 路由負責權限、欄位驗證與持久化。Raw HTML 模式改用 classic screen 的 PHP meta box，
兩個模式共用 `persist_fields()` 與 `wp_slash()` 的 JSON 寫入規則。受眾以 include／exclude
的 list＋tag 規則表示：同一列為 AND、列與列為 OR，排除條件取 NOT；只寄給 `subscribed`。

## 5. 安全邊界

- 所有後台變更需 capability＋nonce；`admin_post_nowcrm_*` 只在 `is_admin()` 註冊。
- 公開端點只有 open／click／cart track／SES SNS；open／click 需 HMAC token，SNS 需 Topic
  allowlist＋簽章。cart track 只讀目前 Woo session 的真實購物車，需 same-origin／nonce，並以
  session 驗證 cycle 擁有權；client 提交的 cycle、cookie 或 email 不能改寫其他購物車。
- 購物車還原先驗證全部商品、變體、庫存與 coupon 再替換；失敗會放回原購物車與有效 coupon。
  過期、重用或跨擁有者的 recovery token 不重播，token 也不能用來登入。
- 後台不輸出 recovery token 或 session；Run 與 Job 詳情只顯示 allow-list 狀態、ID 與原因。
- 退訂 GET 只顯示確認頁，POST 才變更；token 只存 hash，支援 one-click POST。
- 不寫入 FluentCRM `fc_*`；匯入只讀，且不會把 suppressed 狀態放寬。
- 金鑰、正式資料、測試 DB 密碼不進 Git。

## 6. 已知限制

- 開信像素會被 Apple／Gmail 預取，只作趨勢；唯一開信不是閱讀證明。
- `wp_mail()` 無法取得 SES MessageId；SNS 事件必要時以收件地址＋近期 job 比對，報告標註
  `matched_by`。
- WP-Cron 若被停用，需系統 cron 或 `wp action-scheduler run`；本機可用後台「處理一批佇列」。
- HPOS 與傳統儲存都走 WC CRUD，但上線前仍需以實際訂單驗收。
- 購物車流程沒有定義快照。對進行中 Run 修改 owner 流程的步驟，可能改變或重指後續 Step；
  結構性編輯前先暫停流程，或等 Run 結束。這不是 versioned definition。
- `Engine` 的 `GET_LOCK` 是站台層互斥，不是 per-run lease。程序在 `wp_mail()` 前後 crash 時，
  Job 可能停在 `sending`，lease 過期後成 `unknown`；不可自動重寄。
- legacy `kind=cart` 的 `accepted`／`unknown` 會持續阻擋新 owner；這是保守設計，需要人工查核，
  不可用 SQL 清除。
- 還原只支援可安全重建的商品與 coupon。含不透明第三方 cart item data、失效變體或庫存的
  購物車會拒絕還原並保留目前購物車；不保證每台購物車都能回復。
- 付款只採 Woo 內可核對事實（正數 `date_paid` 加付款確認標記或 transaction ID）。離線付款、
  延遲回呼與失敗重試可能長時間停在 `order_pending`；這不是支付商對帳，也不代表款項爭議已解決。
