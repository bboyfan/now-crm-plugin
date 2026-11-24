# NOW CRM — 操作、匯入與切換手冊

本文件描述可在本機／Staging 執行的操作。**正式站需要另外授權**；不要在未備份、未確認
FluentCRM 自動化的情況下直接切換。

## 1. 啟用與升級

1. 備份資料庫。
2. 若從原始碼部署，先建置前端資產：`npm ci && npm run build`。`assets/build/` 已進版控，
   直接使用 Release ZIP 時不需要 Node；但 `campaign-editor.asset.php` 或
   `cart-track.asset.php` 缺失時，對應的 Gutenberg 面板／購物車追蹤不會載入。
3. 上傳 ZIP 或部署 `plugins/now-crm`，啟用外掛。
4. 開啟任一後台頁面，`Schema::maybe_upgrade()` 會建立或更新資料表。目標版本由
   `Schema::VERSION` 定義，可在 **NOW CRM → 工具 → 診斷** 核對已安裝版本與目標版本。
5. 檢查 `NOW CRM → 總覽` 與 `NOW CRM → 設定` 沒有 PHP 錯誤。若懷疑資料表缺失，到
   `NOW CRM → 工具` 按「檢查並修正資料表」；一般請求不會逐一 `SHOW TABLES`。

Schema `2.5.0` 新增寄送與郵件事件報表索引。大型資料表請先備份並安排維護時段，避免索引建置影響正常操作。
Schema `2.8.0` 新增每次寄送的 SES 關聯欄位、回條處理狀態，以及個資匯出用的收件地址索引。

升級不會刪除舊表、不會自動匯入、不會自動寄信。

## 2. FluentCRM 匯入

先備份。**NOW CRM → 工具 → FluentCRM 遷移指引** 提供指令，不會執行遷移。遷移只透過
WP-CLI，沒有後台表單或 `admin-post` 動作；舊的 `tab=migration` 網址仍回到診斷分頁。

「先 dry-run、再 apply」是操作要求，CLI 不會強制；`--apply` 會在該次指令執行中立即寫入，
並依 `--batch` 分批處理到完成。需要查看已記錄的執行時使用 `wp nowcrm import status`，
不要重跑 apply 當成進度查詢。

1. 執行 `wp nowcrm import fluentcrm --dry-run` 預演；不會寫入聯絡人。需要 campaign 歷史時，
   dry-run 與 apply 都加上 `--campaigns`（dry-run 只計數，apply 才寫入 archive）。
2. 確認聯絡人新增、更新、略過與失敗的計數後，執行 `--apply`；同一次指令會分批寫入到完成。
3. 若中斷，對同一模式加 `--resume`，從 `import_runs.cursor_value` 接續最近一筆未完成的
   執行；復原 apply 時使用 `--apply --resume`。完成後不需要再重跑。

需匯入歷史電子報或大量資料時，同樣使用 CLI；正式站操作仍需另行授權。

```bash
# 預演（不寫入聯絡人；--campaigns 只計算 campaign archive）
wp nowcrm import fluentcrm --dry-run --campaigns

# 正式套用（同一次執行內分批寫入到完成）
wp nowcrm import fluentcrm --apply --batch=500 --campaigns

# 僅在套用中斷後接續該筆未完成的執行；沿用相同的 --campaigns
# （Importer 每次執行重新讀取，並在聯絡人之後封存）
wp nowcrm import fluentcrm --apply --batch=500 --campaigns --resume

# 只查狀態，不會觸發匯入
wp nowcrm import status
```

- 只讀 `fc_subscribers`、`fc_lists`、`fc_tags`、`fc_subscriber_pivot`、`fc_campaigns`、
  `fc_campaign_emails`；不寫入、不修改 FluentCRM。
- 狀態映射：`pending` → `transactional`、`spammed` → `complained`；其餘五狀態照實匯入。
- 既有 `unsubscribed`／`bounced`／`complained` 不會被匯入放寬。
- 中斷後用 `--resume` 接續最近一筆同模式未完成的執行；完成後重跑 apply 冪等，不會重複建立。
- `--campaigns` 只備份 campaign 內容與開啟／點擊統計，不搬移背景排程筆記。

