<?php
declare(strict_types=1);

namespace Ioc;

/** 固定特權入口；秘密僅透過 stdin，非阻塞 I/O 有整體期限與輸出上限。 */
final class ConfigBackupGateway
{
    public function __invoke(array $request): array
    {
        $process = null;
        $pipes = [];
        $deadline = microtime(true) + 8.0;
        try {
            $input = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($input) > 262144) {
                throw new \RuntimeException();
            }
            $process = @proc_open(
                ['sudo', '-n', '/opt/oracle-mon/admin/manage-config-backup.sh'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, null, null, ['bypass_shell' => true]
            );
            if (!is_resource($process)) {
                throw new \RuntimeException();
            }
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $offset = 0;
            $output = '';
            $error = '';
            $stdinOpen = true;
            $exitCode = null;
            while (true) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException();
                }
                if ($stdinOpen) {
                    $written = @fwrite($pipes[0], substr($input, $offset, 8192));
                    if ($written === false) {
                        throw new \RuntimeException();
                    }
                    $offset += $written;
                    if ($offset === strlen($input)) {
                        fclose($pipes[0]);
                        $stdinOpen = false;
                    }
                }
                $output .= stream_get_contents($pipes[1], 8192);
                $error .= stream_get_contents($pipes[2], 8192);
                if (strlen($output) > 1048576 || strlen($error) > 65536) {
                    throw new \RuntimeException();
                }
                $status = proc_get_status($process);
                if (!$status['running'] && $exitCode === null) {
                    $exitCode = $status['exitcode'];
                }
                // 程序退出後仍讀完有上限的管線，不能截掉最後一批 JSON。
                if ($exitCode !== null && feof($pipes[1]) && feof($pipes[2])) {
                    if ($offset !== strlen($input) || !in_array($exitCode, [0, 1], true)) {
                        throw new \RuntimeException();
                    }
                    $response = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($response) || !is_bool($response['ok'] ?? null)
                        || ($exitCode === 1 && $response['ok'] !== false)) {
                        throw new \RuntimeException();
                    }
                    return $response;
                }
                usleep(10000);
            }
        } catch (\Throwable $e) {
            throw new IocError('無法完成設備備份要求，請確認備份服務狀態');
        } finally {
            if (is_resource($process)) {
                $state = proc_get_status($process);
                if ($state['running']) {
                    proc_terminate($process, 9);
                }
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }
}
