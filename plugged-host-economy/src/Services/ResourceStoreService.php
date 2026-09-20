<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Economy\Models\UserCredits;

class ResourceStoreService
{
    public function getMaxCpu(): int
    {
        return (int) config('plugged-host-economy.credits.max_cpu', 600);
    }

    public function getMaxMemory(): int
    {
        return (int) config('plugged-host-economy.credits.max_memory', 24576);
    }

    public function getMaxDisk(): int
    {
        return (int) config('plugged-host-economy.credits.max_disk', 102400);
    }

    public function getPriceForCpu(int $amountPercent): int
    {
        $basePrice50 = (int) config('plugged-host-economy.credits.price_cpu_50', 100);

        return (int) ceil(($amountPercent / 50) * $basePrice50);
    }

    public function getPriceForMemory(int $amountMb): int
    {
        $basePrice1024 = (int) config('plugged-host-economy.credits.price_memory_1024', 150);

        return (int) ceil(($amountMb / 1024) * $basePrice1024);
    }

    public function getPriceForDisk(int $amountMb): int
    {
        $basePrice5120 = (int) config('plugged-host-economy.credits.price_disk_5120', 75);

        return (int) ceil(($amountMb / 5120) * $basePrice5120);
    }

    public function getPriceForServerSlot(int $slots = 1): int
    {
        $basePriceSlot = (int) config('plugged-host-economy.credits.price_server_slot', 250);

        return $slots * $basePriceSlot;
    }

    /**
     * @return array{
     *     cpu: int,
     *     memory: int,
     *     disk: int,
     *     server_limit: ?int,
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
        $limits = RoleResourceLimits::forUser($user);
        $userCredits = UserCredits::forUser($user);

        $maxCpu = $this->getMaxCpu();
        $maxMemory = $this->getMaxMemory();
        $maxDisk = $this->getMaxDisk();

        $cpu = $limits ? $limits->cpu : 0;
        $memory = $limits ? $limits->memory : 0;
        $disk = $limits ? $limits->disk : 0;
        $serverLimit = $limits ? $limits->server_limit : null;

        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'server_limit' => $serverLimit,
            'max_cpu' => $maxCpu,
            'max_memory' => $maxMemory,
            'max_disk' => $maxDisk,
            'can_buy_cpu' => $cpu > 0 && $cpu < $maxCpu,
            'can_buy_memory' => $memory > 0 && $memory < $maxMemory,
            'can_buy_disk' => $disk > 0 && $disk < $maxDisk,
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

        $cost = $this->getPriceForCpu($amountPercent);
        $maxCpu = $this->getMaxCpu();
        $userCredits = UserCredits::forUser($user);

        if (!$userCredits || !$userCredits->canAfford($cost)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
        }

        $limits = RoleResourceLimits::forUser($user);
        if ($limits && $limits->cpu > 0 && ($limits->cpu + $amountPercent > $maxCpu)) {
            $allowed = max(0, $maxCpu - $limits->cpu);
            if ($allowed <= 0) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.max_cpu_reached')];
            }
            $amountPercent = $allowed;
            $cost = $this->getPriceForCpu($amountPercent);
        }

        return DB::transaction(function () use ($user, $userCredits, $cost, $amountPercent) {
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

        $cost = $this->getPriceForMemory($amountMb);
        $maxMemory = $this->getMaxMemory();
        $userCredits = UserCredits::forUser($user);

        if (!$userCredits || !$userCredits->canAfford($cost)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
        }

        $limits = RoleResourceLimits::forUser($user);
        if ($limits && $limits->memory > 0 && ($limits->memory + $amountMb > $maxMemory)) {
            $allowed = max(0, $maxMemory - $limits->memory);
            if ($allowed <= 0) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.max_memory_reached')];
            }
            $amountMb = $allowed;
            $cost = $this->getPriceForMemory($amountMb);
        }

        $suffix = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        return DB::transaction(function () use ($user, $userCredits, $cost, $amountMb, $suffix) {
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

        $cost = $this->getPriceForDisk($amountMb);
        $maxDisk = $this->getMaxDisk();
        $userCredits = UserCredits::forUser($user);

        if (!$userCredits || !$userCredits->canAfford($cost)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
        }

        $limits = RoleResourceLimits::forUser($user);
        if ($limits && $limits->disk > 0 && ($limits->disk + $amountMb > $maxDisk)) {
            $allowed = max(0, $maxDisk - $limits->disk);
            if ($allowed <= 0) {
                return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.max_disk_reached')];
            }
            $amountMb = $allowed;
            $cost = $this->getPriceForDisk($amountMb);
        }

        $suffix = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        return DB::transaction(function () use ($user, $userCredits, $cost, $amountMb, $suffix) {
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

    /**
     * @return array{success: bool, message: string}
     */
    public function purchaseServerSlot(User $user, int $slots = 1): array
    {
        if ($slots <= 0) {
            return ['success' => false, 'message' => 'Invalid server slot amount.'];
        }

        $cost = $this->getPriceForServerSlot($slots);
        $userCredits = UserCredits::forUser($user);

        if (!$userCredits || !$userCredits->canAfford($cost)) {
            return ['success' => false, 'message' => trans('plugged-host-economy::strings.credits.insufficient_credits')];
        }

        return DB::transaction(function () use ($user, $userCredits, $cost, $slots) {
            $userCredits->deductCredits(
                $cost,
                'resource_purchase',
                trans('plugged-host-economy::strings.credits.tx_server_slot_purchase', ['slots' => $slots]),
                ['resource' => 'server_slot', 'amount' => $slots, 'cost' => $cost]
            );

            $userCredits->addExtraResources(serverLimit: $slots);

            return [
                'success' => true,
                'message' => trans('plugged-host-economy::strings.credits.server_slot_purchased', ['slots' => $slots, 'cost' => $cost]),
            ];
        });
    }
}
