<?php
declare(strict_types=1);

namespace Ioc;

/** 私有、不可覆寫的證據快照。檔名由伺服器產生，從不接受瀏覽器路徑。 */
final class AuditSnapshotStore
{
    private string $root;

    public function __construct(string $root = '/var/lib/oracle-mon/ioc-evidence')
    {
        $this->root = rtrim($root, '/\\');
    }

    /** @return array{name:string,sha256:string,bytes:int} */
    public function put(string $content): array
    {
        $size = strlen($content);
        if ($size < 1 || $size > 2 * 1024 * 1024 || !is_dir($this->root)
            || is_link($this->root) || !is_writable($this->root)
            || (fileperms($this->root) & 0077) !== 0) {
            throw new IocError('證據儲存空間不可用或快照超過 2 MiB');
        }
        $name = bin2hex(random_bytes(16)) . '.json';
        $tmp = $this->root . '/.' . $name . '.tmp';
        $final = $this->root . '/' . $name;
        $fh = @fopen($tmp, 'x+b');
        if ($fh === false) {
            throw new IocError('無法建立證據快照');
        }
        try {
            if (!@chmod($tmp, 0600)) {
                throw new IocError('證據快照檔案權限不安全');
            }
            clearstatcache(true, $tmp);
            if ((fileperms($tmp) & 0077) !== 0) {
                throw new IocError('證據快照檔案權限不安全');
            }
            $offset = 0;
            while ($offset < $size) {
                $written = fwrite($fh, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new IocError('無法寫入完整證據快照');
                }
                $offset += $written;
            }
            if (!fflush($fh)) {
                throw new IocError('無法完成證據快照');
            }
        } catch (\Throwable $e) {
            fclose($fh);
            @unlink($tmp);
            throw $e;
        }
        fclose($fh);
        if (!@rename($tmp, $final)) {
            @unlink($tmp);
            throw new IocError('無法保存證據快照');
        }
        return ['name' => $name, 'sha256' => hash('sha256', $content), 'bytes' => $size];
    }

    public function get(string $name, string $digest, int $bytes): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\.json\z/D', $name)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $digest) || $bytes < 1 || $bytes > 2 * 1024 * 1024) {
            throw new IocError('證據中繼資料不正確');
        }
        $path = $this->root . '/' . $name;
        clearstatcache(true, $this->root);
        clearstatcache(true, $path);
        if (!is_dir($this->root) || is_link($this->root) || (fileperms($this->root) & 0077) !== 0
            || is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0
            || filesize($path) !== $bytes) {
            throw new IocError('證據快照不存在或已遭更動');
        }
        $content = @file_get_contents($path);
        if ($content === false || !hash_equals($digest, hash('sha256', $content))) {
            throw new IocError('證據快照雜湊驗證失敗');
        }
        return $content;
    }

    /** 只供資料庫交易失敗後移除當次新建、尚未登錄的檔案。 */
    public function discard(string $name): void
    {
        if (preg_match('/\A[a-f0-9]{32}\.json\z/D', $name)) {
            @unlink($this->root . '/' . $name);
        }
    }

    public function freeBytes(): ?int
    {
        $free = @disk_free_space($this->root);
        return $free === false ? null : (int) $free;
    }
}
