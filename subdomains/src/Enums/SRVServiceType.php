<?php

namespace Boy132\Subdomains\Enums;

use App\Models\Server;
use Filament\Support\Contracts\HasLabel;

enum SRVServiceType: string implements HasLabel
{
    case Minecraft = '_minecraft._tcp';
    case FiveM = '_cfx._udp';
    case Mumble = '_mumble._tcp';
    case Factorio = '_factorio._udp';
    case Rust = '_rust._udp';
    case SCPSL = '_scpsl._udp';
    case Teamspeak = '_ts3._udp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Minecraft => 'Minecraft',
            self::FiveM => 'FiveM / RedM',
            default => str($this->name)->title(),
        };
    }

    public function getService(): string
    {
        $parts = explode('.', $this->value);
        return $parts[0] ?? '';
    }

    public function getProto(): string
    {
        $parts = explode('.', $this->value);
        return $parts[1] ?? '';
    }

    public function getConnectionCommand(string $fqdn): string
    {
        return match ($this) {
            self::FiveM => "connect {$fqdn}",
            default => $fqdn,
        };
    }

    /**
     * The SRV service for a server's egg: the egg ids configured per service first, then the
     * egg's `srv-*` features, then its tags (`cfx` = FiveM / RedM; `minecraft` = Minecraft: Java
     * Edition, unless the egg is Bedrock, which has no SRV lookup, or a proxy, which keeps the
     * plain record it always had), then its name.
     */
    public static function fromServer(Server $server): ?self
    {
        $eggId = (int) $server->egg_id;
        $minecraftEggs = (array) config('subdomains.egg_mappings.minecraft', []);
        $fivemEggs = (array) config('subdomains.egg_mappings.fivem', []);

        if (in_array($eggId, $minecraftEggs, true)) {
            return self::Minecraft;
        }

        if (in_array($eggId, $fivemEggs, true)) {
            return self::FiveM;
        }

        $features = $server->egg->features ?? [];
        if (is_array($features)) {
            if (in_array('srv-minecraft', $features, true)) {
                return self::Minecraft;
            }
            if (in_array('srv-fivem', $features, true) || in_array('srv-cfx', $features, true)) {
                return self::FiveM;
            }

            foreach ([self::Factorio, self::Rust, self::Mumble, self::SCPSL, self::Teamspeak] as $type) {
                $featureName = 'srv-' . strtolower($type->name);
                if (in_array($featureName, $features, true)) {
                    return $type;
                }
            }
        }

        $eggName = strtolower($server->egg?->name ?? '');
        $tags = array_map(static fn ($tag): string => strtolower(trim((string) $tag)), array_filter((array) ($server->egg?->tags ?? []), 'is_scalar'));

        if (in_array('cfx', $tags, true)) {
            return self::FiveM;
        }

        if (in_array('minecraft', $tags, true) && !in_array('bedrock', $tags, true) && !in_array('proxy', $tags, true) && !str_contains($eggName, 'bedrock')) {
            return self::Minecraft;
        }

        if (str_contains($eggName, 'minecraft') && !str_contains($eggName, 'bedrock')) {
            return self::Minecraft;
        }
        if (str_contains($eggName, 'fivem') || str_contains($eggName, 'redm')) {
            return self::FiveM;
        }

        return null;
    }
}
