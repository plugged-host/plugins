<?php

namespace PluggedHost\Storage\Support;

/**
 * One box running the storage agent. There are two:
 *
 *  - `storage`, the storage box: in a datacenter, the most stable, but slow (one thread, so
 *    packing an archive takes a long time).
 *  - `backup`, the backup server: less stable, much faster. Optional.
 *
 * Both copy every server independently with the same agent; each has its own address, HMAC
 * secret and mirror key. Downloads and failover restores come from the backup server while its
 * copy is as fresh as the storage box's (StorageService::servingBox()), else from the storage box.
 */
final class StorageBox
{
    public const STORAGE = 'storage';

    public const BACKUP = 'backup';

    /** .env keys per box: url, secret, mirror key, mirror key's addresses. */
    private const ENV = [
        self::STORAGE => ['STORAGE_AGENT_URL', 'STORAGE_AGENT_SECRET', 'STORAGE_MIRROR_PUBLIC_KEY', 'STORAGE_MIRROR_FROM'],
        self::BACKUP => ['STORAGE_BACKUP_AGENT_URL', 'STORAGE_BACKUP_AGENT_SECRET', 'STORAGE_BACKUP_MIRROR_PUBLIC_KEY', 'STORAGE_BACKUP_MIRROR_FROM'],
    ];

    /** Config keys per box (config/plugged-host-storage.php): url, secret, mirror key, its addresses. */
    private const CONFIG = [
        self::STORAGE => ['agent_url', 'agent_secret', 'mirror_public_key', 'mirror_from'],
        self::BACKUP => ['backup_agent_url', 'backup_agent_secret', 'backup_mirror_public_key', 'backup_mirror_from'],
    ];

    /** Comma-separated IPv4/IPv6 addresses or CIDR blocks: what sshd's from= takes, nothing that could quote out of it. */
    public const FROM_PATTERN = '/^[0-9A-Fa-f:.\/]+(,[0-9A-Fa-f:.\/]+)*$/';

    private function __construct(public readonly string $name) {}

    /** @return list<string> */
    public static function names(): array
    {
        return [self::STORAGE, self::BACKUP];
    }

    public static function named(string $name): ?self
    {
        return isset(self::ENV[$name]) ? new self($name) : null;
    }

    public function label(): string
    {
        return $this->name === self::BACKUP ? 'backup server' : 'storage box';
    }

    public function url(): string
    {
        return rtrim((string) config('plugged-host-storage.' . self::CONFIG[$this->name][0], ''), '/');
    }

    public function secret(): string
    {
        return (string) config('plugged-host-storage.' . self::CONFIG[$this->name][1], '');
    }

    public function mirrorKey(): string
    {
        return trim((string) config('plugged-host-storage.' . self::CONFIG[$this->name][2], ''));
    }

    /**
     * The addresses this box reaches the nodes from, as an authorized_keys from= list ('' when
     * unset or not a valid list). Nodes then accept the mirror key only from there, so the key is
     * useless anywhere else.
     */
    public function mirrorFrom(): string
    {
        $from = str_replace(' ', '', (string) config('plugged-host-storage.' . self::CONFIG[$this->name][3], ''));
        if ($from === '' || preg_match(self::FROM_PATTERN, $from) !== 1) {
            return '';
        }
        foreach (explode(',', $from) as $entry) {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $v6 = str_contains($ip, ':');
            if (filter_var($ip, FILTER_VALIDATE_IP) === false
                || ($bits !== null && (! ctype_digit($bits) || (int) $bits > ($v6 ? 128 : 32)))) {
                return '';
            }
        }

        return $from;
    }

    /** The line nodes put in authorized_keys: the mirror key, pinned to mirrorFrom() when set. */
    public function authorizedKey(): string
    {
        $key = $this->mirrorKey();
        $from = $this->mirrorFrom();

        return $from !== '' ? "from=\"{$from}\" {$key}" : $key;
    }

    public function configured(): bool
    {
        return $this->url() !== '' && $this->secret() !== '';
    }

    /** The host part of the agent's address (lower case), '' when unset. */
    public function host(): string
    {
        return strtolower((string) parse_url($this->url(), PHP_URL_HOST));
    }

    public function urlEnv(): string
    {
        return self::ENV[$this->name][0];
    }

    public function secretEnv(): string
    {
        return self::ENV[$this->name][1];
    }

    public function mirrorKeyEnv(): string
    {
        return self::ENV[$this->name][2];
    }

    public function mirrorFromEnv(): string
    {
        return self::ENV[$this->name][3];
    }

    public function secretConfig(): string
    {
        return 'plugged-host-storage.' . self::CONFIG[$this->name][1];
    }
}
