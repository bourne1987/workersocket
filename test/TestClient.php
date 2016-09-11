<?php
include_once "../src/start.php";

//$client = new WorkerClient("tcp", WorkerClient::SOCKET_ASYNC, false);
//$client->on('connect', function($cli) {
    //$cli->send("mmmmmm");
//});

//$client->on('receive', function($cli, $data) {
    //echo serialize($data)."\n";
    //$cli->send(time());
//});

//$client->on('error', function($cli, $errMsg) {
    //echo "$errMsg\n";
//});
//$client->connect("127.0.0.1", 9501);
//exit;

$client = new WorkerClient("json", WorkerClient::SOCKET_SYNC, true);
$client->on('error', function($cli, $errMsg) {
    echo "$errMsg\n";
});
$client->connect('127.0.0.1', 9501);
$client->tick(1000, function($timerId) use ($client) {
    $sendData = "ddddd --- {$timerId}";
    $client->send($sendData);
    $readData = $client->recv();
    var_dump($readData);    
});

WorkerProcess::loop();
