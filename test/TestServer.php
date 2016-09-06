<?php
include_once "../src/start.php";
$serv = new WorkerServer('tcp://0.0.0.0:9501');
$serv->count = 3;
$serv->taskCount = 0;

$serv->heartbeatCheckInterval = 5;
$serv->heartbeatIdleTime = 10;

$serv->on('receive', function($server, $connectSocket, $recordID, $data) {
    //WorkerServer::log("进程[".posix_getpid()."]接收数据: get --- ". serialize($data['get']) . ";post --- " . serialize($data['pos']));
    //$server->send($connectSocket, "welcome to Bourne's place.<br/>");
    $server->send($connectSocket, "HTTP/1.1 200 OK\r\nConnection: keep-alive\r\nServer: workersocket\1.1.4\r\n\r\nhello");
});

WorkerServer::start();
