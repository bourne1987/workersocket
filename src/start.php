<?php

error_reporting(E_ALL);

ini_set('display_errors', 'on');

if (!extension_loaded('pcntl')) {
     exit("Please install pcntl extension.\n");
}

if (!extension_loaded('posix')) {
     exit("Please install posix extension.\n");
}

if (!extension_loaded('libevent')) {
     exit("Please install libevent extension.\n");
}

if (!extension_loaded('sysvmsg') || !extension_loaded('sysvsem') || !extension_loaded('sysvshm')) {
     exit("Please install sysvmsg|sysvsem|sysvshm extension.\n");
}


/**
 * -----------------------------------------------
 * 定义常量
 * -----------------------------------------------
 */
define("WORKER",      __DIR__);
define("WORKER_DATA", WORKER."/Worker/Data");
define("WORKER_LOG",  WORKER_DATA."/Log");

spl_autoload_register(function($className){
    if (strtolower(substr($className, 0, 6)) === 'worker') {
        if ($className{6} === '\\') {
            $classFile = str_replace("\\", '/', $className).'.php';
            include_once $classFile;
        } else {
            switch (strtolower($className)) {
                case 'workerserver':
                    class_alias("Worker\\Server", 'WorkerServer');
                    break;
                case 'workerclient':
                    class_alias("Worker\\Client", 'WorkerClient');
                    break;
                case 'workerprocess':
                    class_alias("Worker\\Process", 'WorkerProcess');
                    break;
                case 'workerlibutil':
                    class_alias("Worker\\Lib\\Util", 'WorkerLibUtil');
                default:
                    return false;
            }            
        }
    }
});

//register_shutdown_function(array('', ''));
