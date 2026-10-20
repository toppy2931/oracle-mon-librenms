<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 事件案給畫面看的樣子：加上中文名稱、時間轉台北時間、空值統一成空字串。
 * 資料庫存英文代碼與 UTC，只有在這裡才轉成人看的（INT-072）。
 */
final class CaseView
{
    public const HISTORY_ACTION = [
        'created'        => '建立事件案',
        'status_changed' => '狀態變更',
        'comment'        => '備註',
    ];

    public const SOURCE = [
        'manual' => '手動建立',
        'auto'   => '自動建立',
    ];

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public static function row(array $c): array
    {
        $s = static fn ($v): string => $v === null ? '' : (string) $v;
        return [
            'id'             => (int) $c['id'],
            'title'          => $s($c['title'] ?? ''),
            'summary'        => $s($c['summary'] ?? ''),
            'status'         => $s($c['status'] ?? ''),
            'status_label'   => Labels::caseStatus($s($c['status'] ?? '')),
            'severity'       => $s($c['severity'] ?? ''),
            'severity_label' => Labels::CASE_SEVERITY[$s($c['severity'] ?? '')] ?? $s($c['severity'] ?? ''),
            'subject_user'   => $s($c['subject_user'] ?? ''),
            'subject_ip'     => $s($c['subject_ip'] ?? ''),
            'subject_host'   => $s($c['subject_host'] ?? ''),
            'evidence_query' => $s($c['evidence_query'] ?? ''),
            'source'         => $s($c['source'] ?? ''),
            'source_label'   => self::SOURCE[$s($c['source'] ?? '')] ?? $s($c['source'] ?? ''),
            'assignee'       => $s($c['assignee'] ?? ''),
            'resolution'     => $s($c['resolution'] ?? ''),
            'resolution_label' => $c['resolution'] === null || ($c['resolution'] ?? '') === '' ? '' : (Labels::RESOLUTION[(string) $c['resolution']] ?? (string) $c['resolution']),
            'created_by'     => $s($c['created_by'] ?? ''),
            'created_at'     => Time::toTaipei($c['created_at'] ?? null),
            'updated_at'     => Time::toTaipei($c['updated_at'] ?? null),
            'closed_at'      => ($c['closed_at'] ?? null) === null ? '' : Time::toTaipei((string) $c['closed_at']),
        ];
    }

    /**
     * @param array<string,mixed> $h
     * @return array<string,string>
     */
    public static function history(array $h): array
    {
        $s = static fn ($v): string => $v === null ? '' : (string) $v;
        $action = $s($h['action'] ?? '');
        return [
            'time'             => Time::toTaipei($h['ts'] ?? null),
            'actor'            => $s($h['actor'] ?? ''),
            'action'           => $action,
            'action_label'     => self::HISTORY_ACTION[$action] ?? $action,
            'from_label'       => $h['from_status'] === null ? '' : Labels::caseStatus((string) $h['from_status']),
            'to_label'         => $h['to_status'] === null ? '' : Labels::caseStatus((string) $h['to_status']),
            'resolution_label' => ($h['resolution'] ?? null) === null ? '' : (Labels::RESOLUTION[(string) $h['resolution']] ?? (string) $h['resolution']),
            'note'             => $s($h['note'] ?? ''),
        ];
    }

    /**
     * 供畫面顯示「這個案件現在可以做什麼」。
     *
     * @param string[] $allowed CaseService::get() 回傳的 allowed
     * @return array<int,array{to:string,label:string}>
     */
    public static function transitions(array $allowed): array
    {
        $out = [];
        foreach ($allowed as $to) {
            $out[] = ['to' => $to, 'label' => Labels::caseStatus($to)];
        }
        return $out;
    }
}
