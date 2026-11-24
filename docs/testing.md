# NOW CRM — 測試與驗證

## 1. 測試架構

外掛程式碼與測試環境分離。Repo 內只有測試程式、`phpunit.xml.dist` 與
`tests/bin/run-tests.sh`；下列項目都由開發環境提供，不在 repo 內，也不進 Git：

| 依賴 | 用途 |
|---|---|
| WordPress 安裝 | runner 掛載為 `/var/www/html` |
| WooCommerce | 測試 bootstrap 必須載入 |
| WordPress test library | `WP_TESTS_DIR` 指向它的 `phpunit` 目錄 |
| Yoast PHPUnit Polyfills | WordPress 測試庫的相容層 |
| PHPUnit 9.6 PHAR | WordPress 測試庫要求 |
| 一次性 MySQL 資料庫 | 測試讀寫；不可指向正式站 |
| Docker 網路 | runner 預設接入 `wpdev_default` |

`tests/bin/run-tests.sh` **不是** standalone。它預設讀取相對於 repo 上層的
`site/`、`plugins/`、`.cache/` 與 Docker 網路；缺少任一項就停止。請用環境變數覆寫，
或直接以自己的 PHP 8.3 環境執行 `phpunit.phar`。

**為什麼是 PHPUnit 9.6**：WordPress test library 會呼叫
`PHPUnit\Util\Test::parseTestMethodAnnotations()`，該 API 在 PHPUnit 10 以上已移除。

測試使用虛構 Email、虛構訂單與獨立資料庫。不要在正式站、正式資料或共用資料庫執行
`--hpos`／`--multisite`；這兩個模式會變更測試資料庫結構。

## 2. 一次性準備

以下範例只用來說明依賴形狀。路徑、DB 主機與密碼由你的環境決定。

```bash
export NOWCRM_DEV_ROOT="/path/to/dev-root"
export NOWCRM_TEST_DB="nowcrm_test"
export NOWCRM_TEST_DB_USER="replace-with-db-user"
export NOWCRM_TEST_DB_HOST="replace-with-db-host"

mkdir -p "$NOWCRM_DEV_ROOT/.cache/wp-tests-7.1.4"
cd "$NOWCRM_DEV_ROOT/.cache"

# WordPress test library（只取 tests/phpunit）
curl -sSL https://codeload.github.com/WordPress/wordpress-develop/tar.gz/refs/tags/7.1.4 \
  | tar xz --strip-components=2 \
      -C "$NOWCRM_DEV_ROOT/.cache/wp-tests-7.1.4" \
      wordpress-develop-7.1.4/tests/phpunit

# PHPUnit polyfills 與 PHPUnit
curl -sSL https://codeload.github.com/Yoast/PHPUnit-Polyfills/tar.gz/refs/tags/3.0.0 | tar xz
mv PHPUnit-Polyfills-3.0.0 phpunit-polyfills
curl -sSL -o phpunit-9.6.phar https://phar.phpunit.de/phpunit-9.6.phar

# 測試資料庫（使用你的 MySQL client 與一次性伺服器）
mysql -u "$NOWCRM_TEST_DB_USER" -p -h "$NOWCRM_TEST_DB_HOST" \
  -e "CREATE DATABASE IF NOT EXISTS $NOWCRM_TEST_DB CHARACTER SET utf8mb4"
```

`wp-tests-config.php` 放在 test library 目錄旁。它必須設定：

- `ABSPATH`：WordPress 安裝路徑，runner 內是 `/var/www/html/`。
- `DB_NAME`、`DB_USER`、`DB_PASSWORD`、`DB_HOST`：一次性資料庫。
- `$table_prefix = 'wptests_'`。
- `WPMU_PLUGIN_DIR`：指向空目錄，避免站台專用的 mu-plugins 進入測試。

runner 的預設值如下；全部可用同名環境變數覆寫：

| 變數 | 預設 |
|---|---|
| `NOWCRM_WP_ROOT` | `<dev root>/site` |
| `NOWCRM_PLUGINS_DIR` | `<dev root>/plugins` |
| `NOWCRM_TEST_CACHE` | `<dev root>/.cache` |
| `NOWCRM_WP_TESTS_LIB` | `<cache>/wp-tests-7.1.4` |
| `NOWCRM_POLYFILLS` | `<cache>/phpunit-polyfills` |
| `NOWCRM_PHPUNIT` | `<cache>/phpunit-9.6.phar` |
| `NOWCRM_TEST_IMAGE` | `wordpress:cli` |
| `NOWCRM_TEST_NETWORK` | `wpdev_default` |
| `NOWCRM_TEST_DB` | `nowcrm_test` |
| `NOWCRM_TEST_DB_HOST` | 由環境提供；覆寫 runner 預設 |
| `NOWCRM_TEST_DB_USER` | 由環境提供；覆寫 runner 預設 |
| `NOWCRM_TEST_DB_PASSWORD` | 由環境提供；覆寫 runner 預設 |

## 3. 前端建置

瀏覽器程式碼在 `src/`，由 WordPress 原生 `@wordpress/scripts` 建置到 `assets/build/`。
`assets/build/` 與 `package-lock.json` 進版控，`node_modules/` 不進版控；PHP 端以
`*.asset.php` 取得依賴與版本。

```bash
npm ci
npm run test:unit
npm run build
npm run check-types
```

