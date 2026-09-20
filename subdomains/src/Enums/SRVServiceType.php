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

    public static function fromServer(Server $server): ?self
    {
        $eggId = (int) $server->egg_id;
        $minecraftEggs = config('subdomains.egg_mappings.minecraft', [12]);
        $fivemEggs = config('subdomains.egg_mappings.fivem', [11]);

        if (in_array($eggId, $minecraftEggs, true)) {
            return self::Minecraft;
        }

        if (in_array($eggId, $fivemEggs, true)) {
            return self::FiveM;
        }

        $eggUuid = $server->egg?->uuid;
        if ($eggUuid === '2f6a1c58-9e4b-4d7a-8c31-5b0e7f9a4d62') {
            return self::Minecraft;
        }
        if ($eggUuid === '238b3cb3-6796-4ba2-8403-5eb30faf1c86') {
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
        if (str_contains($eggName, 'minecraft') && !str_contains($eggName, 'bedrock')) {
            return self::Minecraft;
        }
        if (str_contains($eggName, 'fivem') || str_contains($eggName, 'redm')) {
            return self::FiveM;
        }

        return null;
    }
}
