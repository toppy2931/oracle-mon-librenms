<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 入口的資料表（事件案與可調設定）。放在 LibreNMS 的資料庫，一律用 ioc_ 前綴，LibreNMS 升級不會動它，
 * 備份也跟著 LibreNMS 走。語句全部可重複執行（install.sh／update.sh 每次都會跑）。
 * 時間欄位存 UTC。ioc_case_history 與 ioc_settings_history 只增不改不刪：程式裡沒有任何更新或刪除它們的路徑（IS-026）。
 * 新增資料表時，system/ioc-db-setup.php 的 $tables 清單要同步加（測試會擋）。
 *
 * 之後要加欄位：不要只改上面的 CREATE TABLE（已部署的主機資料表已存在，CREATE 會被略過），
 * 要在這份清單最後面另外加一句 `ALTER TABLE <表> ADD COLUMN IF NOT EXISTS …`（MariaDB 支援），
 * 並同步 PdoCaseRepo 的欄位白名單。tests/test_php.py 會擋沒有 IF NOT EXISTS 的 ALTER／CREATE。
 *
 * 操作者帳號欄位用 VARCHAR(255)：LibreNMS 的 users.username 最長 255，寫不進去會讓該使用者永遠無法處理事件案。
 */
final class Schema
{
    /** @return string[] */
    public static function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_cases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  summary TEXT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'new',
  severity VARCHAR(8) NOT NULL DEFAULT 'medium',
  subject_user VARCHAR(128) NULL,
  subject_ip VARCHAR(45) NULL,
  subject_host VARCHAR(128) NULL,
  evidence_query VARCHAR(1000) NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'manual',
  dedup_key VARCHAR(128) NULL,
  assignee VARCHAR(255) NULL,
  resolution VARCHAR(16) NULL,
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_cases_dedup (dedup_key),
  KEY idx_ioc_cases_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_case_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  case_id INT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  actor VARCHAR(255) NOT NULL,
  action VARCHAR(24) NOT NULL,
  from_status VARCHAR(16) NULL,
  to_status VARCHAR(16) NULL,
  resolution VARCHAR(16) NULL,
  note TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_case_history_case (case_id, id),
  CONSTRAINT fk_ioc_case_history_case FOREIGN KEY (case_id) REFERENCES ioc_cases (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            // 入口的可調設定（目前是風險等級門檻）。沒有資料列＝用程式裡的預設值，所以升級不會改變既有行為
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_settings (
  skey VARCHAR(64) NOT NULL,
  svalue VARCHAR(255) NOT NULL,
  updated_by VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            // 設定的異動紀錄，只增不改不刪：誰、何時、把什麼從多少改成多少
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_settings_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  actor VARCHAR(255) NOT NULL,
  skey VARCHAR(64) NOT NULL,
  old_value VARCHAR(255) NOT NULL,
  new_value VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_settings_history_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            // ── 以下是「事件詳情改版」新增的事件案欄位（2026-10-20）。已部署的主機資料表已存在，所以用 ALTER 補；
            // 全新安裝則是建好表之後立刻補上。PdoCaseRepo::CASE_COLUMNS 要同步（測試會檢查）。
            // category 是事件類型代碼（CaseCatalog），攻擊技術與建議處置依它顯示、不存進案件；tags 是逗號分隔的標籤。
            'ALTER TABLE ioc_cases ADD COLUMN IF NOT EXISTS category VARCHAR(32) NULL',
            'ALTER TABLE ioc_cases ADD COLUMN IF NOT EXISTS target_host VARCHAR(128) NULL',
            'ALTER TABLE ioc_cases ADD COLUMN IF NOT EXISTS related_host VARCHAR(128) NULL',
            'ALTER TABLE ioc_cases ADD COLUMN IF NOT EXISTS impact_scope VARCHAR(200) NULL',
            'ALTER TABLE ioc_cases ADD COLUMN IF NOT EXISTS tags VARCHAR(200) NULL',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_catalogs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_label VARCHAR(100) NOT NULL,
  digest CHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_catalog_digest (digest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_controls (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  catalog_id INT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  domain VARCHAR(100) NOT NULL,
  title VARCHAR(200) NOT NULL,
  requirement TEXT NOT NULL,
  description TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_control_code (catalog_id, code),
  CONSTRAINT fk_ioc_audit_control_catalog FOREIGN KEY (catalog_id) REFERENCES ioc_audit_catalogs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_cycles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  catalog_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_ioc_audit_cycle_catalog FOREIGN KEY (catalog_id) REFERENCES ioc_audit_catalogs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'ALTER TABLE ioc_audit_cycles ADD COLUMN IF NOT EXISTS is_current TINYINT(1) NOT NULL DEFAULT 0',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  kind VARCHAR(24) NOT NULL,
  source_ref VARCHAR(200) NOT NULL,
  source_from DATETIME NULL,
  source_to DATETIME NULL,
  snapshot_name CHAR(37) NULL,
  sha256 CHAR(64) NULL,
  bytes INT UNSIGNED NULL,
  item_count INT UNSIGNED NULL,
  truncated TINYINT(1) NOT NULL DEFAULT 0,
  captured_by VARCHAR(255) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_audit_evidence_control (cycle_id, control_id),
  CONSTRAINT fk_ioc_audit_evidence_cycle FOREIGN KEY (cycle_id) REFERENCES ioc_audit_cycles (id),
  CONSTRAINT fk_ioc_audit_evidence_control FOREIGN KEY (control_id) REFERENCES ioc_audit_controls (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_assessments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  reason TEXT NULL,
  evidence_id BIGINT UNSIGNED NULL,
  assessed_by VARCHAR(255) NOT NULL,
  assessed_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_assessment (cycle_id, control_id),
  CONSTRAINT fk_ioc_audit_assessment_cycle FOREIGN KEY (cycle_id) REFERENCES ioc_audit_cycles (id),
  CONSTRAINT fk_ioc_audit_assessment_control FOREIGN KEY (control_id) REFERENCES ioc_audit_controls (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_assessment_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  reason TEXT NULL,
  evidence_id BIGINT UNSIGNED NULL,
  actor VARCHAR(255) NOT NULL,
  occurred_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_audit_assessment_history (cycle_id, control_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_remediation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  owner VARCHAR(255) NOT NULL,
  due_on DATE NOT NULL,
  status VARCHAR(16) NOT NULL,
  note TEXT NULL,
  closing_evidence_id BIGINT UNSIGNED NULL,
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_audit_remediation_cycle (cycle_id, status, due_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_remediation_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  remediation_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  owner VARCHAR(255) NOT NULL,
  due_on DATE NOT NULL,
  note TEXT NULL,
  closing_evidence_id BIGINT UNSIGNED NULL,
  actor VARCHAR(255) NOT NULL,
  occurred_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_audit_remediation_history (remediation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at DATETIME NOT NULL,
  actor VARCHAR(255) NOT NULL,
  action VARCHAR(32) NOT NULL,
  target_type VARCHAR(24) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_audit_events_time (occurred_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
