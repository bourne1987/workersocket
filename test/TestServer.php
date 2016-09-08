<?php
include_once "../src/start.php";
$serv = new WorkerServer('tcp://0.0.0.0:9501');
$serv->count = 3;
$serv->taskCount = 2;

$serv->heartbeatCheckInterval = 1000;
$serv->heartbeatIdleTime = 3000;

$serv->on("connect", function($serv, $connectSocket, $recordId) {
    //$serv->tick(1000, function($timerId) use ($serv, $connectSocket) {
        //$serv->send($connectSocket, "hhhhhhhh---".time());
        ////$connectSocket->send("hhhhh---".time()."--- aaa");
    //}); 
});

$serv->on('close', function($serv, $connectSocket, $recordId) {
    echo posix_getpid()."---$recordId 执行关闭函数.\n";
});

$serv->on('receive', function($server, $connectSocket, $recordID, $data) {
    echo "接收到数据：".serialize($data)."\n";
    $timerId = $server->tick(1000, function($timerId) use ($server, $connectSocket) {
        $server->send($connectSocket, "welcome to Bourne's place.<br/>");
    }); 

    // **888 
    //$connectSocket->eventTimers[] = $timerId;
});

WorkerServer::start();
