<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Servers\Support\AccountAllowance;

class ResourceStoreService
{
    /**
     * CPU, memory and disk belong to the account's plan on noprofit.space: the caps come with
     * the plan and the prices with the sync (NoprofitCreditSync). Without both, the store does
     * not sell them, rather than guess at numbers noprofit.space may have changed.
     */
    public function sellsComputeTo(User $user): bool
    {
        return NoprofitCreditSync::plan($user) !== null && NoprofitCreditSync::pricing() !== null;
    }

    public function getMaxCpu(User $user): int
    {
        return (int) (NoprofitCreditSync::plan($user)['max_cpu'] ?? 0);
    }

    public function getMaxMemory(User $user): int
    {
        return (int) (NoprofitCreditSync::plan($user)['max_memory'] ?? 0);
    }

    public function getMaxDisk(User $user): int
    {
        return (int) (NoprofitCreditSync::plan($user)['max_disk'] ?? 0);
    }

    /**
     * The step CPU (%), memory or disk (MB) is sold in -- the unit noprofit.space prices -- or 0
     * while no prices are known.
     */
    public function step(string $resource): int
    {
        $key = match ($resource) {
            'cpu' => 'cpu_unit_percent',
            'memory' => 'memory_unit_mb',
            'disk' => 'storage_unit_mb',
            default => null,
        };

        return $key === null ? 0 : max(0, (int) (NoprofitCreditSync::pricing()[$key] ?? 0));
    }

    public function getPriceForCpu(int $amountPercent): int
    {
        return $this->price('cpu_unit_percent', 'cpu_price_credits', $amountPercent);
    }

    public function getPriceForMemory(int $amountMb): int
    {
        return $this->price('memory_unit_mb', 'memory_price_credits', $amountMb);
    }

    public function getPriceForDisk(int $amountMb): int
    {
        return $this->price('storage_unit_mb', 'storage_price_credits', $amountMb);
    }

    /**
     * The noprofit.space price of $amount (a unit is the step bought at once), or 0 while no
     * prices are known -- callers check sellsComputeTo() before charging anything.
     */
    private function price(string $unitKey, string $priceKey, int $amount): int
    {
        $pricing = NoprofitCreditSync::pricing();
        $unit = (int) ($pricing[$unitKey] ?? 0);

        if ($unit <= 0 || !isset($pricing[$priceKey])) {
            return 0;
        }

        return (int) ceil(($amount / $unit) * (int) $pricing[$priceKey]);
    }

