<?php
include_once "../src/start.php";
$serv = new WorkerServer('tcp://0.0.0.0:9501');
$serv->count = 5;
$serv->taskCount = 0;
$serv->on('receive', function($server, $connectSocket, $recordID, $data) {
    static $link = null;
    if ($link == null) {
        $link = mysqli_connect("ylmf", "ylmf", "xxxx", "xxxx");
        if (!$link) {
            $link = null;
            return;
        }
    }

    if (posix_getpid() % 2 == 0) {
        $sql = "insert";
        $result = $link->query($sql);
        if (!$result) {
            return;
        }
        $server->send($connectSocket, "HTTP/1.1 200 OK\r\nConnection: keep-alive\r\nServer: workersocket\1.1.4\r\n\r\nok");
    } else {
        $sql = "select";
        $result = $link->query($sql);
        if (!$result) {
            return;
        }
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $messgae = serialize($data);
        $server->send($connectSocket, "HTTP/1.1 200 OK\r\nConnection: keep-alive\r\nServer: workersocket\1.1.4\r\n\r\n$messgae");
    }
});

WorkerServer::start();