### Woo 歷史缺口

```bash
wp nowcrm import woo --dry-run
wp nowcrm import woo --apply
```

切換驗收用下列唯讀對帳；兩個指令都只輸出彙總，不列 Email、訂單明細或客資。
`--limit` 可先限縮掃描量；沒有指定時才用完整資料核對。

```bash
wp nowcrm import woo --audit
wp nowcrm cart audit
```

第一個區分找不到聯絡人、缺訂單關聯與聯絡人／關聯不一致；第二個分開計算棄購提醒資格、
精確連結的訂單是否有付款證據，以及已完成但找不到證據或已付款尚未完成的差異。

沒有 CRM 主檔的 Woo Billing Email 會建立為 `transactional`，**不會**訂閱、不會取得行銷
資格；同時補上訂單關聯。

### 預期對帳

匯入前記錄來源的聯絡人、名單、標籤與活動數量。匯入後以 `wp nowcrm import status`
與 `wp nowcrm status` 核對，並確認 WooCommerce 對帳中沒有非預期缺漏。

### 新訂閱與偏好名單

- 同步不改寫既有聯絡人的退訂／抑制狀態；歷史回填不代表取得行銷同意。
- Woo 結帳欄位在 Classic 與 Blocks 預設不勾選，未明確勾選不訂閱。若顧客名單
  要求雙重確認，只有主動勾選的新顧客會排確認信，確認前是僅交易信。只在沒有記錄
  選擇、沒有顯示欄位且不要求雙重確認時，才套用店家另外啟用的 Woo 自動訂閱政策。
- 原生表單必須勾選該表單的雙重確認，且全域或名單政策判定需要，才對新聯絡人排確認信。
  聯絡人 CSV 的新記錄也會核對政策；FluentCRM 歷史遷移不寄確認信。
- 未設定時偏好中心不列出名單。若要開放，先用「只公開勾選的名單」並核對名單內容；
  已明確儲存的「全部名單」不會被升級改寫。

## 3. SES SNS

1. 在 AWS SES 建立 Configuration Set，事件目的地選 SNS Topic，並同時選
   `Delivery`、`Bounce`、`Complaint`。只建立 Set 和 Topic 不會讓既有寄送自動發佈事件。
2. SNS Subscription 指向 `https://<site>/wp-json/nowcrm/v1/ses/sns`，保留 SNS 簽署的
   通知格式，不使用 Raw Message Delivery。
3. `NOW CRM → 設定 → 寄送與 SES` 填入允許的 Topic ARN（可用逗號分隔）。
   要由 NOW 指定 Set 時，填 **SES Configuration Set**；留空則由 SES 寄件身分的
   預設 Set 決定。確認實際 `wp_mail` 路由及寄件身分有使用這個 Set，`capture` 不會對外寄信。
   NOW 不配置 SMTP 帳號或 AWS 憑證。
4. 端點先核對 Topic 與 SNS 簽章，再確認訂閱或處理回條。NOW 寄送時標記個別交接嘗試。
   只有簽署回條中的標記、收件人與 NOW Job 完全相符，才更新該 Job 或因硬退信／客訴
   抑制該地址。共用 Topic 上未配對的通知僅列入診斷，不改聯絡人。
   升級前已交付、沒有關聯標記的 Job 不會回填送達或自動抑制；不要為了補歷史回條重寄。
5. 在 `NOW CRM → 工具 → 診斷` 確認已安裝 Schema 和目標一致。經授權後在 Staging 用
   SES Mailbox Simulator 寄三封獨立測試信，逐筆核對 `Delivery`、`Bounce`、`Complaint`
   回到對應原 Job；確認退信與客訴的抑制結果，以及寄送紀錄 → 診斷沒有未解的未配對或失敗。
   只跑本機 fixture 不等於確認實際 SES 寄送路徑或公開 SNS 驗簽。

佇列狀態與送達狀態是兩層事實，不可互相取代：

