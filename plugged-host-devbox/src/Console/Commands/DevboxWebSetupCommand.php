<?php

namespace PluggedHost\Devbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Devbox\Services\DevboxPki;
use PluggedHost\Devbox\Support\DevboxWeb;
use Throwable;

/**
 * VS Code in the browser, the panel's part of setting it up (safe to run again):
 *
 * - the devbox CA and the edge's client certificate (DevboxPki), which nginx reads;
 * - the wildcard DNS record *.<web_domain> → web_origin_ip, proxied by Cloudflare, made with the
 *   subdomains plugin's Cloudflare token when that plugin is on, else DEVBOX_CLOUDFLARE_TOKEN.
 *
 * nginx itself (docs/templates/nginx/devbox-web.conf) and the edge certificate for the domain at
 * Cloudflare are set up by hand once; the command prints the paths nginx needs.
 */
class DevboxWebSetupCommand extends Command
{
    protected $signature = 'plugged-host:devbox-web-setup {--no-dns : Leave the DNS record alone}';

    protected $description = 'Set up VS Code in the browser for devboxes: CA, edge certificate and the wildcard DNS record.';

    public function handle(DevboxPki $pki): int
    {
        $domain = DevboxWeb::domain();
        if ($domain === '') {
            $this->error('Set DEVBOX_WEB_DOMAIN first (e.g. devbox.onplugged.host), in .env or the plugin settings.');

            return self::FAILURE;
        }

        $result = $pki->ensure();
        $this->info(($result['created'] ? 'Created' : 'Kept') . " the devbox CA and edge certificate in {$result['dir']}");

        if (!$this->option('no-dns') && !$this->dns($domain)) {
            return self::FAILURE;
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

    private function dns(string $domain): bool
    {
        $token = self::cloudflareToken();
        if ($token === '') {
            $this->error(Plugins::enabled('subdomains')
                ? 'The subdomains plugin has no Cloudflare token (SUBDOMAINS_CLOUDFLARE_TOKEN).'
                : 'Set DEVBOX_CLOUDFLARE_TOKEN (Zone DNS edit on the web domain\'s zone): the subdomains plugin, whose token would be used, isn\'t installed.');

            return false;
        }

        $ip = (string) config('plugged-host-devbox.web_origin_ip');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->error('Set DEVBOX_WEB_ORIGIN_IP to the edge\'s public IPv4.');

            return false;
        }

        $cloudflare = Http::withToken($token)->acceptJson()->timeout(15)->baseUrl('https://api.cloudflare.com/client/v4');

        try {
            $zone = $this->zone($cloudflare, $domain);
            if ($zone === null) {
                $this->error("No Cloudflare zone the token can see holds {$domain}.");

                return false;
            }

            $name = "*.{$domain}";
            $record = [
                'type' => 'A',
                'name' => $name,
                'content' => $ip,
                'proxied' => true,
                'ttl' => 1,
                'comment' => 'plugged.host devboxes in the browser (plugged-host-devbox)',
            ];

            $existing = $cloudflare->get("/zones/{$zone}/dns_records", ['type' => 'A', 'name' => $name])->throw()->json('result.0');
            if (is_array($existing)) {
                $cloudflare->patch("/zones/{$zone}/dns_records/{$existing['id']}", $record)->throw();
                $this->info("DNS: updated {$name} → {$ip} (proxied)");
            } else {
                $cloudflare->post("/zones/{$zone}/dns_records", $record)->throw();
                $this->info("DNS: created {$name} → {$ip} (proxied)");
            }
        } catch (Throwable $exception) {
            $this->error('Cloudflare: ' . $exception->getMessage());

            return false;
        }

        return true;
    }

    /**
     * The id of the zone holding $domain: tries devbox.example.com, then example.com.
     */
    private function zone(PendingRequest $cloudflare, string $domain): ?string
    {
        $labels = explode('.', $domain);
        while (count($labels) >= 2) {
            $id = $cloudflare->get('/zones', ['name' => implode('.', $labels)])->throw()->json('result.0.id');
            if (is_string($id) && $id !== '') {
                return $id;
            }
            array_shift($labels);
        }

        return null;
    }

    /**
     * The subdomains plugin's Cloudflare token when that plugin is on (it already manages the
     * user-content zone), else the devbox plugin's own.
     */
    public static function cloudflareToken(): string
    {
        if (Plugins::enabled('subdomains')) {
            return (string) config('subdomains.token', '');
        }

        return (string) config('plugged-host-devbox.cloudflare_token', '');
    }
}
