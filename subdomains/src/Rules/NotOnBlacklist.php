<?php

namespace Boy132\Subdomains\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

class NotOnBlacklist implements ValidationRule
{
    /**
     * Permanent core infrastructure blacklist that must NEVER be claimed by users.
     */
    protected const CORE_BLACKLIST = [
        'www', 'panel', 'admin', 'api', 'auth', 'login', 'billing', 'client',
        'node*', 'dash*', 'status', 'cp', 'webmail', 'mail', 'email', 'smtp',
        'pop', 'imap', 'ns*', 'dns*', 'vpn', 'ssl', 'secure', 'autodiscover',
        'ftp', 'ssh', 'sftp', 'pma', 'mysql', 'database', 'proxy', 'gateway',
        'cloud*', 'dev', 'stage', 'staging', 'test', 'prod', 'internal',
        'support', 'help', 'shop', 'store', 'docs', 'legal', 'terms', 'privacy',
        'cdn', 'assets', 'static', 'app', 'bot', 'discord', 'fivem', 'minecraft',
        'mc', 'plugged',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail(trans('validation.string'));
            return;
        }

        $value = strtolower(trim((string) $value));
        $rawBlacklist = config('subdomains.subdomain_blacklist') ?? config('subdomains.blacklist') ?? '';
        $configuredBlacklist = is_string($rawBlacklist) ? array_filter(array_map('trim', explode(',', $rawBlacklist))) : [];
        $blacklist = array_unique(array_merge(self::CORE_BLACKLIST, $configuredBlacklist));

        foreach ($blacklist as $check) {
            if (Str::is(strtolower($check), $value, true)) {
                $fail(trans('subdomains::strings.validation.on_blacklist'));
                return;
            }
        }
    }
}
