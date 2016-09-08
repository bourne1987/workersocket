<?php
include_once "../src/start.php";

$client = new WorkerClient("tcp", WorkerClient::SOCKET_ASYNC, false);
$client->on('connect', function($cli) {
    $cli->send("mmmmmm");
});

$client->on('receive', function($cli, $data) {
    echo serialize($data)."\n";
});
$client->connect("127.0.0.1", 9501);
exit;