修改 `src/` 後必須重新 `npm run build`，並把 `assets/build/` 的產物一起提交。

## 4. 執行

```bash
tests/bin/run-tests.sh                       # 單站
tests/bin/run-tests.sh --multisite           # 多站（主站 + 另一個網路站台）
tests/bin/run-tests.sh --hpos                # HPOS
tests/bin/run-tests.sh --filter WooSyncTest  # 單一測試類別
```

runner 會啟動 `wordpress:cli` 容器，掛載 WordPress、外掛目錄、test library、polyfills
與 PHAR，再執行 PHPUnit。若你的環境沒有這些掛載或 Docker 網路，請直接以 PHP 8.3 執行
`phpunit.phar --configuration phpunit.xml.dist`，並設定相同的 `WP_TESTS_*` 與
`NOWCRM_*` 變數。

`--hpos` 會在 `setup_theme` 對目前 DB 執行 `DROP TABLE` 並重建 `wc_orders`、
`wc_order_addresses`、`wc_order_operational_data`、`wc_orders_meta`；`--multisite`
會建立第二個測試站台。兩者都只能在用完即丟的資料庫執行。

常用切片：

```bash
tests/bin/run-tests.sh --filter WooSyncTest
tests/bin/run-tests.sh --filter TermsTest
tests/bin/run-tests.sh --filter EmailQueueTest
tests/bin/run-tests.sh --filter CartAutomationIntegrationTest
tests/bin/run-tests.sh --filter SecurityTest
tests/bin/run-tests.sh --hpos --filter HposOrderTest
tests/bin/run-tests.sh --multisite --filter MultisiteTest
```

沒有實際輸出時記 **NOT RUN**，不要寫 PASS。

## 5. 涵蓋範圍

| 區域 | 代表測試 |
|---|---|
| Schema 與生命週期 | `SchemaTest`、`SettingsTest`、`LifecycleTest` |
| 聯絡人與名單 | `ContactsTest`、`TermsTest`、`StatusTest`、`ContactBulkTest`、`ContactCsvTest` |
| WooCommerce | `WooSyncTest`、`HposOrderTest`、`OrderAccountingTest`、`CheckoutOptinTest` |
| 寄送佇列 | `EmailJobRepositoryTest`、`EmailQueueTest`、`EmailBlocksTest`、`TrackingTest` |
| Campaign 與受眾 | `CampaignTest`、`EditorTest`、`AudienceTest`、`RecurringCampaignTest` |
| 自動化與序列 | `AutomationTest`、`AutomationWorkflowTest`、`SequencesTest`、`WorkflowEmailTest` |
| 購物車 | `CartTest`、`CartCaptureStateTest`、`CartSourcePaymentTest`、`CartRecoverySafetyTest`、`CartAdminTest` |
| 同意與隱私 | `ConsentTest`、`ConsentSettingsTest`、`UnsubscribeTest`、`PrivacyExportTest` |
| 整合 | `SesTest`、`WebhooksTest`、`FormsTest`、`SmartLinksTest` |
| 權限與站台 | `SecurityTest`、`MultisiteTest` |

`WooSyncTest` 包含升級回歸：預先建立舊版顧客名單，再同步新訂單，確認聯絡人加入原名單、
名單沒有改名，且沒有建立第二個顧客名單。

## 6. 已知限制

1. **HPOS 切換**：測試以 `--hpos` 建立 WooCommerce HPOS 資料表；未在測試套件中來回切換
   既有站台的儲存模式。程式只使用 `wc_get_order()`／`WC_Order` CRUD 與官方 hook。
2. **真實寄送**：本機使用 `capture` transport 驗證佇列與 renderer。真實 SMTP、SES 送達與
   SNS 訂閱需要外部憑證與公開端點，不由 PHPUnit 證明。
3. **瀏覽器流程**：Classic／Blocks 結帳、`sendBeacon`、跨分頁、Session 過期與行動瀏覽器
   需在測試站以實際流程驗收。PHPUnit fixture 不能取代瀏覽器證據。
4. **排程與佇列**：Action Scheduler tick、重複 tick、`wp action-scheduler run`、claim／lease
   與 `unknown` 不重寄需要實際執行紀錄。手動呼叫 `wp nowcrm cart sweep` 不算 cron 驗收。
5. **付款與退款**：離線付款、延遲回呼、失敗重試、退款與重複 callback 需在測試站走過。
   合成 `date_paid` 不是支付商對帳。
6. **Schema 升級／降級**：升級需在舊版 fixture 上執行並核對索引與資料保留；不支援原地
   降級。
7. **靜態分析**：本專案以 `php -l` 與 PHPUnit 為驗收，未引入 PHPCS 或 PHPStan 工具鏈。

## 7. 清理

```bash
export NOWCRM_DEV_ROOT="/path/to/dev-root"

rm -rf "$NOWCRM_DEV_ROOT/.cache/wp-tests-7.1.4" \
       "$NOWCRM_DEV_ROOT/.cache/phpunit-polyfills" \
       "$NOWCRM_DEV_ROOT/.cache/phpunit-9.6.phar"

mysql -u "$NOWCRM_TEST_DB_USER" -p -h "$NOWCRM_TEST_DB_HOST" \
  -e "DROP DATABASE IF EXISTS $NOWCRM_TEST_DB"
```

測試不會寫入外掛 repo；`dist/`、`.phpunit.result.cache` 與本機測試設定都在
`.gitignore` 內。
