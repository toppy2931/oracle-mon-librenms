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
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_assets (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  address VARCHAR(255) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  readiness VARCHAR(24) NOT NULL,
  reason VARCHAR(120) NOT NULL DEFAULT '',
  os_confirmed TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NOT NULL,
  requested_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_audit_assets_kind (kind, readiness)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'ALTER TABLE ioc_audit_assets ADD COLUMN IF NOT EXISTS requested_at DATETIME NULL',
            'ALTER TABLE ioc_audit_assets ADD COLUMN IF NOT EXISTS os_confirmed TINYINT(1) NOT NULL DEFAULT 1',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_packs (
  pack_key VARCHAR(40) NOT NULL,
  version INT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (pack_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  pack_key VARCHAR(40) NOT NULL,
  pack_version INT UNSIGNED NOT NULL DEFAULT 1,
  rule_id VARCHAR(80) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_mapping (cycle_id, control_id, pack_key, rule_id),
  KEY idx_ioc_audit_mapping_control (cycle_id, control_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'ALTER TABLE ioc_audit_mappings ADD COLUMN IF NOT EXISTS pack_version INT UNSIGNED NOT NULL DEFAULT 1',
            'ALTER TABLE ioc_audit_mappings ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NULL,
  source VARCHAR(32) NOT NULL,
  source_revision VARCHAR(128) NOT NULL,
  pack_key VARCHAR(40) NOT NULL,
  pack_version INT UNSIGNED NOT NULL,
  dedupe_key CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL,
  error_code VARCHAR(48) NOT NULL DEFAULT '',
  started_at DATETIME NOT NULL,
  finished_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_run_dedupe (dedupe_key),
  KEY idx_ioc_audit_run_asset (asset_id, pack_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_auto_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  source_ref VARCHAR(200) NOT NULL,
  snapshot_name CHAR(37) NULL,
  sha256 CHAR(64) NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  item_count INT UNSIGNED NOT NULL DEFAULT 0,
  truncated TINYINT(1) NOT NULL DEFAULT 0,
  source_from DATETIME NULL,
  source_to DATETIME NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_auto_evidence_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_auto_links (
  evidence_id BIGINT UNSIGNED NOT NULL,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (evidence_id, cycle_id, control_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_proposal_evidence (
  proposal_id BIGINT UNSIGNED NOT NULL,
  evidence_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (proposal_id,evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_case_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  category VARCHAR(32) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_case_mapping (cycle_id,control_id,category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'ALTER TABLE ioc_audit_case_mappings ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_mapping_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mapping_kind VARCHAR(16) NOT NULL,
  mapping_id BIGINT UNSIGNED NOT NULL,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  source_key VARCHAR(80) NOT NULL,
  source_version INT UNSIGNED NOT NULL,
  action VARCHAR(16) NOT NULL,
  actor VARCHAR(255) NOT NULL,
  occurred_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ioc_audit_mapping_history (cycle_id,mapping_kind,mapping_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_case_attempts (
  case_id INT UNSIGNED NOT NULL,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  closed_at DATETIME NOT NULL,
  evidence_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL,
  error_code VARCHAR(48) NOT NULL DEFAULT '',
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (case_id,cycle_id,control_id,closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_results (
  run_id BIGINT UNSIGNED NOT NULL,
  rule_id VARCHAR(80) NOT NULL,
  status VARCHAR(20) NOT NULL,
  evidence_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (run_id, rule_id),
  KEY idx_ioc_audit_result_rule (rule_id, run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS ioc_audit_proposals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  evidence_id BIGINT UNSIGNED NULL,
  fingerprint CHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL,
  reason VARCHAR(200) NOT NULL,
  review_state VARCHAR(16) NOT NULL DEFAULT 'pending',
  reviewed_by VARCHAR(255) NULL,
  review_reason VARCHAR(2000) NULL,
  created_at DATETIME NOT NULL,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ioc_audit_proposal_fingerprint (fingerprint),
  KEY idx_ioc_audit_proposal_pending (cycle_id, review_state, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
