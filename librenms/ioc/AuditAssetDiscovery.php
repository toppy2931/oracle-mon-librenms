<?php
declare(strict_types=1);

namespace Ioc;

/** One inventory interpretation shared by web and scheduled importer. */
final class AuditAssetDiscovery
{
    private const LINUX_OS = ['linux', 'ubuntu', 'debian', 'centos', 'rhel', 'redhat', 'fedora',
        'almalinux', 'rocky', 'rockylinux', 'suse', 'opensuse'];
    private const NETWORK_TYPES = ['network', 'firewall', 'wireless', 'loadbalancer'];

    /** @return array{id:int,name:string,address:string,kind:string,os_confirmed:bool}|null */
    public static function fromLibreNms(object $device): ?array
    {
        $type = strtolower((string) ($device->type ?? ''));
        $os = strtolower((string) ($device->os ?? ''));
        $isLinux = in_array($os, self::LINUX_OS, true)
            || preg_match('/\A(?:linux|ubuntu|debian|centos|rhel|redhat|fedora|almalinux|rocky|suse)[._-]/', $os) === 1;
        if (in_array($type, self::NETWORK_TYPES, true)) {
            $kind = 'network';
        } elseif ($isLinux || $type === 'server') {
            $kind = 'linux';
        } else {
            return null;
        }
        $address = Fleet::deviceIp($device->overwrite_ip ?? null, $device->ip ?? null, $device->hostname ?? null)
            ?: (string) ($device->hostname ?? '');
        if ($address === '') { return null; }
        return ['id' => (int) $device->device_id,
            'name' => (string) ($device->display ?: ($device->sysName ?: $device->hostname)),
            'address' => $address, 'kind' => $kind,
            'os_confirmed' => $kind === 'network' || $isLinux];
    }
}
