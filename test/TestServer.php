<?php
include_once "../src/start.php";
$serv = new WorkerServer('json://0.0.0.0:9501');
$serv->count = 1;
$serv->taskCount = 2;

$serv->heartbeatCheckInterval = 1000;
$serv->heartbeatIdleTime = 5000;

$serv->on("connect", function($serv, $connectSocket, $recordId) {
    echo "进程[".posix_getpid()."]链接上了[$recordId]\n";
});

$serv->on('close', function($serv, $connectSocket, $recordId) {
    echo "进程[".posix_getpid()."]关闭recordid[$recordId]\n";
});

$serv->on('error', function($serv, $connectSocket, $recordId, $errMsg) {
    echo "进程[".posix_getpid()."] recordid= {$recordId}  错误信息: $errMsg\n";
});

$serv->on('sendBufferFull', function($serv, $connectSocket, $recordId, $msg) {
    echo "$msg\n";
});

$serv->on('receive', function($serv, $connectSocket, $recordID, $data) {
    echo "接收到数据：".serialize($data)." --- {$recordID}\n";
    $serv->task($data);
    $serv->send($connectSocket, $data);
});

$serv->on('task', function($serv, $taskID, $taskData) {
    echo "异步任务---- {$taskData}\n";
});

WorkerServer::start();
