<?php

namespace PluggedHost\Devbox\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\DevboxDns;
use PluggedHost\Devbox\Services\DevboxPki;
use PluggedHost\Devbox\Support\DevboxWeb;
use Throwable;

/**
 * VS Code in the browser, the panel's part of setting it up (safe to run again):
 *
 * - the devbox CA and the edge's client certificate (DevboxPki), which nginx reads;
 * - the DNS record of every running devbox (<slug>.<web_domain>, proxied; DevboxDns). New ones get
 *   theirs when they start, and lose it when they are deleted. No wildcard on the domain.
 *
 * nginx (docs/templates/nginx/devbox-web.conf) and its origin certificate for *.<web_domain> are
 * set up by hand once; the command prints the paths nginx needs.
 */
class DevboxWebSetupCommand extends Command
{
    protected $signature = 'plugged-host:devbox-web-setup {--no-dns : Leave DNS records alone}';

    protected $description = 'Set up VS Code in the browser for devboxes: CA, edge certificate and the running devboxes\' DNS records.';

    public function handle(DevboxPki $pki, DevboxDns $dns): int
    {
        $domain = DevboxWeb::domain();
        if ($domain === '') {
            $this->error('Set DEVBOX_WEB_DOMAIN first (e.g. onplugged.host), in .env or the plugin settings.');

            return self::FAILURE;
        }

        $result = $pki->ensure();
        $this->info(($result['created'] ? 'Created' : 'Kept') . " the devbox CA and edge certificate in {$result['dir']}");

        if (!$this->option('no-dns')) {
            if (DevboxDns::token() === '') {
                $this->error('No Cloudflare token: the subdomains plugin\'s is used when it is installed, otherwise set DEVBOX_CLOUDFLARE_TOKEN.');

                return self::FAILURE;
            }

            $running = Devbox::query()->whereNotNull('token_hash')->whereNotNull('server_id')->get();
            foreach ($running as $devbox) {
                try {
                    $dns->ensure($devbox);
                    $this->info('DNS: ' . DevboxWeb::host($devbox) . ' → ' . config('plugged-host-devbox.web_origin_ip'));
                } catch (Throwable $exception) {
                    $this->error('DNS: ' . DevboxWeb::host($devbox) . ': ' . $exception->getMessage());
                }
            }
            if ($running->isEmpty()) {
                $this->line('DNS: no running devbox; each gets its record when it starts.');
            }
        }

        $dir = $result['dir'];
        $this->line('');
        $this->line('nginx (docs/templates/nginx/devbox-web.conf) needs:');
        $this->line("  proxy_ssl_certificate         {$dir}/edge.crt;");
        $this->line("  proxy_ssl_certificate_key     {$dir}/edge.key;");
        $this->line("  proxy_ssl_trusted_certificate {$dir}/ca.crt;");
        $this->line('Running devboxes get their web certificate at their next start.');

        return self::SUCCESS;
    }
}
