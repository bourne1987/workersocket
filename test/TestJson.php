<?php
include_once "../src/start.php";
$serv = new WorkerServer('json://0.0.0.0:9501');
$serv->count = 2;
$serv->taskCount = 0;

$serv->on('connect', function() {
    echo "链接来了!\n";
});

$serv->on('receive', function($server, $connectSocket, $recordID, $data) {
    echo "接收数据:$recordID\n";
    $data = $data."---".$recordID;
    $server->send($connectSocket, $data);
});

WorkerServer::start();
