<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 預留 AI 分析契約。輸入只能來自 BehaviorAnalyzer::aiFeatures() 的去識別資料；
 * 回傳的說明與調查建議不得改變規則警示、正式事件案或設備設定。
 */
interface BehaviorAiProvider
{
    public const CONTRACT_VERSION = BehaviorAnalyzer::CONTRACT_VERSION;

    /** @param array<string,int|float|string> $features @return array{risk_explanation:string,investigation:string}|null */
    public function analyze(array $features): ?array;
}
