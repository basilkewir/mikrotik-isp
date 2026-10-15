<?php

namespace App\Services\Mikrotik;

use Exception;

/**
 * Pure PHP MikroTik RouterOS API Client (No external dependencies required).
 * Supports RouterOS v6.x (both pre/post 6.43 auth) and RouterOS v7.x.
 */
class RouterOsProtocol
{
    protected $socket = null;
    protected bool $connected = false;
    protected int $timeout = 5;

    public function __construct(int $timeout = 5)
    {
        $this->timeout = $timeout;
    }

    /**
     * Connect and authenticate to MikroTik Router.
     */
    public function connect(string $ip, string $username, string $password, int $port = 8728, bool $useSsl = false): bool
    {
        $address = ($useSsl ? 'ssl://' : 'tcp://') . $ip . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ]);

        $this->socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new Exception("Unable to connect to RouterOS at {$ip}:{$port}. Error: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, $this->timeout);

        // Attempt authentication (Modern RouterOS v6.43+ and v7)
        $loginResponse = $this->write('/login', [
            '=name=' . $username,
            '=password=' . $password,
        ])->read();

        // Check if modern login succeeded
        if (isset($loginResponse[0]['!done']) && !isset($loginResponse[0]['=ret'])) {
            $this->connected = true;
            return true;
        }

        // Handle legacy challenge-response for RouterOS < 6.43
        if (isset($loginResponse[0]['=ret'])) {
            $challenge = $loginResponse[0]['=ret'];
            $md5Hash = md5(chr(0) . $password . pack('H*', $challenge));
            $legacyResponse = $this->write('/login', [
                '=name=' . $username,
                '=response=00' . $md5Hash,
            ])->read();

            if (isset($legacyResponse[0]['!done'])) {
                $this->connected = true;
                return true;
            }
        }

        if (isset($loginResponse[0]['!trap'])) {
            $message = $loginResponse[0]['=message'] ?? 'Authentication failed';
            throw new Exception("RouterOS Auth Error: {$message}");
        }

        throw new Exception("Failed to authenticate with RouterOS device at {$ip}");
    }

    /**
     * Write command and attributes to the API socket.
     */
    public function write(string $command, array $params = []): self
    {
        if (!$this->socket) {
            throw new Exception('Socket connection not initialized');
        }

        $this->writeWord($command);
        foreach ($params as $param) {
            $this->writeWord($param);
        }
        $this->writeWord(''); // End of sentence (empty word)

        return $this;
    }

    /**
     * Read the response sentences from the API.
     */
    public function read(): array
    {
        $response = [];
        $sentence = [];

        while (true) {
            $word = $this->readWord();

            if ($word === '') {
                // End of sentence
                if (!empty($sentence)) {
                    $response[] = $sentence;
                    $sentence = [];
                }
                continue;
            }

            if ($word === '!done') {
                $sentence['!done'] = true;
                $response[] = $sentence;
                break;
            } elseif ($word === '!re') {
                $sentence['!re'] = true;
            } elseif ($word === '!trap') {
                $sentence['!trap'] = true;
            } elseif ($word === '!fatal') {
                $sentence['!fatal'] = true;
                $response[] = $sentence;
                break;
            } elseif (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                $key = $parts[0];
                $val = $parts[1] ?? '';
                $sentence['=' . $key] = $val;
            } else {
                $sentence[$word] = true;
            }
        }

        return $response;
    }

    /**
     * Send command and return formatted array of records (stripping '=' prefixes).
     */
    public function command(string $command, array $params = []): array
    {
        $raw = $this->write($command, $params)->read();
        $results = [];

        foreach ($raw as $sentence) {
            if (isset($sentence['!trap'])) {
                $msg = $sentence['=message'] ?? 'Command failed with trap';
                throw new Exception("RouterOS Trap: {$msg}");
            }

            if (isset($sentence['!re'])) {
                $item = [];
                foreach ($sentence as $k => $v) {
                    if ($k === '!re') {
                        continue;
                    }
                    if (str_starts_with($k, '=')) {
                        $item[substr($k, 1)] = $v;
                    } else {
                        $item[$k] = $v;
                    }
                }
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * Write single word with RouterOS length prefix encoding.
     */
    protected function writeWord(string $word): void
    {
        $len = strlen($word);

        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            fwrite($this->socket, chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            fwrite($this->socket, chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            $len |= 0xE0000000;
            fwrite($this->socket, chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        }

        fwrite($this->socket, $word);
    }

    /**
     * Read single word from the socket.
     */
    protected function readWord(): string
    {
        $byte = fread($this->socket, 1);
        if ($byte === '' || $byte === false) {
            return '!fatal';
        }

        $b = ord($byte);
        $len = 0;

        if (($b & 0x80) === 0x00) {
            $len = $b;
        } elseif (($b & 0xC0) === 0x80) {
            $b2 = ord(fread($this->socket, 1));
            $len = (($b & 0x3F) << 8) + $b2;
        } elseif (($b & 0xE0) === 0xC0) {
            $b2 = ord(fread($this->socket, 1));
            $b3 = ord(fread($this->socket, 1));
            $len = (($b & 0x1F) << 16) + ($b2 << 8) + $b3;
        } elseif (($b & 0xF0) === 0xE0) {
            $b2 = ord(fread($this->socket, 1));
            $b3 = ord(fread($this->socket, 1));
            $b4 = ord(fread($this->socket, 1));
            $len = (($b & 0x0F) << 24) + ($b2 << 16) + ($b3 << 8) + $b4;
        } elseif (($b & 0xF8) === 0xF0) {
            $b2 = ord(fread($this->socket, 1));
            $b3 = ord(fread($this->socket, 1));
            $b4 = ord(fread($this->socket, 1));
            $b5 = ord(fread($this->socket, 1));
            $len = ($b2 << 24) + ($b3 << 16) + ($b4 << 8) + $b5;
        }

        if ($len === 0) {
            return '';
        }

        $received = '';
        while (strlen($received) < $len) {
            $chunk = fread($this->socket, $len - strlen($received));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $received .= $chunk;
        }

        return $received;
    }

    /**
     * Close socket connection gracefully.
     */
    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
            $this->connected = false;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

