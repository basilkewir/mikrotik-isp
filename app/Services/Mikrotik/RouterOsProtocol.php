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
     * Change the socket timeout for subsequent reads/connects.
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = max(1, $timeout);

        if ($this->socket) {
            @stream_set_timeout($this->socket, $this->timeout);
        }
    }

    /**
     * Connect and authenticate to MikroTik Router.
     *
     * RouterOS delays login replies from a source IP after failed attempts
     * (anti-bruteforce backoff). A silent first attempt is retried once
     * after a short pause before giving up.
     */
    public function connect(string $ip, string $username, string $password, int $port = 8728, bool $useSsl = false): bool
    {
        try {
            return $this->attemptLogin($ip, $username, $password, $port, $useSsl);
        } catch (Exception $exception) {
            if (!str_starts_with($exception->getMessage(), 'Failed to authenticate')) {
                throw $exception;
            }

            // Silent timeout — the API port is open but RouterOS is holding
            // the reply back. Wait briefly and retry once.
            sleep(3);
            $this->disconnect();

            return $this->attemptLogin($ip, $username, $password, $port, $useSsl);
        }
    }

    /**
     * Single login attempt: open the socket and perform the /login handshake.
     */
    protected function attemptLogin(string $ip, string $username, string $password, int $port, bool $useSsl): bool
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

        throw new Exception("Failed to authenticate with RouterOS device at {$ip} (no reply — RouterOS may be delaying logins from this IP after recent failed attempts; wait a few minutes before retrying)");
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
     * Send '/file/get' and return the concatenated file body.
     *
     * RouterOS streams a file as many '=contents=' words (one per chunk) plus
     * a trailing '=contents=' with an offset, so the generic command() parser
     * (which keeps only the last value per key) cannot be used. Returns null
     * when the file does not exist.
     */
    public function getFileContents(string $name): ?string
    {
        $this->write('/file/get', ['=.id=' . $name]);

        $body = '';
        while (true) {
            $word = $this->readWord();

            if ($word === '') {
                continue;
            }

            if ($word === '!done') {
                break;
            }

            if ($word === '!trap' || $word === '!fatal') {
                throw new Exception("RouterOS Trap: unable to read file {$name}");
            }

            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                if ($parts[0] === 'contents') {
                    $body .= $parts[1] ?? '';
                }
            }
        }

        return $body;
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
            $this->failRead('the start of a word');
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
                $this->failRead("the body of a word ({$len} bytes, received " . strlen($received) . ')');
            }
            $received .= $chunk;
        }

        return $received;
    }

    /**
     * Abort a read that cannot be completed safely.
     *
     * A partial sentence must never be returned to the caller: the unread bytes
     * stay in the socket buffer and would be consumed as the reply to whatever
     * command is sent next, silently returning another command's data. Dropping
     * the connection is the only way to stay in sync with RouterOS.
     */
    protected function failRead(string $expecting): void
    {
        $meta = @stream_get_meta_data($this->socket);
        $timedOut = !empty($meta['timed_out']);

        $this->disconnect();

        if ($timedOut) {
            throw new Exception(
                "RouterOS API read timed out after {$this->timeout}s waiting for {$expecting} — " .
                'the router is not answering in time; connection was dropped so a stale reply cannot be mistaken for this command\'s result'
            );
        }

        throw new Exception("RouterOS API connection closed by the router while waiting for {$expecting}");
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