- **佇列狀態**（`queued`／`sending`／`accepted`／`failed`／`suppressed`／
  `unknown`／`cancelled`）由外掛的 worker 與 transport 寫入。`accepted`（介面顯示
  「已送出」）只代表 transport 接受交接，不能稱為已送達。
- **送達狀態**（`delivered`／`bounced`／`complained`／`deferred`）由已配對的 SES SNS
  Delivery／Bounce／Complaint 事件寫入。`delivered` 表示收信伺服器接受，不證明進收件匣。
  「尚未回條」不代表成功或失敗。回條不會改變佇列狀態或 campaign 的 `accepted` 口徑。

無 AWS 憑證時，可用本機 Fixture 驗證處理器：

```bash
wp nowcrm ses simulate --file=/tmp/bounce.json
```

（`simulate` 直接呼叫 Processor，不驗簽；公開端點仍必須驗簽。）

## 4. Campaign 操作

1. `NOW CRM → Campaigns → 新增 Campaign`。
2. 選擇 Gutenberg 或 Raw HTML；填主旨、preheader、寄件者、footer。
3. 選受眾（全部已訂閱／Lists／Tags）。
4. 儲存後：
   - **預覽**：用目前使用者資料 render；
   - **測試寄送**：寄到指定地址（仍需 `wp_mail` transport）；
   - **排程**：依站台時區設定時間；
   - **立即寄送**：建立收件人 job 並喚醒 worker。
5. 寄送中可暫停／恢復／取消；取消會把 queued job 標為 cancelled。
6. 佇列由 Action Scheduler／WP-Cron 推進；本機可用 `NOW CRM → 寄送佇列 → 處理一批佇列`
   或 `wp action-scheduler run --group=nowcrm`。

寄送前會重新檢查 `subscribed` 與抑制名單；已退訂／退信／投訴者不會收到。

## 5. 購物車放棄

本節是分段操作手冊。只在本機／Staging 以 `capture` transport 與一次性資料庫演練；正式站的
啟用、升級、真實寄送、遷移與部署必須另外授權。不要用正式資料測試本節步驟。

### 5.1 狀態與責任邊界

- 提醒只有一個寄送 owner：購物車自動化。`Sweeper` 只做狀態轉換與到期補送，不再建立
  `kind=cart` 提醒；新流程不產生 `kind=cart`。
- 預設 owner 是受管理的單一流程（`cart_automation_id=0`）：一個 Email 步驟、沒有 Wait。
  提醒內容在該流程的 Email 步驟編輯。系統只在需要時建立流程；之後即使被暫停或編輯，也不會自動替換。
- 也可以選一個明確流程：必須是 `active`、觸發事件為 `cart_abandoned`、且至少有一個 Email
  步驟。設定頁只列合格流程。
- 選取失效時（流程暫停、刪除或失去 Email 步驟），系統保留該選擇、顯示警告，並在購物車活動
  紀錄記 `not_eligible`／`owner_unavailable`。**不會默默改回預設流程**；店家必須修正流程或改回
  預設。
- Cart Email 的寄送資格只有 `subscribed` 與 `transactional`。新訪客聯絡人建立為
  `transactional`，只授權購物車提醒，不是行銷同意；一般 Campaign／自動化／Sequence 仍只寄
  `subscribed`。`pending`、退訂、bounce、complaint、spammed 與全域 suppression 一律阻擋，
  `force_run` 也不能繞過最終寄送檢查。
- 預設：閒置 20 分鐘轉 `abandoned`、放棄後延遲 0、同 Email 冷卻 3 天、5 天轉 `lost`、
  提醒上限 1 封、還原連結 7 天。
- 「放棄後延遲」由 `carts.reminder_due_at` 套用一次。自訂流程若另外加入 Wait，那是流程的
  第二步延遲，會再加時間。

### 5.2 每次放棄的唯一路徑

1. Checkout 只在 Woo session 內捕捉。伺服器讀真實購物車；client 的 cycle、cookie 或 email
   不能改寫其他購物車。
