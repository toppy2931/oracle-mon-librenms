<?php
declare(strict_types=1);

namespace Ioc;

/**
 * IOC 入口的例外。端點只把 getMessage() 這類「給使用者看的中文訊息」回給瀏覽器，
 * 其他例外一律記 log 並回通用錯誤（規格 IS-047：錯誤訊息不得揭露連線資訊或秘密）。
 */
class IocError extends \RuntimeException
{
}

final class AccessDenied extends IocError
{
}

final class NotFound extends IocError
{
}

final class InvalidTransition extends IocError
{
}

final class ValidationError extends IocError
{
    /** @var string[] */
    private array $errors;

    /** @param string[] $errors */
    public function __construct(array $errors)
    {
        parent::__construct(implode('；', $errors));
        $this->errors = $errors;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }
}

/** 查不到資料來源（Graylog）。入口要明確顯示「無法取得」，不能當成「沒有事件」（EVT-007、INT-017）。 */
final class GraylogUnavailable extends IocError
{
}
