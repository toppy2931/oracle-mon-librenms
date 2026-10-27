<?php
declare(strict_types=1);

/**
 * IOC 函式庫的進入點：依序載入全部類別（不用 autoloader，免得依賴 LibreNMS 的設定）。
 * 這些檔案安裝在 LibreNMS 的 includes/ioc/，不在 html/ 底下，瀏覽器無法直接要求它們。
 * 函式庫本身不依賴 LibreNMS，所以能在沒有 LibreNMS 的環境（例如單元測試）載入。
 */

namespace Ioc;

if (!defined('IOC_LIB_LOADED')) {
    define('IOC_LIB_LOADED', true);
    foreach ([
        'Errors', 'Labels', 'Access', 'Time', 'CaseFlow', 'CaseRepo', 'CaseService', 'PdoCaseRepo', 'Schema',
        'LogSource', 'WindowedLogSource', 'GraylogClient', 'UnavailableLogSource', 'OverviewService', 'CaseCatalog', 'CaseView', 'EvidenceService',
        'Fleet', 'RiskScore', 'LandscapeService',
        'IpNets', 'Sites', 'SettingsRepo', 'PdoSettingsRepo', 'SettingsService',
        'IdentityService', 'AuditTrail', 'HostLoginService', 'NetworkVisibilityService',
        'ConfigBackupGateway', 'ConfigBackupService',
        'AuditEvidenceService', 'AuditSnapshotStore', 'AuditAssetDiscovery', 'AuditAutomationService',
        'BehaviorService', 'BehaviorAnalyzer', 'BehaviorAiProvider', 'BehaviorCollector', 'BehaviorWebhook', 'BehaviorVizExport',
    ] as $f) {
        require_once __DIR__ . '/' . $f . '.php';
    }
}
