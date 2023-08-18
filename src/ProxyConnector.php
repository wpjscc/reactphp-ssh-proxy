<?php 

namespace Wpjscc\React\SshProxy;

use React\Socket\ConnectorInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;

class ProxyConnector implements ConnectorInterface
{

    protected $user;
    protected $ip;
    protected $port;

    protected $indentityFilePath;

    protected $loop;

    public function __construct(
        #[\SensitiveParameter]
        $uri,
        $indentityFilePath = [
            'private' => '~/.ssh/id_rsa',
            'public' => '~/.ssh/id_rsa.pub'
        ],
        LoopInterface $loop = null
    ) {
        // URI must use optional ssh:// scheme, must contain host and neither pass nor target must start with dash
        $parts = \parse_url((\strpos($uri, '://') === false ? 'ssh://' : '') . $uri);

        $target = (isset($parts['user']) ? \rawurldecode($parts['user']) . '@' : '') . (isset($parts['host']) ? $parts['host'] : '');


        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'ssh' || (isset($pass[0]) && $pass[0] === '-') || $target[0] === '-') {
            throw new \InvalidArgumentException('Invalid SSH server URI');
        }

        $this->user = isset($parts['user']) ? \rawurldecode($parts['user']) : null;

        if (!$this->user) {
            throw new \InvalidArgumentException('Invalid SSH server URI: Username required');
        }

        $this->ip = isset($parts['host']) ? $parts['host'] : null;

        if (!$this->ip) {
            throw new \InvalidArgumentException('Invalid SSH server URI: Hostname required');
        }

        $pattern = '/-p\s+(\d+)\b/';
        preg_match($pattern, $uri, $matches);
        if (isset($matches[1])) {
            $this->port = $matches[1];
        } else {
            $this->port = 22;
        }

        $this->indentityFilePath = $indentityFilePath;
        
        $this->loop = $loop ?: \React\EventLoop\Loop::get();
        var_dump($this->indentityFilePath, $this->user, $this->ip, $this->port);

    }


    public function connect($uri)
    {
        // URI must use optional tcp:// scheme, must contain host and port and host must not start with dash
        $parts = \parse_url((\strpos($uri, '://') === false ? 'tcp://' : '') . $uri);
        if (!isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp' || $parts['host'][0] === '-') {
            return \React\Promise\reject(new \InvalidArgumentException('Invalid target URI'));
        }

        $deferred = new Deferred(function ($_, $reject) use ($uri) {
            $reject(new \RuntimeException(
                'Connection to ' . $uri . ' cancelled while waiting for proxy (ECONNABORTED)',
                defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
            ));
        });


        try {

            $host = $parts['host'];
            $port = $parts['port'];

            $connection = ssh2_connect($this->ip, $this->port);
            ssh2_auth_pubkey_file($connection, $this->user, $this->indentityFilePath['public'], $this->indentityFilePath['private']);
            $tunnel = ssh2_tunnel($connection, $host, $port);
            stream_set_blocking($tunnel, false);
            $stream = new Io\CompositeConnection(
                new \React\Stream\ReadableResourceStream($tunnel),
                new \React\Stream\WritableResourceStream($tunnel)
            );

            $deferred->resolve($stream);

        } 
        catch (\Exception $e) {
            $deferred->reject($e);
        } 
        catch (\Throwable $th) {
            $deferred->reject($th);
        }

        return $deferred->promise();
    }

}