2. 只有伺服器回 `ok=true` 才算捕捉成功。暫時失敗（網路、`no_session`、429）以相同
   `activity_order` 有上限重試；`invalid_nonce`、`empty_cart`、`stale_cycle`、`disabled`
   不自動重送，等下一次真實觀察或回頁再處理。舊觀察永遠不會覆寫較新的觀察。
3. 清空或改成無效 Email 時，前端送 `email_state=none` 到同一端點。伺服器在既有 cycle 與
   Session 內清空該車地址、取消該車待寄 Job 並移除 `reminder_due_at`；不會全域退訂，也不會
   動到其他購物車。改址同樣會令舊 Job 失效。
4. 使用者在 Checkout 實際操作時，前端最多每 60 秒送一次 `kind=activity` 心跳；伺服器只在
   同一 Session、較新的 `activity_order` 與 Active 狀態下延後 `last_activity_at`，不 bump
   version。已 abandoned 的車由既有的重返路徑轉回 Active 並取消舊 Job。
5. `Sweeper` 以版本＋cutoff 原子轉 `abandoned`。只有轉態成功的那一次發 `nowcrm_cart_abandoned`。
6. `Cart\Automation::start_for_cart()` 在購物車鎖內依 `cart:<cart_id>:<version>` 找既有 Run；
   存在就重用。同 Email 的另一台購物車有獨立 Run。
7. 建立 Run 前先檢查 legacy `kind=cart` Job 的 fence，再解析 owner。owner 不合格就記原因並停止。
8. Engine 到期執行 Email 步驟，建立 `kind=automation` Job，帶 `cart_id`、來源版本與 Run 關聯。
   Step 顯示 completed 只代表「Job 已排入」，不代表已寄達。
9. Queue 寄送前做最後一次 gate：Cart 仍 abandoned、停用狀態、版本／Email 未變、owner 仍有效、
   Run 仍 active、未付款、無待付款訂單、未 lost、未超過上限／冷卻、狀態符合 Cart 政策
   （`subscribed` 或 `transactional`）、未 suppression。
   失敗會記機器原因，不默默丟棄。
10. 寄送後由 Queue 回寫 Job 結果；只有真正的 handoff 才更新 Cart 的提醒計數。

### 5.3 Job 結果的意義

| 狀態 | 意義 | 操作 |
|---|---|---|
| `queued` | 已排入，尚未 claim。 | 等待 worker；可取消來源。 |
| `sending` | worker 已 claim，帶 lease。 | 不要重送；lease 過期會轉 `unknown`。 |
| `accepted` | transport 已接受（handoff）。 | 不是送達、不是開信、不是點擊。 |
| `suppressed` | 寄送前 gate 拒絕，附原因。 | 修正資格或政策，不要重送同一 Job。 |
| `failed` | 可重試的失敗。 | 依既有重試上限與佇列規則處理。 |
| `unknown` | 結果不明（crash、timeout、lease 過期）。 | **不自動重寄**；先查核，必要時由人工決定。 |
| `cancelled` | 來源失效、付款、lost 或人工取消。 | 保留紀錄，不恢復同 Job。 |

`accepted` 只代表 `wp_mail()`／transport 接受；不可在文件中寫成「已送達」。開信與點擊是個別事件。

### 5.4 Legacy `kind=cart` Job

- 舊版排入的 `queued`／`sending` Job 保留原 Job Key、ID 與狀態，仍是該購物車版本的唯一 owner；
  新自動化不會同時建立提醒。
- 舊版本（較舊 `cart_version`）且尚未 dispatch 的 `queued` Job 會被取消；這是可證明的未寄出狀態。
- `accepted`／`unknown` 是保守 fence：不刪除、不取消、不重送。購物車活動紀錄會顯示
  `legacy_owner`。
- 不要在資料庫手動改寫 Job 狀態或刪除歷史。需要處置時，透過後台與既有 CLI 操作，並記錄決定。

**升級前的舊 Job 檢查：**

- 升級前由購物車 Trigger 產生的舊 `kind=automation` Job 可能沒有 `cart_id`、來源鍵或 recovery
  context。若精確的舊 Run 可證明 Cart 來源、但缺少可信的來源或 recovery context，Queue 會
  suppressed 並記原因；不要把它當成可寄。