    /**
     * @return array{
     *     cpu: int,
     *     memory: int,
     *     disk: int,
     *     max_cpu: int,
     *     max_memory: int,
     *     max_disk: int,
     *     can_buy_cpu: bool,
     *     can_buy_memory: bool,
     *     can_buy_disk: bool,
     *     user_credits: int
     * }
     */
    public function getUserResourceSummary(User $user): array
    {
        $limits = AccountAllowance::forUser($user);
        $userCredits = UserCredits::forUser($user);

        $maxCpu = $this->getMaxCpu($user);
        $maxMemory = $this->getMaxMemory($user);
        $maxDisk = $this->getMaxDisk($user);

        $cpu = $limits ? $limits->cpu : 0;
        $memory = $limits ? $limits->memory : 0;
        $disk = $limits ? $limits->disk : 0;
        $sells = $this->sellsComputeTo($user);

        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'max_cpu' => $maxCpu,
            'max_memory' => $maxMemory,
            'max_disk' => $maxDisk,
            'can_buy_cpu' => $sells && $cpu > 0 && $cpu < $maxCpu,
            'can_buy_memory' => $sells && $memory > 0 && $memory < $maxMemory,
            'can_buy_disk' => $sells && $disk > 0 && $disk < $maxDisk,
            'user_credits' => $userCredits ? $userCredits->credits : 0,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function purchaseCpu(User $user, int $amountPercent): array
    {
        if ($amountPercent <= 0) {
            return ['success' => false, 'message' => 'Invalid CPU amount.'];
        }

        if (!$this->sellsComputeTo($user)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.store_unavailable')];
        }

        $cost = $this->getPriceForCpu($amountPercent);
        $maxCpu = $this->getMaxCpu($user);
        $userCredits = UserCredits::forUser($user);

        if (!$userCredits || !$userCredits->canAfford($cost)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
        }

        $limits = AccountAllowance::forUser($user);
        if ($limits && $limits->cpu > 0 && ($limits->cpu + $amountPercent > $maxCpu)) {
            $allowed = max(0, $maxCpu - $limits->cpu);
            if ($allowed <= 0) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.max_cpu_reached')];
            }
            $amountPercent = $allowed;
            $cost = $this->getPriceForCpu($amountPercent);
        }

        return DB::transaction(function () use ($userCredits, $cost, $amountPercent) {
            $userCredits->deductCredits(
                $cost,
                'resource_purchase',
                trans('plugged-host-economy::strings.credits.tx_cpu_purchase', ['amount' => $amountPercent]),
                ['resource' => 'cpu', 'amount' => $amountPercent, 'cost' => $cost]
            );

            $userCredits->addExtraResources(cpu: $amountPercent);

            return [
                'success' => true,
                'message' => trans('plugged-host-economy::strings.credits.cpu_purchased', ['amount' => $amountPercent, 'cost' => $cost]),
            ];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function purchaseMemory(User $user, int $amountMb): array
    {
        if ($amountMb <= 0) {
            return ['success' => false, 'message' => 'Invalid memory amount.'];
        }

        if (!$this->sellsComputeTo($user)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.store_unavailable')];
        }

        $cost = $this->getPriceForMemory($amountMb);
        $maxMemory = $this->getMaxMemory($user);
        $userCredits = UserCredits::forUser($user);

        if (!$userCredits || !$userCredits->canAfford($cost)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
        }

        $limits = AccountAllowance::forUser($user);
        if ($limits && $limits->memory > 0 && ($limits->memory + $amountMb > $maxMemory)) {
            $allowed = max(0, $maxMemory - $limits->memory);
            if ($allowed <= 0) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.max_memory_reached')];
            }
            $amountMb = $allowed;
            $cost = $this->getPriceForMemory($amountMb);
        }

        $suffix = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        return DB::transaction(function () use ($userCredits, $cost, $amountMb, $suffix) {
            $userCredits->deductCredits(
                $cost,
                'resource_purchase',
                trans('plugged-host-economy::strings.credits.tx_memory_purchase', ['amount' => $amountMb, 'unit' => $suffix]),
                ['resource' => 'memory', 'amount' => $amountMb, 'cost' => $cost]
            );

            $userCredits->addExtraResources(memory: $amountMb);

            return [
                'success' => true,
                'message' => trans('plugged-host-economy::strings.credits.memory_purchased', ['amount' => $amountMb, 'unit' => $suffix, 'cost' => $cost]),
            ];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function purchaseDisk(User $user, int $amountMb): array
    {
        if ($amountMb <= 0) {
            return ['success' => false, 'message' => 'Invalid disk space amount.'];
        }

        if (!$this->sellsComputeTo($user)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.store_unavailable')];
        }

        $cost = $this->getPriceForDisk($amountMb);
        $maxDisk = $this->getMaxDisk($user);
        $userCredits = UserCredits::forUser($user);

        if (!$userCredits || !$userCredits->canAfford($cost)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
        }

        $limits = AccountAllowance::forUser($user);
        if ($limits && $limits->disk > 0 && ($limits->disk + $amountMb > $maxDisk)) {
            $allowed = max(0, $maxDisk - $limits->disk);
            if ($allowed <= 0) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.max_disk_reached')];
            }
            $amountMb = $allowed;
            $cost = $this->getPriceForDisk($amountMb);
        }

        $suffix = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        return DB::transaction(function () use ($userCredits, $cost, $amountMb, $suffix) {
            $userCredits->deductCredits(
                $cost,
                'resource_purchase',
                trans('plugged-host-economy::strings.credits.tx_disk_purchase', ['amount' => $amountMb, 'unit' => $suffix]),
                ['resource' => 'disk', 'amount' => $amountMb, 'cost' => $cost]
            );

            $userCredits->addExtraResources(disk: $amountMb);

            return [
                'success' => true,
                'message' => trans('plugged-host-economy::strings.credits.disk_purchased', ['amount' => $amountMb, 'unit' => $suffix, 'cost' => $cost]),
            ];
        });
    }
}
