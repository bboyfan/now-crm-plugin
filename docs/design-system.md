# NOW CRM 設計基準：Contacts 列表與詳情

本文件是 NOW 後台唯一適用的設計基準（口語的 `design.md`）。視覺方向沿用 Executive
Monochrome：Inter／系統字、白底、1px hairline、4px 控制項圓角、狀態色只用於狀態；開工
不需要外部檔案，若舊筆記或截圖與本文件衝突，以本文件與實際程式為準。

**基準來源**

| 畫面 | PHP owner | CSS | 資料 |
|---|---|---|---|
| Contacts 列表 | `includes/Admin/ContactsPage.php` | `assets/css/admin.css` | `ContactRepository`、`TermRepository`、`Status` |
| Contacts 詳情 | `includes/Admin/ContactDetailPage.php` | `assets/css/admin.css`＋`assets/css/contact-detail.css` | `ContactRepository`、`ContactNotes`、`ContactEmailPanel` |
| 聯絡人分區導覽 | `ContactsPage::render_nav()`（列表、名單、標籤、分眾共用） | `assets/css/admin.css` | `Menu.php` |
| 自動化／序列／電子報／定期寄送列表 | `includes/Admin/WorkflowCatalog.php` | `assets/css/workflow-catalog.css` | 各頁自己的 repository |

- 本文件記錄現況與新工作的規則。文中的數字是 Contacts 的實際值，可作為起點，不是每個畫面都必須照抄的配額。
- FluentCRM Core + Pro 3.3.1 只做唯讀行為對照；不複製程式、CSS、資產，也不在執行期依賴。
- 入口、路由與 capability 以 [後台畫面與動作對照](admin-map.md) 為準；流程決策見 [工作指南](crm-ux-playbook.md)。

## 1. 共用 token

`assets/css/admin.css` 的 `.nowcrm-wrap` 定義 6 個共用 token：

| Token | 值 | 用途 |
|---|---|---|
| `--nowcrm-ink` | `#1a1c1c` | 主要文字、選中狀態、主要按鈕 |
| `--nowcrm-muted` | `#747878` | 次要文字、表頭、說明、未選頁籤 |
| `--nowcrm-line` | `#e5e5e5` | 卡片、輸入框、表格外框、頁籤底線 |
| `--nowcrm-surface` | `#ffffff` | 卡片、表格、輸入框、抽屜 |
| `--nowcrm-canvas` | `#f9f9f9` | 頁面底色、chip 底色、抽屜 footer |
| `--nowcrm-success` | `#1fb34a` | 已訂閱／正常狀態圓點；只用於狀態 |

- 新 CSS 能用這 6 個 token 表達的值就用 token；hover、分隔線、錯誤色與陰影目前仍是字面值，不要為了湊 token 另建一套命名。
- 動態 token 只在 `admin.css` 定義，其他頁面直接引用，不要另抄曲線或毫秒數：`--nowcrm-ease-out` `cubic-bezier(0.23, 1, 0.32, 1)`、`--nowcrm-ease-in-out` `cubic-bezier(0.77, 0, 0.175, 1)`、`--nowcrm-ease-drawer` `cubic-bezier(0.32, 0.72, 0, 1)`、`--nowcrm-duration-press` `120ms`、`--nowcrm-duration-hover` `120ms`、`--nowcrm-duration-drawer` `240ms`。
- `contact-detail.css` 的 `--nowcrm-cd-*` 只屬於該頁；自動化畫布和新畫面共用 `--nowcrm-*`。
- 連結不用藍色，以底線或 hover 區分。狀態色只出現在圓點、錯誤訊息與危險操作。

## 2. Contacts 列表配方

閱讀順序固定：**實際資料 → 主要動作 → 篩選與批次**。不要先把所有篩選器展開。