- 舊工作樹可能已覆寫或刪除 Run，因而失去不可變證據。沒有證據的 Job 不能推定來源，也不能
  宣稱已解決或可自動重試。
- 啟用新 Cart owner 前必須先做 pre-upgrade drain／hold：暫停寄送或佇列、停止新 sweep，列出
  unmatched／unknown 與缺少可信來源的舊 Job，逐筆人工檢視。未完成前不要啟用。
- 先演練 suspended／queue disabled 狀態，確認暫停與停用期間不會寄出；恢復後仍受 gate 約束。
  `unknown` 不會因升級、重跑或恢復而自動解決。

### 5.5 Woo 訂單、付款與退款

- 建單只進 `order_pending`，取消未寄 Job；此時不算挽回。
- 只有同時具備下列兩項才算 paid：正數 Woo `date_paid`，且存在 `_nowcrm_payment_confirmed`
  （`woocommerce_payment_complete` 寫入）或非空 transaction ID。**所有付款方式同一規則**。
- 只有 status、或 Woo 自行合成的 `date_paid`，都不是付款證明。COD／BACS／cheque 會停在
  `order_pending`，直到精確付款回呼或交易證明。
- 失敗／取消：保持 `order_pending`，記 `order_failed` 原因，取消 queued Job；不復活已取消工作。
  同一訂單之後的精確付款確認仍可結案。
- 退款：保留付款證據，記 `order_refunded` 與原因。報表仍用既有 counted／net `order_links` 事實，
  不把未付款購物車金額當成營收。
- 同 Email 的其他訂單不可完成這台購物車。只有 `_nowcrm_cart_linked` 指到的購物車可被處理。
- 這套規則是 Woo 內可核對的付款事實，**不是**外部支付商對帳。

### 5.6 歸因分層

依序分開記錄：`recovery_click` → `cart_restored` → Job `queued`／`accepted` → `order_placed`
→ `order_paid`。

只有精確連到購物車、且有付款證據的訂單才計入「郵件連結挽回」。自然完成另計；不同幣別不
合計。不要把「點擊連結」寫成「已挽回」，也不要把「已建立訂單」寫成「已付款」。

### 5.7 還原連結

- Token 只存 hash、有期限；只用於還原，不用於登入。
- 先 preflight：商品存在、變體關係與屬性、庫存、限量、數量、coupon 是否可重播。
- 含有不透明外掛 cart item data 的商品會拒絕還原並回報原因，不宣稱成功。
- 還原失敗要保留目前購物車，包括有效 coupon 與第三方 line data；不可先清空再失敗。
- 過期、重用、跨擁有者的 token 不重播；只有精確 row 與 version 可被接手。
- 後台不顯示 recovery token 或 session；不要把 token 貼進工單、文件或截圖。

### 5.8 啟用（本機／Staging，capture）

前置：

1. 確認一次性資料庫與 `capture` transport。寄送總開關可先關閉。
2. `NOW CRM → 工具 → 診斷` 顯示已安裝 Schema 與目前目標一致。未升級時停止，不要切換 owner。
3. 備份資料庫（prerelease backup）。
4. 確認沒有 legacy `kind=cart` `queued`／`sending` Job 屬於要測試的購物車；若有，先依 5.4 理解
   fence，這是預期阻擋。
5. 先完成 5.4 的 pre-upgrade drain／hold 與 unmatched／unknown 清單。缺少可信 Cart 來源的舊
   Job 未人工檢視前，不要啟用新 owner。
6. 演練 suspended／queue disabled：確認暫停與停用時不寄出，恢復後仍受 gate 約束；不要假設
   `unknown` 會自動解決或重試。

步驟與手動檢查：

1. `NOW CRM → 購物車放棄 → 設定與提醒`：勾選啟用；閒置 20、延遲 0、lost 5、冷卻 3、
   上限 1、還原 7。owner 留空（預設）或選合格流程後儲存。
