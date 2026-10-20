<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 中文顯示名稱的單一來源。資料裡存英文代碼，只有顯示時才轉中文。
 * event_action 的代碼由 system/graylog-pipelines.py 寫入；tests/test_php.py 會檢查兩邊一致，
 * 新增代碼卻沒在這裡補中文，測試會失敗。
 */
final class Labels
{
    public const ACTIONS = [
        'login_success'        => '登入成功',
        'login_failed'         => '登入失敗',
        'invalid_user'         => '無效帳號登入嘗試',
        'logout'               => '登出',
        'explicit_logon'       => '以明確憑證登入',
        'privileged_logon'     => '使用高權限',
        'privileged_command'   => '執行提權指令',
        'account_created'      => '帳號建立',
        'account_enabled'      => '帳號啟用',
        'account_disabled'     => '帳號停用',
        'account_deleted'      => '帳號刪除',
        'account_locked'       => '帳號鎖定',
        'group_member_added'   => '加入群組',
        'group_member_removed' => '移出群組',
        'audit_policy_changed' => '稽核原則變更',
        'audit_log_cleared'    => '稽核日誌清除',
        'service_installed'    => '安裝服務',
        'fw_allow'             => '防火牆允許',
        'fw_deny'              => '防火牆阻擋',
        'link_up'              => '連接埠上線',
        'link_down'            => '連接埠離線',
    ];

    public const LOG_TYPES = [
        'windows'  => 'Windows 伺服器／網域',
        'linux'    => 'Linux 主機',
        'firewall' => '防火牆',
        'network'  => '網路設備',
    ];

    /** 日誌事件的 severity → 「需注意程度」。 */
    public const SEVERITY = [
        'info'   => '低',
        'low'    => '低',
        'medium' => '中',
        'high'   => '高',
    ];

    /** 事件案可選的等級（沒有 info）。 */
    public const CASE_SEVERITY = [
        'low'    => '低',
        'medium' => '中',
        'high'   => '高',
    ];

    public const CASE_STATUS = [
        'new'         => '待確認',
        'ack'         => '已確認',
        'in_progress' => '處理中',
        'closed'      => '已結案',
    ];

    public const RESOLUTION = [
        'fixed'          => '已處理',
        'false_positive' => '誤報',
        'accepted'       => '接受風險',
    ];

    public static function action(string $code): string
    {
        return self::ACTIONS[$code] ?? $code;
    }

    public static function logType(string $code): string
    {
        return self::LOG_TYPES[$code] ?? $code;
    }

    public static function severity(string $code): string
    {
        return self::SEVERITY[$code] ?? $code;
    }

    public static function caseStatus(string $code): string
    {
        return self::CASE_STATUS[$code] ?? $code;
    }
}
