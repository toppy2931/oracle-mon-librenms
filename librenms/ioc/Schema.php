<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 事件案的資料表。放在 LibreNMS 的資料庫，一律用 ioc_ 前綴，LibreNMS 升級不會動它，
 * 備份也跟著 LibreNMS 走。語句全部可重複執行（install.sh／update.sh 每次都會跑）。
 * 時間欄位存 UTC。ioc_case_history 只增不改不刪：程式裡沒有任何更新或刪除它的路徑（IS-026）。
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
        ];
    }
}
