<?php

namespace PluggedHost\Noprofit\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Doctor\Doctor;

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
    }
}
