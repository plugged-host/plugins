<?php

namespace PluggedHost\Devbox\Services;

use RuntimeException;

/**
 * The devbox CA, private to this panel, for VS Code in the browser:
 *
 * - edge.crt/edge.key: the client certificate nginx (the edge) presents to a devbox. `ph` only
 *   serves its web side to that certificate, so code-server is reachable through the edge alone.
 * - a server certificate per devbox start for <slug>.<web_domain>, which the edge verifies, so
 *   nothing between the panel box and a node can stand in for a devbox.
 *
 * EC P-256 throughout. The CA key never leaves pki_path (www-data, 0600); nginx reads the edge
 * files and ca.crt from there as root.
 */
final class DevboxPki
{
    private const CA_DAYS = 3650;

    private const EDGE_DAYS = 3650;

    private const SERVER_DAYS = 30;

    public static function dir(): string
    {
        return rtrim((string) config('plugged-host-devbox.pki_path'), '/');
    }

    public static function ready(): bool
    {
        return is_file(self::dir() . '/ca.crt') && is_file(self::dir() . '/ca.key')
            && is_file(self::dir() . '/edge.crt') && is_file(self::dir() . '/edge.key');
    }

    /**
     * Creates the CA and the edge certificate when missing. Safe to run again.
     *
     * @return array{created: bool, dir: string}
     */
    public function ensure(): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("could not create {$dir}");
        }

        $created = false;

        if (!is_file("{$dir}/ca.crt") || !is_file("{$dir}/ca.key")) {
            $key = self::newKey();
            $cert = $this->sign(['commonName' => self::commonName('devbox CA')], $key, null, null, 'v3_ca', self::CA_DAYS);
            self::write("{$dir}/ca.key", self::exportKey($key));
            self::write("{$dir}/ca.crt", $cert);
            // A new CA invalidates the old edge certificate.
            @unlink("{$dir}/edge.crt");
            @unlink("{$dir}/edge.key");
            $created = true;
        }

        if (!is_file("{$dir}/edge.crt") || !is_file("{$dir}/edge.key")) {
            $key = self::newKey();
            $cert = $this->sign(['commonName' => self::commonName('devbox edge')], $key, $this->caCert(), $this->caKey(), 'v3_edge', self::EDGE_DAYS);
            self::write("{$dir}/edge.key", self::exportKey($key));
            self::write("{$dir}/edge.crt", $cert);
            $created = true;
        }

        return ['created' => $created, 'dir' => $dir];
    }

    /**
     * A server certificate for one devbox start.
     *
     * @return array{cert: string, key: string, ca: string}
     */
    public function issue(string $host): array
    {
        if (!self::ready()) {
            throw new RuntimeException('the devbox CA is not set up (php artisan plugged-host:devbox-web-setup)');
        }
        if (!preg_match('/^[a-z0-9.-]{1,253}$/', $host)) {
            throw new RuntimeException("not a host name: {$host}");
        }

        $key = self::newKey();
        $cert = $this->sign(['commonName' => $host], $key, $this->caCert(), $this->caKey(), 'v3_server', self::SERVER_DAYS, $host);

        return ['cert' => $cert, 'key' => self::exportKey($key), 'ca' => $this->caCert()];
    }

    public function caCert(): string
    {
        return (string) file_get_contents(self::dir() . '/ca.crt');
    }

    private function caKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private((string) file_get_contents(self::dir() . '/ca.key'));

        return $key ?: throw new RuntimeException('unreadable devbox CA key');
    }

    /**
     * @param  array<string, string>  $subject
     */
    private function sign(array $subject, \OpenSSLAsymmetricKey $key, ?string $caCert, ?\OpenSSLAsymmetricKey $caKey, string $extensions, int $days, ?string $san = null): string
    {
        $config = self::opensslConfig($san);

        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions, 'req_extensions' => $extensions];
            $csr = openssl_csr_new($subject, $key, $options);
            if ($csr === false || $csr === true) {
                throw new RuntimeException('could not make a CSR: ' . openssl_error_string());
            }

            $signed = openssl_csr_sign($csr, $caCert, $caKey ?? $key, $days, $options, random_int(1, PHP_INT_MAX));
            if ($signed === false || !openssl_x509_export($signed, $pem)) {
                throw new RuntimeException('could not sign: ' . openssl_error_string());
            }

            return $pem;
        } finally {
            @unlink($config);
        }
    }

    private static function opensslConfig(?string $san): string
    {
        $config = tempnam(sys_get_temp_dir(), 'ph-devbox-ssl');
        $sanLine = $san !== null ? "subjectAltName = DNS:{$san}\n" : '';

        file_put_contents($config, <<<CNF
            [ req ]
            distinguished_name = dn
            [ dn ]
            [ v3_ca ]
            basicConstraints = critical, CA:TRUE, pathlen:0
            keyUsage = critical, keyCertSign, cRLSign
            subjectKeyIdentifier = hash
            [ v3_edge ]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            extendedKeyUsage = clientAuth
            [ v3_server ]
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            extendedKeyUsage = serverAuth
            {$sanLine}
            CNF);

        return $config;
    }

    /**
     * "<app name> devbox CA": only a label (nothing checks it), cut to X.509's 64 characters. It
     * is set when a CA or edge certificate is made, so existing ones keep theirs.
     */
    private static function commonName(string $what): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f]+/', ' ', (string) config('app.name')) . ' ' . $what);

        return mb_substr(mb_strlen($name) > 64 ? $what : $name, 0, 64);
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        return $key ?: throw new RuntimeException('could not make a key: ' . openssl_error_string());
    }

    private static function exportKey(\OpenSSLAsymmetricKey $key): string
    {
        if (!openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('could not export a key: ' . openssl_error_string());
        }

        return $pem;
    }

    private static function write(string $path, string $contents): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $contents) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) {
            throw new RuntimeException("could not write {$path}");
        }
    }
}