2. 以測試顧客在 Classic 或 Blocks checkout 留下商品與 Email；確認列表出現該購物車。
3. 在一次性 fixture 把 `last_activity_at` 設為 19 分 59 秒前，執行 `wp nowcrm cart sweep`：
   狀態仍 active，沒有 `abandoned` 事件。
4. 在一次性 fixture 設為 20 分前，再 sweep：轉 `abandoned`，只有一筆 `abandoned` 事件；詳情出現一筆
   `自動化執行`。重跑 sweep：不新增第二筆事件或 Run。
5. `NOW CRM → 自動化`（`nowcrm-automation&id=<automation_id>`）確認 owner 是啟用中的
   `cart_abandoned` 流程；`nowcrm-automation-runs&id=<automation_id>&run_id=<run_id>` 顯示
   來源 `cart:<id>`、購物車連結、步驟事件與 Job。
6. 執行佇列（`wp action-scheduler run --group=nowcrm` 或後台處理一批）。Job 由 `queued` 到
   `accepted`；`NOW CRM → 寄送紀錄`（`nowcrm-email&cart_id=<cart_id>`）可查到。
   確認 capture 有內容、Cart 提醒計數只增加 1。
7. 再讓同一購物車產生活動：舊來源的未寄 Job 變 `cancelled`；不建立新版本的重複 Run。
8. 同 Email 建第二台購物車：各有獨立 Run，互不取消。
9. 建單但不付款：Cart 轉 `order_pending`，未寄 Job 取消，訂單連結出現；不可顯示已挽回。
10. 以有證據的付款完成：轉 `completed`（自然）或 `recovered`（`email_click`），`order_paid`
    只記一次；重複 callback 不重複計。
11. 把 owner 流程暫停，再放棄新購物車：沒有 Run，活動紀錄為 `not_eligible`／`owner_unavailable`，
    設定頁顯示警告；不會自動回預設。
12. 以自訂流程測試時，確認只在流程明確定義 Wait 時才多等；沒有 Wait 時 20 分鐘後即可排 1 封。

以上是驗收步驟，不是已執行結果。沒有實際輸出就記 **NOT RUN**。

### 5.9 停用與回復

1. 在 `NOW CRM → 購物車放棄 → 設定與提醒` 取消「啟用購物車捕捉與提醒」並儲存。這會停止新的
   sweep、Run 與提醒。
2. 要立即停止寄送時，關閉寄送總開關或暫停 owner 流程。不要直接刪除 queue 資料表。
3. 保留 `carts`、`cart_events`、`automation_runs`、`automation_run_events`、`email_jobs` 與 legacy
   Job 的 ID、狀態與事件。不要用 SQL 刪除、改寫或重送。
4. 停用後再取消或等待既有 queued Job；Queue 仍會以 `cart_disabled` 等 gate 阻擋，但要實際核對。
5. 回舊版前先確認是否已有多個 `cart:<id>:<version>` 來源 Run。**多次來源 Run 之後不支援原地
   降級**；新舊 schema 不可在同一資料庫直接互換。
6. 需要回到 prerelease 備份時，只在 Staging 執行，並把它當作需另外授權的「安全分段復原」；
   保留可核對紀錄。不可在正式站直接降級或還原未經批准的備份。
7. 舊 `kind=cart` 歷史保留。回舊版後若仍有新 Automation Job，先取消或等它結束，避免雙寄。

### 5.10 Schema 2.6.0

- 2.6.0 是增量升級：`automation_runs` 增加購物車來源身分（`cart_id`、來源版本／來源鍵）與
  對應唯一鍵，讓同 Contact 的多台購物車各自有 Run。
- 舊 `(automation_id, contact_id)` 唯一鍵只在新唯一鍵建立並驗證後才替換。`dbDelta` 不保證
  刪除舊索引；升級要能重試，且不得刪除既有 Run／Event 來換表。
- 這是歷史遷移；啟用或切換流程前，確認已安裝 Schema 與目前版本目標一致。
- 需要核對索引時只用唯讀查詢（例如 `wp eval` 的 `SHOW INDEX FROM {$wpdb->prefix}nowcrm_automation_runs`）；
  不要在資料庫手動 `DROP INDEX`。新唯一鍵不存在就停止並還原備份。
