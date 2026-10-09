<?php

namespace PluggedHost\Noprofit\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Support\Plugins;

class NoprofitDoctorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Doctor::check('plugged-host-noprofit', 'Secrets shared with noprofit.space', function (): array {
            $missing = array_keys(array_filter([
                'NOPROFIT_SSO_SECRET' => blank(config('plugged-host-noprofit.sso_secret')),
                'NOPROFIT_MIGRATION_SECRET' => blank(config('plugged-host-noprofit.migration_secret')),
                'NOPROFIT_SYNC_SECRET' => blank(config('plugged-host-noprofit.sync_secret')),
            ]));

            return $missing === []
                ? Doctor::ok('accounts at ' . config('plugged-host-noprofit.accounts_url'))
                : Doctor::fail('not set: ' . implode(', ', $missing) . ' (same values as in noprofit.space\'s .env)');
        });

        Doctor::check('plugged-host-noprofit', 'neo\'s AI gateway secret', function (): array {
            if (!Plugins::enabled('plugged-host-ai')) {
                return Doctor::ok('plugged-host-ai is off');
            }

            return strlen((string) config('plugged-host-noprofit.neo_gateway_secret')) >= 32
                ? Doctor::ok('set')
                : Doctor::fail('NEO_GATEWAY_SECRET is not set (at least 32 characters, same value as in noprofit.space\'s .env): neo cannot think and rux has no AI');
        });
    }
}
