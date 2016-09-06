<?php

include_once "../src/start.php";
$serv = new WorkerServer('http://0.0.0.0:9501');
$serv->count = 3;
$serv->taskCount = 0;

$serv->heartbeatCheckInterval = 5;
$serv->heartbeatIdleTime = 10;

$serv->on('receive', function($server, $connectSocket, $recordID, $data) {
    // $message, $logName = "error"
    WorkerLibUtil::log(serialize($data['get']), date("Ymd"));
    $server->send($connectSocket, "welcome to Bourne's place.<br/>");
});

WorkerServer::start();