- 2.6.0 也新增大型站台用的聚合索引：Cart 狀態／日期與事件期間查詢（canonical 名稱
  `status_activity`、`status_abandoned`、`event_type_time`）。索引建置可能需要維護時段；
  先做 staged 備份並安排維護窗口，本次沒有執行任何部署。
- 升級後不可原地降級到舊 schema。降級只走 5.9 的備份路徑。

## 6. Woo processing 交接（Staging）

歷史稽核紀錄：Woo 原生 processing Email **OFF**、FluentCRM 處理中通知 **ON**。
切換前要重新讀回現行設定；此紀錄不能證明正式站目前的寄送 owner。

切換前：

1. 在 FluentCRM 找到 Woo 訂單觸發的「processing」自動化，確認目前狀態與寄送對象。
2. 暫停該自動化（不要刪除），記下設定。
3. 到 `NOW CRM → 工具 → Woo 交接` 檢查兩邊狀態，確認後啟用 Woo 原生處理中信。
   偵測到已發布的已知 FluentCRM processing 流程時會阻擋；「未偵測到」不涵蓋自訂
   觸發器、其他外掛或已在佇列中的信，仍需人工核對。
4. 下一筆測試訂單確認：
   - 只收到一封 processing 信；
   - 商品、金額、付款方式、物流資訊正確；
   - FluentCRM 沒有同時寄出。

回復：

1. `NOW CRM → 工具` 停用 Woo 原生 processing Email；先確認替代流程準備好接手，
   避免訂單在切換空檔觸發。
2. 重新啟用 FluentCRM 自動化。
3. 用測試訂單確認只收到一封。

若發現雙寄，立即停用其中一個 owner，檢查兩邊的 queue 與自動化紀錄。

## 7. 上線前檢查

- [ ] 資料庫與 FluentCRM 已備份
- [ ] `wp nowcrm import fluentcrm --dry-run` 數字與 Audit 一致
- [ ] 正式匯入後狀態／Lists／Tags 對帳
- [ ] Woo 缺口、棄購資格與實際付款歸因完成唯讀對帳
- [ ] Woo 訂閱來源與公開名單核對，沒有因回填改成已訂閱
- [ ] 已安裝 Schema 與目標一致；確認實際寄件身分套用 Configuration Set、SNS allowlist 與訂閱
- [ ] 受控測試已核對 Delivery、Bounce、Complaint 各自的原 Job，及診斷／抑制結果
- [ ] Woo processing 單一 owner 切換與回復演練
- [ ] WP-Cron／Action Scheduler 實際運行（非僅手動）
- [ ] 寄送速率與 SES 配額確認，保留 Woo 交易信容量
- [ ] ZIP 安裝／升級／停用／rollback 演練

## 8. 資料匯出與保留

使用 WordPress → 工具 → 匯出個人資料。NOW CRM 依請求 Email 匯出聯絡人主檔、
自訂欄位、名單／標籤、同意與狀態、訂單關聯、寄送與追蹤、購物車摘要、填答、
SES 回條摘要及抑制資料。匯出不含可使用的權杖、簽署網址、完整郵件或 SNS 原始資料、
購物車快照、內部操作人或員工筆記。這不是資料刪除功能。
訂單關聯只依目前聯絡人的 ID 匯出，不以帳單 Email 取得其他聯絡人的訂單。

```bash
# 預演，預設 90 天，只列可清理數量
wp nowcrm email retention --days=90
# 核對預演、備份並獲准後，每個資料來源至多清理一批
wp nowcrm email retention --days=90 --batch=200 --apply
```

只清除超過期限的已交接／已取消 Job 內容及已處理 SNS 原始 payload。保留 Job 與
事件 ID、回條去重、退訂、追蹤、報表與歸因關聯；保留待寄、寄送中、失敗／未明或仍
可能操作的 Job，以及未處理 SNS 原始資料。清理後部分郵件不再能預覽原文。
不帶 `--apply`（或給 `--apply=false`）時只預演。工具不排程、不刪除事件列；
若還有符合的資料，重新預演後再分批處理。
