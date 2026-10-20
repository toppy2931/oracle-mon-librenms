<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 整體風險分數（0–100，**越高越好**，對應簡報的「整體風險概況」儀表）。【提案版，數字都可以調】
 *
 * 三個分項，各自 0–100，再依權重加權平均：
 *   設備與服務（權重 40）：正常設備數 ÷ 設備總數
 *   事件處置　（權重 35）：100 － 未結案件的扣分（高 15、中 6、低 2；扣到 0 為止）
 *   資料來源　（權重 25）：正常接收的來源類型 ÷ 有資料的來源類型（還沒接入的不算進分母，與「今日整體運作」一致）
 *
 * 某個分項的資料取不到（或沒有可比的對象，例如沒有任何設備）就不納入，權重由其他分項重新分配，
 * 並在 missing 列出；三個分項都沒有資料就是「無法計算」，不是 100 分，也不是 0 分（OPS-006）。
 * 等級：分數 ≥ 低風險門檻是低風險、≥ 中等風險門檻是中等風險、其餘高風險。門檻預設 85／65，
 * 使用者可在入口的「設定」頁自己調（SettingsService 存取與驗證）；門檻讀不到就只算分數、不判定等級。
 */
final class RiskScore
{
    public const WEIGHTS = ['devices' => 40, 'cases' => 35, 'sources' => 25];
    public const PART_LABEL = ['devices' => '設備與服務', 'cases' => '事件處置', 'sources' => '資料來源'];
    /** 每件未結案扣幾分。 */
    public const CASE_PENALTY = ['high' => 15, 'medium' => 6, 'low' => 2];
    /** 等級門檻的預設值：low＝低風險的最低分，medium＝中等風險的最低分。 */
    public const DEFAULT_THRESHOLDS = ['low' => 85, 'medium' => 65];
    public const LEVEL_LABEL = ['low' => '低風險', 'medium' => '中等風險', 'high' => '高風險'];

    /**
     * @param array{total:int,up:int}|null $devices 設備總數與正常數；null＝讀不到
     * @param array<string,int>|null $openBySeverity 未結案件數，鍵為 high／medium／low；null＝讀不到
     * @param array{present:int,ok:int}|null $sources 有資料的來源類型數與其中正常接收的；null＝讀不到
     * @param array{low:int,medium:int}|null $thresholds 等級門檻；null＝讀不到（有分數但 level 是 null，不能套預設值冒充）
     * @return array{score:?int,level:?string,level_label:?string,thresholds:?array{low:int,medium:int},parts:array<string,array<string,mixed>>,missing:string[]}
     */
    public static function compute(?array $devices, ?array $openBySeverity, ?array $sources, ?array $thresholds): array
    {
        $scores = [
            'devices' => $devices !== null && $devices['total'] > 0 ? 100 * $devices['up'] / $devices['total'] : null,
            'cases'   => $openBySeverity === null ? null : max(0.0, 100 - self::penalty($openBySeverity)),
            'sources' => $sources !== null && $sources['present'] > 0 ? 100 * $sources['ok'] / $sources['present'] : null,
        ];

        $parts = [];
        $missing = [];
        $sum = $weight = 0.0;
        foreach (self::WEIGHTS as $key => $w) {
            $s = $scores[$key];
            $parts[$key] = ['label' => self::PART_LABEL[$key], 'weight' => $w, 'score' => $s === null ? null : (int) round($s)];
            if ($s === null) {
                $missing[] = self::PART_LABEL[$key];
                continue;
            }
            $sum    += $w * $s;
            $weight += $w;
        }
        if ($weight === 0.0) {
            return ['score' => null, 'level' => null, 'level_label' => null, 'thresholds' => $thresholds, 'parts' => $parts, 'missing' => $missing];
        }
        $score = (int) round($sum / $weight);
        $level = $thresholds === null ? null : self::level($score, $thresholds);
        return ['score' => $score, 'level' => $level, 'level_label' => $level === null ? null : self::LEVEL_LABEL[$level],
                'thresholds' => $thresholds, 'parts' => $parts, 'missing' => $missing];
    }

    /** @param array{low:int,medium:int} $thresholds */
    public static function level(int $score, array $thresholds): string
    {
        return $score >= $thresholds['low'] ? 'low' : ($score >= $thresholds['medium'] ? 'medium' : 'high');
    }

    /** @param array<string,int> $open */
    private static function penalty(array $open): int
    {
        $p = 0;
        foreach (self::CASE_PENALTY as $severity => $points) {
            $p += (int) ($open[$severity] ?? 0) * $points;
        }
        return $p;
    }
}