| 區域 | 基準 class | 現況 |
|---|---|---|
| 分區導覽 | `.nowcrm-contact-tabs` | 所有聯絡人／名單／標籤／分眾；1px 底線，選中 2px ink |
| 頁首 | `.nowcrm-contacts__head` | 左側 h1＋筆數；右側「更多操作（匯出／重設表格偏好）／匯入／新增聯絡人」 |
| 篩選列 | `.nowcrm-contacts-filters` | 單一白底 hairline 區塊，padding 10px 12px；搜尋、狀態、名單、標籤、進階、檢視 |
| 表格外框 | `.nowcrm-contacts-surface` | 白底、1px line、4px 圓角、水平捲動 |
| 表格 | `.nowcrm-contacts-table` | `min-width:720px`；表頭 11px／500、uppercase、`0.04em`、muted |
| 頁尾 | `.nowcrm-contacts-footer` | 左側「顯示 a–b，共 n 位」；右側每頁筆數與分頁 |
| 批次列 | `.nowcrm-bulk-form` | 固定底部；選取後才出現（JS 移除 `hidden`）；進度為 `role="status"` |
| 空狀態 | `.nowcrm-empty` | 置中；一句說明＋主要動作；現況另附兩個次要入口 |

- 預設每頁 10 筆。列 padding 8px 12px、分隔 `#ededed`、最後一列不畫線；hover 為 `#f5f5f5`。聯絡人欄是頭像 28px＋姓名 14px／500＋Email 12px muted。
- 勾選欄 36px；可排序表頭帶 `aria-sort`，排序圖示 `aria-hidden`。
- 篩選錯誤時顯示錯誤且不顯示資料，不回退成全部；批次、匯出與計數共用同一個驗證後的篩選範圍。「全選本頁」與「選取全部篩選」是兩個明確動作，勾滿本頁不等於跨頁全選。

### 購物車畫面

購物車列表、單筆詳情與設定共用 `nowcrm-cart` slug，專屬樣式只來自 `assets/css/cart-admin.css`。

| 狀態 | 區域 | 現況 |
|---|---|---|
| 列表 | `.nowcrm-cart-snapshot` | 「目前狀態快照」7 格（全部＋6 狀態）；全站計數，不受 Email 篩選影響；目前狀態用 `aria-current="page"` |
| 列表 | `.nowcrm-cart-table-scroll` | 只有表格可水平捲動，`role="region"`＋`tabindex="0"`；頁尾與分頁在捲動區外 |
| 列表 | `.nowcrm-cart-field` | 篩選標籤可見；Email 是完整比對，提示用 `aria-describedby`，錯誤欄位加 `aria-invalid` |
| 詳情 | `.nowcrm-cart-detail__head` | 麵包屑、Email 標頭、狀態／金額／件數 chip；訂單歸屬在最前，之後是商品快照與活動 |
| 詳情 | `.nowcrm-cart-side` | 提醒與還原、相關連結；重複的摘要卡不顯示 |
| 設定 | `.nowcrm-cart-jump`、`.nowcrm-cart-group` | 頁內跳點；捕捉、時程、流程擁有者分組，各自保留原本表單與儲存邊界 |

- 頁首筆數是「符合目前篩選」的筆數；狀態快照是全站數字。兩者不可混用。
- 空狀態依序處理：先回第一頁（保留篩選），再考慮清除篩選；只有捕捉未啟用時才提供開啟設定的連結。

## 3. Contacts 詳情配方

| 區域 | 基準 class | 現況 |
|---|---|---|
| 分區導覽 | `.nowcrm-contact-tabs` | 與列表同一組，維持在最上層 |
| 麵包屑 | `.nowcrm-contact-detail__crumbs` | 所有聯絡人／目前聯絡人；右側上一位／下一位 |
| 身份標頭 | `.nowcrm-contact-detail__header` | 頭像 40px、姓名 20px、Email、建立時間、Email／刪除、類型與狀態 |
| 內容分頁 | `.nowcrm-contact-detail__tabs` | 概覽／Email／訂單／筆記與動態；選中頁籤白底接內容 |
| 兩欄主體 | `.nowcrm-contact-detail__body` | `minmax(0, 1fr) minmax(260px, 320px)`；gap 16px |
| 內容卡 | `.nowcrm-contact-detail__section` | 白底、1px line、4px 圓角、16px padding |
| 右側 | `.nowcrm-contact-detail__side` | 狀態、名單、標籤，各自獨立表單 |
| 筆記 | `.nowcrm-notes__*`、`.nowcrm-notes__drawer` | 表格＋520px 抽屜；一般抽屜 700px |

