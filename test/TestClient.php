<?php
include_once "../src/start.php";
$client = new WorkerClient("tcp", WorkerClient::SOCKET_SYNC);
$client->connect("127.0.0.1", 9501, 1);
$client->send("aaaa");
var_dump($client->recv());
$client->close();

//exit;
//$cli = new WorkerClient('json', WorkerClient::SOCKET_ASYNC);
//$cli->on('connect', function($cli) {
    //echo "链接上了.\n";
    //$cli->send("hhhhhhhhh");
//});
//$cli->on('receive', function($cli, $data) {
    //echo "收到数据:".serialize($data)."\n";
//});
//$cli->on('close', function($cli) {
    //echo "服务端链接关闭了，客户端也需要关闭.\n";
//});
//$cli->connect('127.0.0.1', '9501');
