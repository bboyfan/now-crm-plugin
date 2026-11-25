# NOW CRM

NOW CRM 是一款自架式 WordPress CRM 外掛。你可以在同一個後台管理聯絡人、寄送電子報、建立自動化流程與表單。啟用 WooCommerce 後，也能同步訂單、寄送購物車提醒信。

CRM 資料儲存在自己的 WordPress 資料庫，不必另外申請雲端 CRM 帳號。外掛不會把使用統計或聯絡人資料回傳給開發者。**目前版號為 `1.1`。**

## 主要功能

| 功能 | 可以做什麼 |
| --- | --- |
| 聯絡人管理 | 用名單、標籤與分眾整理聯絡人。支援 CSV 匯入、自訂欄位、備註與狀態歷程。 |
| 電子報 | 用 WordPress 區塊編輯器或自訂 HTML 編寫郵件，支援預覽、測試信、排程與定期寄送。 |
| 範本、序列與自動化 | 重複使用郵件範本、安排多封信的寄送順序，或依事件觸發流程。 |
| 表單與訂閱 | 建立表單、收集填答，並設定雙重確認訂閱（double opt-in）與一鍵退訂。 |
| WooCommerce 訂單同步 | 依帳單電子郵件地址連結訂單與聯絡人。支援傳統訂單儲存與高效能訂單儲存（HPOS）。 |
| 放棄購物車提醒 | 可針對放棄結帳的購物車寄送提醒信。顧客可透過有期限的連結還原購物車；提醒信內容在自動化的 Email 步驟編輯。 |
| 寄送紀錄與整合 | 透過佇列控管寄送與重試。可選用 Amazon SES／SNS 回報寄送事件，或用 Webhook 與其他系統交換資料。 |

## 系統需求

| 項目 | 需求 |
| --- | --- |
| WordPress | 6.7 以上 |
| PHP | 8.3 以上 |
| WooCommerce | 8.0 以上，僅訂單同步與購物車功能需要 |
| 郵件傳輸 | 寄送實際郵件時，站台需有可用的 WordPress 郵件傳輸方式 |

沒有安裝 WooCommerce，仍可使用聯絡人、電子報、自動化與表單。Node.js 和 npm 只在從原始碼建置時需要，安裝外掛 ZIP 的站台不需要。

## 安裝與開始使用

先從原始碼建置安裝 ZIP。請勿直接把 GitHub 的 **Download ZIP** 當成外掛安裝檔。

```bash
git clone https://github.com/bboyfan/now-crm-plugin.git
cd now-crm-plugin
npm ci
npm run build
bash bin/build-zip.sh
```

1. 在 WordPress 後台開啟 **外掛 → 安裝外掛 → 上傳外掛**。
2. 上傳建置產生的 `dist/now-crm-1.1.zip`，安裝並啟用。
3. 開啟 **NOW CRM** 選單。可以先新增一位聯絡人、建立名單，再到 **電子報** 預覽草稿。

啟用時只會建立必要的資料表、預設設定與權限。全新安裝預設不會同步 WooCommerce 訂單、自動將新顧客設為已訂閱、寄送實際郵件、擷取放棄購物車資料或追蹤電子報開信與點擊。

想先測試寄信流程時，可把郵件傳輸設為 **Capture（只寫入本機紀錄，不寄出）**。要正式寄送時，請先確認站台的郵件傳輸可用。`wp_mail()` 回傳成功只表示郵件交給了傳輸系統，不代表已送達收件匣。

## 資料、隱私與第三方服務

- **資料儲存與保留：** 聯絡人、訂單關聯、郵件事件、購物車摘要及表單填答都儲存在站台資料庫。支援 WordPress 個人資料匯出，以及透過 `wp nowcrm email retention` 管理歷史郵件內容的保留。停用或刪除外掛不會自動刪除 CRM 資料。
- **顧客訂閱：** 可選擇在 WooCommerce 結帳頁顯示「訂閱電子報」選項。同步訂單不會變更既有聯絡人的訂閱狀態，匯入舊資料也不等於取得新的行銷同意。
- **購物車與郵件追蹤：** 啟用購物車擷取後，外掛可在訂單送出前記錄結帳電子郵件地址與購物車內容。啟用相關功能前，請依站台的隱私告知和同意流程處理。郵件服務可能預先載入追蹤像素，因此開信數不等於實際閱讀人數。
- **外部連線：** 郵件由站台設定的傳輸方式寄出；Amazon SES／SNS 和 Webhook 均須另外設定。各項服務的用途、啟用條件、資料項目與適用政策，請見 [`readme.txt`](readme.txt) 的 **External Services**。

外掛提供訂閱、退訂與追蹤控制，不保證符合法規；站台經營者仍須決定如何取得同意、告知用途與保留資料。

## 搬移既有資料

可透過 WP-CLI 從 FluentCRM 唯讀匯入聯絡人、名單與標籤，也能補齊 WooCommerce 歷史訂單關聯。啟用外掛不會觸發匯入。請先備份，並在測試站執行 `--dry-run` 核對結果，再套用到正式站。操作步驟見[維運與匯入手冊](docs/operations.md)。

## 開發與測試

前端原始碼在 `src/`，使用 `@wordpress/scripts` 建置。修改前端原始碼後，請重新建置並一併提交 `assets/build/` 的變更。

```bash
npm ci
npm run check-types
npm run test:unit
npm run build
```

PHP 測試需另外準備 WordPress 測試程式庫、WooCommerce、PHPUnit、Docker 環境及可銷毀的測試資料庫。執行 `tests/bin/run-tests.sh` 前，請先依[測試指南](docs/testing.md)準備環境。資料模型、寄送佇列與 Multisite 支援範圍見[架構說明](docs/architecture.md)。

## 問題回報與貢獻

- 一般問題、功能建議請開 [GitHub Issue](https://github.com/bboyfan/now-crm-plugin/issues)。回報問題時，請附上 NOW CRM、WordPress、PHP 與 WooCommerce 版本（如有），以及重現步驟和預期結果。
- 發現安全問題時，請透過 [GitHub 私密漏洞回報](https://github.com/bboyfan/now-crm-plugin/security/advisories/new)，不要發在公開 Issue。請勿在公開回報中貼出客戶資料、電子郵件地址或金鑰。
- 歡迎透過 Pull Request 提交修正。請說明修改原因與驗證方式。

## 授權

NOW CRM 採 **GPL-2.0-or-later** 授權。詳見 [`LICENSE`](LICENSE)。

Copyright (C) 2026 WENSZU.