- 上層分區導覽與詳情分頁是不同層級，詳情頁兩者同時存在；不要合併，也不要互套樣式。
- 概覽的「基本資料／地址資料／自訂資料」是三張卡，但包在同一個 profile 表單裡，一次送出。
- 寫入邊界：profile 表單只寫檔案欄位；狀態、名單、標籤各自送出，只改指定關聯；筆記與刪除各自送出。不要用一張大表單覆寫整筆聯絡人。
- 表單 label 欄 180px、輸入最寬 420px；窄螢幕改上下堆疊。儲存失敗在表單內顯示 `role="alert"` 並移動焦點。

## 4. 按鈕、表單、狀態與浮層

- 按鈕：主要 `button button-primary`（ink 底、白字、無陰影）；次要 `button`（白底、1px line）。頁首工具列 36px，內容區與篩選列 32px，`<=782px` 時 36px；每個畫面只留一個主要動作，其餘收進「更多操作」。
- 危險操作：白底、danger 文字與邊框（現況 `#ba1a1a`／`#f0c8cb`）。刪除確認目前是 `onclick="return confirm(...)"` 的 JS；沒有 JS 時仍會直接送出，不能宣稱已有無 JS 確認流程。表單為白底、1px line、4px 圓角、32px 高，focus 是 ink 邊框＋1px ink ring；標籤 12px／500 muted，錯誤欄位加 `aria-invalid="true"`。
- chip：11px、padding 2px 8px、999px、canvas 底、line 邊框，最寬 160px 省略。狀態 badge 是 chip 外形＋6px 圓點；只有已訂閱用 success，其餘灰，且必須保留文字。
- 頭像：列表 28px、詳情 40px；soft 底、line 邊框、999px；顯示姓名首字母，不載入外部圖片。
- 抽屜：`<dialog class="nowcrm-drawer">`；一般 `min(700px, 100vw)`、筆記 520px；header 16px 20px、body 20px、footer canvas 底。對話框（例如匯出）為 `min(640px, calc(100vw - 32px))`，head／foot 12px 16px，footer 放取消與主要動作。
- 浮層選單用 `<details>`（例如「更多操作」）；面板白底、1px `#d4d4d4`、4px 圓角，最大高度 300px。
- 抽屜與對話框靠 JS 的 `showModal()` 呈現；`<dialog open>` 只是內嵌顯示，不等於完整的無 JS 流程。匯出在 `<noscript>` 另給 CSV 設定頁連結；新增聯絡人 POST 回錯時以 `open` 屬性重開抽屜。
- 互動回饋共用一層：可點元素移除 tap highlight；`a`、`button`、`.button` 的顏色、邊框、背景與 `transform` 共用 120ms 過渡；按鈕按下 `scale(0.97)`；鍵盤焦點統一 2px ink outline。抽屜滑入 240ms drawer 曲線、遮罩 180ms 淡入；`prefers-reduced-motion: reduce` 時取消位移與按壓縮放，只保留顏色變化。新增互動元素沿用這層，不要每個元件各寫一套 easing。

## 5. 響應式

| 斷點 | 行為 |
|---|---|
| `> 1024px` | 列表完整表格；詳情兩欄，側欄 260–320px |
| `<= 960px` | 詳情改單欄，側欄移到內容後；批次列左側縮到 24px |
| `<= 782px` | WP admin 手機版；搜尋佔滿一列；按鈕 36px；批次列左右 12px |
| `<= 767px` | 詳情標頭與卡片 padding 12px；表單 label 改上下堆疊 |
| `<= 600px` | 筆記表單與筆記列改單欄 |

- 表格不壓縮；保持 720px 最小寬度並容許水平捲動。抽屜手機全寬，不出現水平捲動；不為了放進手機隱藏狀態、主要動作或錯誤訊息。

## 6. 無障礙與失敗狀態檢查

新畫面交付前確認：

- 每個 nav 有 `aria-label`；目前位置用 `aria-current="page"`（列表分區導覽與詳情分頁都已如此）。可排序表頭有 `aria-sort`；勾選全部與列勾選都有可讀名稱。
- 焦點不可移除；現況用 2px ink outline 或 1px ink ring。抽屜關閉後把焦點還給觸發元素（`contact-detail.js` 已處理），新元件比照。
- 非同步結果用 live region 或 `role="alert"`；批次進度現況是 `role="status" aria-live="polite"`。錯誤留在脈絡裡：篩選錯誤顯示訊息、不造假資料；表單錯誤保留草稿並把焦點移到訊息。
- 點擊目標：一般控制項至少 32px，表格列內 icon button 至少 24px。這是新工作的門檻，不是對既有控制項的保證；完整鍵盤走查、所有元件的無 JS 等價流程與跨瀏覽器節奏尚未全面具備，不要用文件宣稱已達成。

