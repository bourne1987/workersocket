<?php
include_once "../src/start.php";

//WorkerProcess::daemon();
$workers = [];
$worker_num = 3;//创建的进程数

for($i=0;$i<$worker_num ; $i++){
    $process = new WorkerProcess('process');
    $process->name("child process"); // 设置子进程名称
    $pid = $process->start();
    $workers[$pid] = $process;
}

foreach($workers as $process){
    WorkerProcess::tick(1000, function($timeId) use ($process) {
        $data = $process->read();
        var_dump($data);    
        if (empty($data)) {
            WorkerProcess::clearTimer($timeId);
        }
    });
}

function process($process){// 子进程第一个处理
    $count = 0;
    $val = WorkerProcess::tick(1000, function($timeId) use ($process, &$count)  {
        $count++;
        $time = time();
        $process->write($time."---".posix_getpid());       
        if ($count == 5) {
            WorkerProcess::clearTimer($timeId);
        }
    });
}

WorkerProcess::loop();

foreach($workers as $process){
    $data = WorkerProcess::wait();
    echo "销毁: ".json_encode($data)."\n";
}
