<?php 

require __DIR__ . '/../vendor/autoload.php';


$socket = new React\Socket\SocketServer('0.0.0.0:'.getParam('--local-port'));

$socket->on('connection', function (React\Socket\ConnectionInterface $proxyConnection) {
    $buffer = '';
    $proxyConnection->on('data', $fn = function ($data) use (&$buffer) {
        $buffer .= $data;
    });

    (new React\Socket\Connector(array(
        'timeout' => 3.0,
        // 'tcp' => new Clue\React\HttpProxy\ProxyConnector('192.168.43.1:8234'), //可以做个跳板(http proxy)
        // 'tcp' => new Clue\React\Socks\Client('192.168.43.1:8235'), // 可以做个跳板(socket proxy),
        // 'tcp' => new Clue\React\SshProxy\SshProcessConnector('user@ip'), //可以做个跳板(ssh proxy)
        'tcp' => new Wpjscc\React\SshProxy\ProxyConnector('root@10.8.0.1', '/root/.ssh/id_rsa.pub', '/root/.ssh/id_rsa'), //可以做个跳板(ssh proxy)
        'dns' => false,

    )))
    ->connect("tcp://".getParam("--dest-host").":".getParam("--dest-port"))
    ->then(function (React\Socket\ConnectionInterface $connection) use ($proxyConnection, $fn, &$buffer)  {

        $proxyConnection->removeListener('data', $fn);
        $fn = null;
        $proxyConnection->pipe($connection);
        $connection->pipe($proxyConnection);

        if ($buffer) {
            $connection->write($buffer);
            $buffer = '';
        }

    }, function ($e) use ($proxyConnection) {
        $proxyConnection->write("HTTP/1.1 502 Bad Gateway\r\n\r\n".$e->getMessage());
        $proxyConnection->end();
    });
});



function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            return explode('=', $arg)[1];
        }
    }
    return $default;
}