## 7. FluentCRM 對照（唯讀）

來源：`plugins/fluent-crm/assets/v3app/src/Modules/Contacts/Contacts.js`、
`Modules/Profile/Parts/ProfileOverview.js`、`assets/admin/css/app3.css`。

| 區域 | FluentCRM 3.3.1 | NOW |
|---|---|---|
| 列表 | Vue＋Element Plus `ContactsTable`；頭像 36px（窄版 30px） | 伺服器渲染表格；頭像 28px；原生表單＋少量 JS |
| 詳情版面 | `.fcrm_profile_container` 為 `flex`、gap 32px；`.fcrm_profile_sidebar` 為 56px 側軌 | CSS grid 1fr＋260–320px 固定側欄；gap 16px |
| 詳情標頭 | `.fcrm_profile_header`：8px 圓角、padding 20px | 4px 圓角、16px padding |
| 表單 | `ProfileOverview.js` 以 Vue 元件排基本資料與地址表單 | 同一 profile 表單內的基本／地址／自訂三張卡 |
| Token／圓角 | `#0E121B`／`#525866`／`#99A0AE`、success `#1FC16B`、藍色連結；8px 為主流圓角 | `#1a1c1c`／`#747878`／`#e5e5e5`、success `#1fb34a`、無藍色連結；4px |
| 前端 | Vue／Element Plus | PHP＋原生 HTML／CSS；不新增框架依賴 |

刻意不同：Company UI、sticky note、Fluent 的自動存檔節奏、Element Plus 元件與 8px 圓角、Pro 的 A/B 報表版面。差異寫在功能文件，不假裝等同。

### 總覽與報表

FluentCRM 3.3.1 的 `Dashboard.js` 將快照、近期工作和快速入口放在一起；
`ReportsHome.js` 則先選分析主題、日期與比較，再從趨勢往清單或明細查。
NOW 沿用這個分工：總覽提供可到工作頁的數字和連結，報表才提供期間與比較控制。

- 沿用 Contacts 頁首、分頁、篩選列和可橫捲表格。分析卡片共用白底、1px 邊線、
  4px 圓角；數字使用等寬數字字形。窄畫面卡片堆疊，不縮小表格欄位。
- 趨勢以中性色呈現，系列不能只靠顏色辨識。圖表須提供可讀的確切資料；
  無資料時顯示空狀態，不畫出貌似有結果的圖。
- 篩選與明細以可分享的 URL 為主。明確標示統計期間、資料時間欄位、比較範圍
  與不能推論的關係；「現在」的快照不能看起來像是所選期間的歷史。

## 8. 樣式表地圖

| 檔案 | 載入畫面 | 內容 |
|---|---|---|
| `assets/css/admin.css` | 所有 NOW 畫面 | 6 個共用 token、按鈕、表格、抽屜、Contacts 列表 |
| `assets/css/contact-detail.css` | `nowcrm-contact` | 頁面自己的 `--nowcrm-cd-*`、詳情標頭、分頁、表單、名單／標籤、筆記抽屜 |
| `assets/css/contact-terms.css` | 名單、標籤 | 名單／標籤表格與對話框 |
| `assets/css/workflow-catalog.css` | 自動化／序列／範本／電子報／定期寄送列表 | `WorkflowCatalog` 的工具列與批次 |
| `assets/css/automation-editor.css` | `nowcrm-automation` | 以共用 token 呈現自動化畫布、步驟卡與編輯抽屜；列表與紀錄沿用共用樣式 |
| `assets/css/cart-admin.css` | `nowcrm-cart` | 購物車列表快照、詳情標頭與側欄、設定分組與窄螢幕堆疊 |
| `assets/css/order-panel.css` | Woo 訂單編輯畫面 | 訂單側欄的聯絡人面板：身份、統計列、同意、名單標籤與提示區塊 |
| `form-workspace.css`、`campaign-editor.css`、`workflow-email.css` | 序列、定期寄送、電子報 | 編輯器與寄送控制項 |

新增樣式前先找同一個 class；只有該畫面真的需要不同密度或結構時才加 scoped CSS，不複製整份 `admin.css`。
