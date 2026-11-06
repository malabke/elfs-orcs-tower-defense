<?php

namespace JumPHP\Ide\Debug;

use JumPHP\Ide\Async\Deferred;

/**
 * One PHP process being debugged: its DBGp connection (Xdebug connected to the IDE). Messages are
 * "<length>\0<xml>\0" from Xdebug, "command -i <transaction> args -- base64data\0" to it; every command answers with
 * the same transaction id, so command() gives a Deferred of that answer. Never blocks: poll() reads what came.
 */
final class Session
{
    public string $status = 'starting';   // starting, running, break, stopping, stopped

    /** what Xdebug said first (<init>): the script it runs */
    public ?XmlNode $init = null;

    /** @var array<int, Deferred> transaction id => its answer */
    private array $waiting = [];
    private int $next = 1;
    private string $buffer = '';

    /** @param resource $socket */
    public function __construct(public readonly int $id, private $socket)
    {
        stream_set_blocking($this->socket, false);
    }

    /** Sends a command; resolves with Xdebug's <response> as an XmlNode (rejects with its <error>). */
    public function command(string $name, array $args = [], ?string $data = null): Deferred
    {
        $deferred = new Deferred();
        if (!is_resource($this->socket)) {
            $deferred->reject(new \RuntimeException('the debugged program has ended'));
            return $deferred;
        }
        $transaction = $this->next++;
        $line = "$name -i $transaction";
        foreach ($args as $flag => $value) {
            $line .= " -$flag " . self::quote((string)$value);
        }
        if ($data !== null) {
            $line .= ' -- ' . base64_encode($data);
        }
        $this->waiting[$transaction] = $deferred;
        $this->write($line . "\0");
        return $deferred;
    }

    /**
     * Reads what Xdebug sent. Returns the messages that are not answers (the <init>, notifications), and false once
     * the connection is closed.
     *
     * @return list<XmlNode>|false
     */
    public function poll(): array|false
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        while (($chunk = @fread($this->socket, 65536)) !== false && $chunk !== '') {
            $this->buffer .= $chunk;
        }
        $other = [];
        while (($zero = strpos($this->buffer, "\0")) !== false) {
            $length = (int)substr($this->buffer, 0, $zero);
            if (strlen($this->buffer) < $zero + 1 + $length + 1) {
                break;
            }
            $xml = substr($this->buffer, $zero + 1, $length);
            $this->buffer = (string)substr($this->buffer, $zero + 1 + $length + 1);
            $message = XmlNode::parse($xml);
            if ($message === null) {
                continue;
            }
            if ($message->localName() === 'response') {
                $this->answer($message);
            } else {
                if ($message->localName() === 'init') {
                    $this->init = $message;
                }
                $other[] = $message;
            }
        }
        if (feof($this->socket)) {
            $this->close();
            return $other ?: false;
        }
        return $other;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->status = 'stopped';
        foreach ($this->waiting as $deferred) {
            $deferred->reject(new \RuntimeException('the debugged program has ended'));
        }
        $this->waiting = [];
    }

    public function isOpen(): bool
    {
        return is_resource($this->socket);
    }

    private function answer(XmlNode $response): void
    {
        $transaction = (int)$response->attr('transaction_id', '0');
        if ($response->has('status')) {
            $this->status = (string)$response->attr('status');
        }
        $deferred = $this->waiting[$transaction] ?? null;
        unset($this->waiting[$transaction]);
        if ($deferred === null) {
            return;
        }
        $error = $response->child('error');
        if ($error !== null && $error->has('code')) {
            $deferred->reject(new \RuntimeException(trim((string)$error->child('message')?->text) ?: 'Xdebug error ' . $error->attr('code')));
            return;
        }
        $deferred->resolve($response);
    }

    private function write(string $data): void
    {
        // a socket that is not ready takes what it can: the rest is written right after (small commands, local)
        $tries = 0;
        while ($data !== '' && is_resource($this->socket) && $tries++ < 200) {
            $written = @fwrite($this->socket, $data);
            if ($written === false) {
                $this->close();
                return;
            }
            $data = (string)substr($data, $written);
            if ($data !== '') {
                usleep(1000);
            }
        }
    }

    private static function quote(string $value): string
    {
        return preg_match('/^[\w.\/:\\\\-]+$/', $value) ? $value : '"' . addcslashes($value, '"\\') . '"';
    }
}
