<?php

namespace PluggedHost\Servers\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use PluggedHost\Servers\Support\AccountAllowance;

/**
 * The resource presets ("plans") offered as tiles during server creation.
 *
 * A preset that does not fit inside the user's remaining role allowance is returned
 * as unavailable rather than omitted, so the user can see why it is out of reach
 * instead of wondering where it went.
 */
class PlanCatalog
{
    /**
     * @return Collection<int, array{id: string, label: string, cpu: int, memory: int, disk: int, available: bool}>
     */
    public function forUser(?User $user, ?AccountAllowance $limits = null): Collection
    {
        $limits ??= AccountAllowance::forUser($user);

        return collect((array) config('plugged-host-servers.user-creatable-servers.plans', []))
            ->filter(fn ($plan) => is_array($plan))
            ->map(function (array $plan) use ($user, $limits) {
                $cpu = (int) ($plan['cpu'] ?? 0);
                $memory = (int) ($plan['memory'] ?? 0);
                $disk = (int) ($plan['disk'] ?? 0);

                return [
                    'id' => (string) ($plan['id'] ?? ''),
                    'label' => $this->translate($plan['label'] ?? null) ?? (string) ($plan['id'] ?? ''),
                    'cpu' => $cpu,
                    'memory' => $memory,
                    'disk' => $disk,
                    'available' => $limits?->canCreateServer($user, $cpu, $memory, $disk) ?? false,
                ];
            })
            ->filter(fn (array $plan) => $plan['id'] !== '')
            ->values();
    }

    /**
     * @return array{id: string, label: string, cpu: int, memory: int, disk: int, available: bool}|null
     */
    public function find(?User $user, ?string $id, ?AccountAllowance $limits = null): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->forUser($user, $limits)->firstWhere('id', $id);
    }

    private function translate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $translated = trans($value);

        return is_string($translated) ? $translated : $value;
    }
}
