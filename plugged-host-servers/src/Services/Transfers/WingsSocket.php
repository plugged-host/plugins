<?php

namespace PluggedHost\Servers\Services\Transfers;

use RuntimeException;

/**
 * Just enough of a websocket client (RFC 6455) to listen to a wings server socket.
 *
 * Wings only reports transfer progress as "transfer logs" events on the source node's
 * websocket, and the panel ships no websocket client, so this is the smallest thing that
 * works: text frames in, masked text frames out, ping answered, no extensions (wings only
 * compresses when the client offers permessage-deflate, which this one never does).
 * Non-blocking after the handshake so one watcher can hold a socket per running transfer.
 */
final class WingsSocket
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** A frame bigger than this is not something wings sends; treat it as a broken stream. */
    private const MAX_FRAME = 4 * 1024 * 1024;

    /** @var resource|null */
    private $stream;

    private string $buffer = '';

    private string $fragments = '';

    private bool $open = false;

    /** @param resource $stream */
    private function __construct($stream, string $leftover)
    {
        $this->stream = $stream;
        $this->buffer = $leftover;
        $this->open = true;
    }

    /**
     * @param  string  $url  wss://host:port/api/servers/{uuid}/ws
     * @param  string  $origin  must equal the panel URL wings is configured with
     *
     * @throws RuntimeException
     */
    public static function open(string $url, string $origin, bool $verify, float $timeout = 5.0): self
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || !in_array($scheme, ['ws', 'wss'], true)) {
            throw new RuntimeException("invalid websocket url: $url");
        }

        $secure = $scheme === 'wss';
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            ($secure ? 'tls://' : 'tcp://') . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (!is_resource($stream)) {
            throw new RuntimeException("websocket connect failed: $errstr ($errno)");
        }

        stream_set_timeout($stream, (int) ceil($timeout));

        $key = base64_encode(random_bytes(16));
        $request = "GET $path HTTP/1.1\r\n"
            . "Host: $host:$port\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Origin: $origin\r\n"
            . "User-Agent: plugged.host-transfers\r\n\r\n";

        if (@fwrite($stream, $request) === false) {
            fclose($stream);

            throw new RuntimeException('websocket handshake write failed');
        }

        $response = '';
        $deadline = microtime(true) + $timeout;

        while (!str_contains($response, "\r\n\r\n")) {
            if (microtime(true) > $deadline || strlen($response) > 16384) {
                fclose($stream);

                throw new RuntimeException('websocket handshake timed out');
            }

            $chunk = @fread($stream, 4096);

            if ($chunk === false || ($chunk === '' && feof($stream))) {
                fclose($stream);

                throw new RuntimeException('websocket handshake closed');
            }

            $response .= $chunk;
        }

        [$head, $leftover] = explode("\r\n\r\n", $response, 2);
        $lines = explode("\r\n", $head);

        if (!preg_match('#^HTTP/1\.[01] 101#', $lines[0] ?? '')) {
            fclose($stream);

            throw new RuntimeException('websocket upgrade refused: ' . trim($lines[0] ?? ''));
        }

        $accept = null;

        foreach ($lines as $line) {
            if (stripos($line, 'sec-websocket-accept:') === 0) {
                $accept = trim(substr($line, strlen('sec-websocket-accept:')));
            }
        }

        if ($accept !== base64_encode(sha1($key . self::GUID, true))) {
            fclose($stream);

            throw new RuntimeException('websocket accept key mismatch');
        }

        stream_set_blocking($stream, false);

        return new self($stream, $leftover);
    }

    public function isOpen(): bool
    {
        return $this->open && is_resource($this->stream) && !feof($this->stream);
    }

    /** @return resource|null */
    public function stream()
    {
        return $this->stream;
    }

    /** @param array<string, mixed> $payload */
    public function sendJson(array $payload): void
    {
        $this->sendFrame(0x1, (string) json_encode($payload));
    }

    /**
     * Every complete text message that has arrived, without waiting for more.
     *
     * @return list<string>
     */
    public function read(): array
    {
        if (!$this->isOpen()) {
            return [];
        }

        // With TLS, stream_select() can miss data already decrypted into PHP's buffer, so
        // drain until the socket really has nothing rather than trusting one fread().
        while (true) {
            $chunk = @fread($this->stream, 65536);

            if ($chunk === false) {
                $this->close();

                break;
            }

            if ($chunk === '') {
                if (feof($this->stream)) {
                    $this->open = false;
                }

                break;
            }

            $this->buffer .= $chunk;
        }

        $messages = [];

        while (($frame = $this->nextFrame()) !== null) {
            [$fin, $opcode, $payload] = $frame;

            switch ($opcode) {
                case 0x0: // continuation
                case 0x1: // text
                case 0x2: // binary
                    $this->fragments .= $payload;

                    if ($fin) {
                        $messages[] = $this->fragments;
                        $this->fragments = '';
                    }

                    break;

                case 0x8: // close
                    $this->close();

                    break 2;

                case 0x9: // ping
                    $this->sendFrame(0xA, $payload);

                    break;

                default: // pong and anything else: nothing to do
                    break;
            }
        }

        return $messages;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            if ($this->open) {
                try {
                    $this->sendFrame(0x8, pack('n', 1000));
                } catch (RuntimeException) {
                    //
                }
            }

            @fclose($this->stream);
        }

        $this->open = false;
        $this->stream = null;
    }

    /** @return null|array{0: bool, 1: int, 2: string} */
    private function nextFrame(): ?array
    {
        $length = strlen($this->buffer);

        if ($length < 2) {
            return null;
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $size = $second & 0x7F;
        $offset = 2;

        if ($size === 126) {
            if ($length < 4) {
                return null;
            }

            $size = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($size === 127) {
            if ($length < 10) {
                return null;
            }

            $size = unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($size > self::MAX_FRAME) {
            $this->close();

            return null;
        }

        $mask = null;

        if ($second & 0x80) {
            if ($length < $offset + 4) {
                return null;
            }

            $mask = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $size) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $size);
        $this->buffer = (string) substr($this->buffer, $offset + $size);

        if ($mask !== null) {
            $payload = $payload ^ str_repeat($mask, intdiv($size, 4) + 1);
            $payload = substr($payload, 0, $size);
        }

        return [(bool) ($first & 0x80), $first & 0x0F, $payload];
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('websocket is closed');
        }

        $size = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($size < 126) {
            $header .= chr(0x80 | $size);
        } elseif ($size < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $size);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $size);
        }

        $mask = random_bytes(4);
        $masked = substr($payload ^ str_repeat($mask, intdiv($size, 4) + 1), 0, $size);
        $frame = $header . $mask . $masked;

        // The socket is non-blocking; a short write here is rare (frames are tiny) but must
        // not truncate a frame, so finish it in blocking mode.
        stream_set_blocking($this->stream, true);

        try {
            $written = 0;
            $total = strlen($frame);

            while ($written < $total) {
                $n = @fwrite($this->stream, substr($frame, $written));

                if ($n === false || $n === 0) {
                    $this->open = false;

                    throw new RuntimeException('websocket write failed');
                }

                $written += $n;
            }
        } finally {
            if (is_resource($this->stream)) {
                stream_set_blocking($this->stream, false);
            }
        }
    }
}
