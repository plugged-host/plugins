<?php

namespace PluggedHost\Ai\Discord;

use RuntimeException;

/**
 * A client WebSocket to Discord's gateway (JSON encoding, no compression), just enough of
 * RFC 6455 for it.
 *
 * Everything read is appended to a buffer and only whole frames are taken off it, so a frame
 * that arrives in several TLS records is never cut in half. Fragmented messages are joined,
 * pings answered; a close frame ends the connection and reports its code.
 */
final class GatewaySocket
{
    /** @var resource|null */
    private $stream = null;

    private string $buffer = '';

    private string $fragments = '';

    private ?int $closeCode = null;

    private string $closeReason = '';

    /**
     * @throws RuntimeException
     */
    public function connect(string $url): void
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? 'gateway.discord.gg');
        $query = 'v=10&encoding=json';

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new RuntimeException("Cannot reach the Discord gateway at {$host}: [{$errno}] {$errstr}");
        }

        stream_set_timeout($stream, 15);

        $key = base64_encode(random_bytes(16));
        fwrite($stream, "GET /?{$query} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nUser-Agent: DiscordBot (https://github.com/plugged-host/plugins, 1.0) rux\r\n\r\n");

        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $line = fgets($stream);

            if ($line === false) {
                fclose($stream);

                throw new RuntimeException('The Discord gateway closed the connection during the handshake.');
            }

            $head .= $line;

            if (strlen($head) > 16384) {
                fclose($stream);

                throw new RuntimeException('The Discord gateway sent an oversized handshake.');
            }
        }

        if (preg_match('#^HTTP/1\.[01] 101#', $head) !== 1) {
            fclose($stream);

            throw new RuntimeException('The Discord gateway refused the WebSocket upgrade: ' . strtok($head, "\r\n"));
        }

        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $m) !== 1 || $m[1] !== $expected) {
            fclose($stream);

            throw new RuntimeException('The Discord gateway answered the handshake with the wrong accept key.');
        }

        stream_set_blocking($stream, false);

        $this->stream = $stream;
        $this->buffer = '';
        $this->fragments = '';
        $this->closeCode = null;
        $this->closeReason = '';
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream) && $this->closeCode === null && !feof($this->stream);
    }

    public function closeCode(): ?int
    {
        return $this->closeCode;
    }

    public function closeReason(): string
    {
        return $this->closeReason;
    }

    /**
     * Wait up to $timeoutMs for data and return every complete text message received.
     *
     * @return list<string>
     */
    public function read(int $timeoutMs): array
    {
        if (!is_resource($this->stream)) {
            return [];
        }

        $read = [$this->stream];
        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 0, $timeoutMs * 1000);

        // Read even when select says nothing is there: TLS can hold decrypted bytes that a
        // select on the raw socket does not see.
        while (true) {
            $chunk = @fread($this->stream, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $this->buffer .= $chunk;
        }

        return $this->drainFrames();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendJson(array $payload): void
    {
        $this->sendFrame(0x1, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function close(int $code = 1000): void
    {
        if (is_resource($this->stream)) {
            try {
                $this->sendFrame(0x8, pack('n', $code));
            } catch (RuntimeException) {
            }

            @fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * @return list<string>
     */
    private function drainFrames(): array
    {
        $messages = [];

        while (strlen($this->buffer) >= 2) {
            $first = ord($this->buffer[0]);
            $second = ord($this->buffer[1]);
            $fin = ($first & 0x80) !== 0;
            $opcode = $first & 0x0F;
            $masked = ($second & 0x80) !== 0;
            $length = $second & 0x7F;
            $offset = 2;

            if ($length === 126) {
                if (strlen($this->buffer) < 4) {
                    break;
                }
                $length = unpack('n', substr($this->buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($this->buffer) < 10) {
                    break;
                }
                $length = unpack('J', substr($this->buffer, 2, 8))[1];
                $offset = 10;
            }

            $mask = '';
            if ($masked) {
                if (strlen($this->buffer) < $offset + 4) {
                    break;
                }
                $mask = substr($this->buffer, $offset, 4);
                $offset += 4;
            }

            if (strlen($this->buffer) < $offset + $length) {
                break;
            }

            $payload = substr($this->buffer, $offset, $length);
            $this->buffer = (string) substr($this->buffer, $offset + $length);

            if ($masked) {
                $payload = $payload ^ str_repeat($mask, intdiv($length, 4) + 1);
                $payload = substr($payload, 0, $length);
            }

            switch ($opcode) {
                case 0x0:
                case 0x1:
                case 0x2:
                    $this->fragments .= $payload;
                    if ($fin) {
                        $messages[] = $this->fragments;
                        $this->fragments = '';
                    }
                    break;
                case 0x8:
                    $this->closeCode = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1005;
                    $this->closeReason = strlen($payload) > 2 ? substr($payload, 2) : '';
                    break 2;
                case 0x9:
                    $this->sendFrame(0xA, $payload);
                    break;
                default:
                    break;
            }
        }

        return $messages;
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('The gateway connection is closed.');
        }

        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask . substr($payload ^ str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        $deadline = microtime(true) + 10;
        while ($frame !== '') {
            $written = @fwrite($this->stream, $frame);

            if ($written === false) {
                throw new RuntimeException('Writing to the Discord gateway failed.');
            }

            $frame = (string) substr($frame, $written);

            if ($frame !== '') {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Writing to the Discord gateway timed out.');
                }

                $read = null;
                $write = [$this->stream];
                $except = null;
                @stream_select($read, $write, $except, 0, 100000);
            }
        }
    }
}
