# 新增設備 OS 支援

## 流程

1. **建 YAML 偵測定義**：`resources/definitions/os_discovery/<os>.yaml`
   - 偵測方法優先序：sysObjectID prefix（最佳）> sysDescr regex > snmpget（避免）
   - 定義 OS name、icon、device type
2. **OS 特定邏輯**（選用）：建 `LibreNMS/OS/<Os>.php` 類別（類名 = OS 名首字大寫）
3. **Sensor/模組定義**：YAML `modules` key 定義 OID 模板（temperature、voltage、state…）
4. **測試資料**：
   ```bash
   ./scripts/collect-snmp-data.php -h <device_id>   # 從真實設備擷取 → tests/snmpsim/<os>.snmprec
   ./scripts/save-test-data.php -o <os>             # 產生 DB 狀態基準 → tests/data/<os>.json
   ```
5. **跑測試**：
   ```bash
   ./lnms dev:check unit -o <osname>                # 該 OS 的 discovery/module 測試
   ./lnms dev:simulate <os_variant>                 # 手動啟 snmpsim（127.1.6.1:1161）除錯
   ```

## 測試結構

| 目錄/檔案 | 用途 |
| ---- | ---- |
| `tests/Unit/` | 純單元測試（無 DB/SNMP） |
| `tests/Feature/` | 完整 app context |
| `tests/OSDiscoveryTest.php` | 以 snmprec 重播驗證 OS 偵測正確 |
| `tests/OSModulesTest.php` | 驗證 discovery/poller 模組輸出符合 JSON 基準 |
| `tests/Browser/` | Laravel Dusk 瀏覽器測試（預設排除） |
| `tests/snmpsim/*.snmprec` | 擷取的 SNMP 回應（每設備/變體一檔） |
| `tests/data/*.json` | discovery/poll 後的 DB 狀態基準 |

## 機制

snmprec 檔案由 snmpsim 重播，測試不需真實設備：
```
snmprec（模擬 SNMP 回應）→ discovery/poller 執行 → 結果與 tests/data/*.json 比對
```

修改既有 OS 的 discovery/polling 邏輯後，若輸出合理變更，需重新產生 JSON 基準（`save-test-data.php`）。
