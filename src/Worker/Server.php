<?php
/**
 *  this part for net server
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author Bourne<61647649@qq.com>
 * @version 1.0
 * @copyright 3K, Inc.
 * @link http://www.3k.com
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 **/
namespace Worker
{
    use Worker\Ipc\MessageQueue;
    use Worker\Events\Libevent;
    use Worker\Timers\Timer;
    use Worker\Events\EventInterface;
    use Worker\Net\SocketInterface;
    use Worker\Lib\Util;
    use Worker\Net\SocketTcp;

    class Server
    {
        const STATUS_STARTING  = 1; // 开始状态
        const STATUS_RUNNING   = 2; // 运行中状态
        const STATUS_SHUTDOWN  = 3; // 停止状态
        const STATUS_RELOADING = 4; // 重新载入状态

        protected static $_startFile = ''; // 当前项目开始文件地址
        protected static $_pidFile = ''; // 进程ID存储地址
        protected static $_logFile = ''; // 日志文件地址
        protected static $_globalStatistics = array(
            'start_timestamp' => 0,
            'worker_exit_info' => array()
        ); // 统计数据
        protected static $_statisticsFile = '';
        protected static $_daemonize = false;  // 是否修改当前进程为守护进程
        protected static $_masterPid = 0; // 主进程ID
        protected static $_stdoutFile = '/dev/null'; // 重定向地址

        protected $messageQueue = null;
        public $name = '';                  // 当前worker名称
        public $count = 5;                  // 当前worker初始化多少个子进程
        public $taskCount = 2;              // 当前worker初始化多少个任务进程
        public $onWorkerStart = null;       // 当前worker下的每个子进程在开始的时候调用的
        public $onWorkerReload = null;      // 当前worker下的每个子进程在reload的时候回调
        public $onWorkerStop = null;        // 当前worker下的每个子进程停止回调函数
        public $reloadable = true;          // 当前WORKER下的子进程是否支持reload
        public $heartbeatCheckInterval = 5; // 当前worker下的子进程每5秒检查一次心跳
        public $heartbeatIdleTime = 10;     // 当前worker下的子进程如果超过10秒数据不通讯关闭连接
        public $user = null;                // 当前worker下的进程和子进程的用户
        public $group = null;               // 当前worker下的进程和子进程的用户组
        protected $_workerId = null;        // 当前worker的ID
        public $_methods = array();      // connect, receive, error, sendBufferFull, close, task(异步任务) 
        protected $_errorNo = 0;
        protected $_errorMsg = "";

        protected static $_reloadPids = array();           // 主进程有效，存储所有需要reload的子进程ID
        protected static $_status = self::STATUS_STARTING; // 进程当前状态
        public static $_globalEvent = null;             // 监听事件
        protected static $_idMap = array();                // 主进程有效，存储子进程, 存储方式 array("worker_id"=> array(pid1, pid2, pid3) ....);
        protected static $_pidMap = array();               // 主进程有效，存储子进程PID， [worker_id=>[pid=>pid, pid=>pid, ..], ..]
        protected static $_workers = array();              // 主进程有效，保存所有的worker实例, 子进程只存储子进程来源哪个worker实例

        // ----------------------------- Task进程使用参数 ------------------------------
        protected static $_idTaskMap = array(); // 存储Task子进程PID， [worker_id=>[pid=>pid, pid=>pid, ..], ..]
        // ----------------------------- Task进程使用参数 ------------------------------

        // ---------------------------- 网络相关 ------------------------------
        public $reusePort = false;
        protected $_socketName = ""; // tcp://127.0.0.1:8080
        protected $_context = ""; // 上下文资源
        protected $_mainSocket = null; // 当前worker在主进程创建的socket
        protected static $_builtinTransports = array(
            'tcp'  => 'tcp',
            'udp'  => 'udp',
            'unix' => 'unix',
        );
        protected $_transport = 'tcp'; 
        protected $_protocol = "";
        public $connections = array(); // 用于子进程, 存储子进程的当前的所有连接
        // ---------------------------- 网络相关 ------------------------------

        public function __construct($socketName = '', $contextOption = array())
        {
            $this->_workerId = spl_object_hash($this);
            self::$_workers[$this->_workerId] = $this;

            $this->_socketName = $socketName;
            // 设置每个监听的队列，可以满足多少个链接
            if (!isset($contextOption['socket']['backlog'])) {
                $contextOption['socket']['backlog'] = 1024;
            }

            $this->_context = stream_context_create($contextOption);
            $this->messageQueue = new MessageQueue($this->_workerId);
        }

        public static function start()
        {
            if (php_sapi_name() != 'cli') {
                exit("only run in command line mode.\n");
            }

            self::$_globalEvent = new Libevent();
            Timer::init(self::$_globalEvent);

            self::createLogFile();
            self::createStartFile();
            self::createPidFile();
            self::initStatistics();
            self::parseCommand();
            self::daemonize();
            self::setMasterPid();
            self::setProcessTitle("Worker: master process startFile= ". self::$_startFile);
            self::initWorkers();
            self::installSignal();
            self::forkTaskWorkers(); 
            self::forkWorkers(); 
            self::displayUI(); 
            self::resetStd(); 

            self::monitorWorkers();
        }

        protected static function monitorWorkers()
        {
            self::$_status = self::STATUS_RUNNING;
            while (1) {
                pcntl_signal_dispatch();
                // 阻塞的，等待信号信号或者进程返回，一旦子进程全部都没有了，就会不断返回
                $status = 0;
                $pid = pcntl_wait($status, WUNTRACED);
                pcntl_signal_dispatch();

                if ($pid > 0) {
                    $pidType = 0;
                    foreach (self::$_pidMap as $workerId => $workerPidArr) {
                        if (isset($workerPidArr[$pid])) {
                            $worker = self::$_workers[$workerId];
                            self::log("[".$worker->name."] Worker Process[$pid] exit with status[".$status."]");
                            unset(self::$_pidMap[$workerId][$pid]);
                            $id = self::getId($workerId, $pid);
                            self::$_idMap[$workerId][$id] = 0;
                            $pidType = 1;
                            break;
                        }
                    }

                    // 删除Task进程
                    foreach (self::$_idTaskMap as $worker_id => $worker_task_pid_array) {
                        if (isset($worker_task_pid_array[$pid])) {
                            $worker = self::$_workers[$worker_id];
                            self::log("[".$worker->name."] Task Process[$pid] exit with status[".$status."]");
                            unset(self::$_idTaskMap[$worker_id][$pid]);
                            $pidType = 2;
                            break;
                        }
                    }

                    if (self::$_status !== self::STATUS_SHUTDOWN) {
                        if ($pidType === 1) {
                            self::forkWorkers();
                        } else {
                            self::forkTaskWorkers();
                        }

                        if (in_array($pid, self::$_reloadPids)) {
                            $index = array_search($pid, self::$_reloadPids);
                            unset(self::$_reloadPids[$index]);
                            if (count(self::$_reloadPids) > 0) {
                                self::reload();
                            } else {
                                self::$_status = self::STATUS_RUNNING;
                            }
                        }
                    }

                } else {
                    if (self::$_status === self::STATUS_SHUTDOWN && !self::getAllWorkerPids() && !self::getAllTaskPids()) {
                        self::exitAndCleanAll();
                    }
                }
            }
        }

        // for master process call
        protected static function exitAndCleanAll()
        {
            self::cleanMessageQueue();
            @unlink(self::$_pidFile);
            self::log("WORKER[".basename(self::$_startFile)."] has been stopped.");
            exit(0);
        }

        /**
         * only run for master process
         */
        protected static function getAllWorkerPids()
        {
            $pidArray = array();
            foreach (self::$_pidMap as $workerPidArrays) {
                foreach ($workerPidArrays as $pid) {
                    $pidArray[$pid] = $pid;
                }
            }
            return $pidArray;
        }

        /**
         * only run for master process
         */
        protected static function getAllTaskPids()
        {
            $taskPidAarray = array();
            foreach (self::$_idTaskMap as $taskPidArrays) {
                foreach ($taskPidArrays as $taskPid) {
                    $taskPidAarray[$taskPid] = $taskPid;
                }
            }

            return $taskPidAarray;
        }

        protected static function displayUI()
        {
            /*{{{*/
            echo "\033[1A\n\033[K-----------------------\033[47;30m WORKERS \033[0m-----------------------------\n\033[0m";
            echo 'version:'. 1.0 . "          PHP version:", PHP_VERSION, "\n";
            echo "\033[1A\n\033[K-----------------------\033[47;30m WORKERS \033[0m-----------------------------\n\033[0m";

            foreach (self::$_workers as $worker) {
                echo $worker->user,"        ",$worker->name, " \033[32;40m [OK] \033[0m\n";
            }

            echo "-------------------------------------------------------------\n";
            if (self::$_daemonize) {
                global $argv;
                $start_file = $argv[0];
                echo "Input \"php $start_file stop\" to quit. Start success.\n";
            } else {
                echo "Press Ctrl-C to quit. Start success.\n";
            }
            /*}}}*/
        }

        protected static function forkTaskWorkers()
        {
            //  [worker_id=>[pid=>pid, pid=>pid, ..], ..]
            foreach (self::$_workers as $workerId => $worker) {
                while (count(self::$_idTaskMap[$workerId]) < $worker->taskCount) {
                    self::forkOneTaskWorker($worker);
                }
            }
        }

        protected static function forkOneTaskWorker($worker)
        {
            $taskPid = pcntl_fork();
            if ($taskPid > 0) { // for master process
                self::$_idTaskMap[$worker->_workerId][$taskPid] = $taskPid;
            } elseif (0 === $taskPid) { // for task process
                if (self::$_status === self::STATUS_STARTING) {
                    self::resetStd();
                }

                self::setProcessTitle("Worker: task process[".posix_getpid()."]; workerId = ". $worker->_workerId);
                $worker->setUserAndGroup();
                // --------------------------------  关闭不需要的参数 ----------------------------
                $worker->name = 'TaskProcess';
                unset($worker->count);
                unset($worker->taskCount);
                $worker->onWorkerStart = null;
                $worker->onWorkerReload = null;
                $worker->onWorkerStop = null;
                unset($worker->heartbeatCheckInterval);
                unset($worker->heartbeatIdleTime);
                //unset(self::$_reloadPids);
                self::$_reloadPids = null;
                self::$_idMap = null;
                self::$_idTaskMap = null;
                self::$_pidMap = null;
                unset($worker->reusePort);
                unset($worker->_socketName);
                unset($worker->_context);
                @fclose($worker->_mainSocket);
                unset($worker->_mainSocket);
                self::$_builtinTransports = null;
                unset($worker->_transport);
                unset($worker->_protocol);
                unset($worker->connections);
                // --------------------------------  关闭不需要的参数 ----------------------------
                self::$_workers = array($worker->_workerId => $worker);
                $worker->runTask();
                exit(250);
            } else {
                throw new \Exception("create Task Process error!");
            }
        }

        /**
         * only running in task process
         */
        protected function runTask()
        {
            self::$_status = self::STATUS_RUNNING;
            self::$_globalEvent = new Libevent();
            // 重新注册信号用libevent来处理
            self::resetInstallSignal();
            Timer::init(self::$_globalEvent);
            // TODO
            Timer::add(0.1, function($timer_id) {
                // 当前进程用于监听消息队列的数据，它也只做这个事情，所以就算做阻塞也没事，lievent不会做其他事情的
                // 获取所有队列中所有类型的数据
                $message = $this->messageQueue->receiveMsg(0); // 如果没有就阻塞
                if (false !== $message && !empty($message)) {
                    $taskID   = $message['content']['id'];
                    $taskData = $message['content']['data'];
                    if (isset($this->_methods['task']) && is_callable($this->_methods['task'])) {
                        try {
                            call_user_func($this->_methods['task'], $this, $taskID, $taskData);
                        } catch (\Exception $e) {
                            self::log("task callbackFunc Exception : " . $e->getMessage());
                        }
                    } 
                } 
            });

            self::$_globalEvent->loop();
            self::log("task process [".posix_getpid()."] exit.");
        }

        protected static function resetInstallSignal()
        {
            // add($flag, $fd, $callbackFunc, $args = array())
            pcntl_signal(SIGINT,  SIG_IGN, false);
            pcntl_signal(SIGUSR1, SIG_IGN, false);
            pcntl_signal(SIGUSR2, SIG_IGN, false);
            // reinstall stop signal
            self::$_globalEvent->add(EventInterface::EV_SIGNAL, SIGINT, array("\Worker\Server", "signalHandler"));
            // reinstall reload signal
            self::$_globalEvent->add(EventInterface::EV_SIGNAL, SIGUSR1, array("\Worker\Server", "signalHandler"));
            // resintall status signal
            self::$_globalEvent->add(EventInterface::EV_SIGNAL, SIGUSR2, array("\Worker\Server", "signalHandler"));
        }

        /**
         * 设置进程的用户和用户组
         */
        protected function setUserAndGroup()
        {
            $userInfo = posix_getpwnam($this->user);
            $gid = $userInfo['gid'];
            $uid = $userInfo['uid'];
            if ($uid != posix_getuid() || $gid != posix_getgid()) {
                posix_setgid($gid);
                posix_setuid($uid);
                posix_initgroups($userInfo['name'], $gid);
            }
        }


        protected static function forkWorkers()
        {
            //  [worker_id=>[pid=>pid, pid=>pid, ..], ..]
            foreach (self::$_workers as $workerId => $worker) {
                while (count(self::$_pidMap[$workerId]) < $worker->count) {
                    self::forkOneWorker($worker);
                }
            }
        }

        protected static function forkOneWorker($worker)
        {
            $id = self::getId($worker->_workerId, 0);
            $pid = pcntl_fork();
            if ($pid > 0) { // for master process
                self::$_pidMap[$worker->_workerId][$pid] = $pid;
                self::$_idMap[$worker->_workerId][$id]  = $pid;
            } elseif (0 === $pid) { // for worker process
                if (self::$_status === self::STATUS_STARTING) {
                    self::resetStd();
                }

                self::setProcessTitle("Worker: child process[".posix_getpid()."]; workerId = ". $worker->_workerId);
                $worker->setUserAndGroup();

                // --------------------------------  关闭不需要的参数 ----------------------------
                unset($worker->count);
                unset($worker->taskCount);
                self::$_reloadPids = null;
                self::$_idMap = null;
                self::$_idTaskMap = null;
                self::$_pidMap = null;
                // --------------------------------  关闭不需要的参数 ----------------------------
                self::$_workers = array($worker->_workerId => $worker);
                $worker->run();
                exit(250);
            } else {
                throw new \Exception("create Worker Process error!");
            }
        }

        /**
         * running only for worker process
         */
        protected function run()
        {
            self::$_status = self::STATUS_RUNNING;
            self::$_globalEvent = new Libevent();
            // 重新注册信号用libevent来处理
            self::resetInstallSignal();
            Timer::init(self::$_globalEvent);
            
            if ($this->onWorkerStart && is_callable($this->onWorkerStart)) {
                try {
                    call_user_func($this->onWorkerStart, $this);
                } catch (\Exception $e) {
                    self::log($e->getMessage());
                }
            }

            $this->net();
            self::$_globalEvent->loop();
            self::log("worker process [".posix_getpid()."] exit.");
        }

        // only for worker process
        protected function net()
        {
            if ($this->_socketName) {
                if ($this->_transport !== 'udp') {
                    self::$_globalEvent->add(EventInterface::EV_READ, $this->_mainSocket, array($this, 'acceptConnection'), array($this->_mainSocket));
                    foreach (self::$_workers as $worker) {
                        // 添加定时器做心跳检查
                        Timer::add($this->heartbeatCheckInterval, function($timerId) use (&$worker){
                            if ($worker->connections) {
                                self::log("对进程[".posix_getpid()."] 的所有连接检查一次心跳.");
                                $time_now = time();
                                foreach ($worker->connections as $record_id => $connection) {
                                    if (empty($connection->lastMessageTime)) {
                                        $connection->lastMessageTime = $time_now;
                                        continue;
                                    }
                                    // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
                                    if ($time_now - $connection->lastMessageTime > $worker->heartbeatIdleTime) {
                                        $connection->close();
                                    }
                                }
                            } 
                        });
                        break;
                    }
                } else {
                    //self::$_globalEvent->add(EventInterface::EV_READ, $this->_mainSocket, array($this, 'acceptUdpConnection'), array($this->_mainSocket));
                }
            }
        }

        /**
         * 处理TCP的链接
         */
        public function acceptConnection($mainSocket)
        {
            // 创建tcp服务
            $connectSocket = new SocketTcp($mainSocket, $this, $this->_protocol);
            if (!$connectSocket->socketTcp) { // 创建tcp服务失败
                unset($connectSocket);
                return;
            }

            $this->connections[$connectSocket->id] = $connectSocket;
            $this->connections[$connectSocket->id]->connect();
        }

        protected function acceptUdpConnection($mainSocket)
        {
            // TODO 暂时不做
        }

        public function on($methodName, $func)
        {
            $methods = array('connect', 'receive', 'error', 'sendBufferFull', 'close', 'task');
            if (in_array($methodName, $methods)) {
                $this->_methods[$methodName] = $func;
                return true;
            }

            return false;
        }


        /**
         * running for master process
         */
        protected static function getId($workerId, $pid)
        {
            $id = array_search($pid, self::$_idMap[$workerId]);
            if ($id === false) {
                return false;
            }
            return $id;
        }

        protected static function resetStd()
        {
            if (!self::$_daemonize) 
            {
                return;
            }
            global $STDOUT, $STDERR;
            $handle = fopen(self::$_stdoutFile, "a");
            if ($handle) 
            {
                unset($handle);
                @fclose(STDOUT);
                @fclose(STDERR);
                $STDOUT = fopen(self::$_stdoutFile, "a");
                $STDERR = fopen(self::$_stdoutFile, "a");
            } 
            else 
            {
                throw new \Exception('can not open stdoutFile ' . self::$_stdoutFile);
                exit(250);
            }
        }

        protected static function installSignal()
        {
            // stop
            pcntl_signal(SIGINT,  array('\Worker\Server', 'signalHandler'), false);
            // reload
            pcntl_signal(SIGUSR1, array('\Worker\Server', 'signalHandler'), false);
            // status
            pcntl_signal(SIGUSR2, array('\Worker\Server', 'signalHandler'), false);
            // 忽略对SIGPIPE信号当前进程接收到之后不做任何处理, 直接忽略
            pcntl_signal(SIGPIPE, SIG_IGN, false);
        }

        public static function signalHandler($signo)
        {
            switch ($signo) {
                case SIGINT: // stop
                    self::stopAll();
                    break;
                case SIGUSR1: // reload
                    if (posix_getpid() === self::$_masterPid) {
                        self::setReloadPids(); 
                    }
                    self::reload();
                    break;
                case SIGUSR2: // status
                    self::writeStatisticsToStatusFile();
                    break;
            }
        }

        /**
         * only run for master process
         */
        protected static function setReloadPids()
        {
            $workerPids = self::getAllWorkerPids();
            $taskPids = self::getAllTaskPids();
            $reloadablePids = array();

            foreach (self::$_pidMap as $workerId => $workerPidsArr) {
                $worker = self::$_workers[$workerId];
                if ($worker->reloadable) {
                    foreach ($workerPidsArr as $pid) {
                        $reloadablePids[$pid] = $pid;
                    }
                }
            }

            foreach (self::$_idTaskMap as $workerId => $taskPidArr) {
                $worker = self::$_workers[$workerId];
                if ($worker->reloadable) {
                    foreach ($taskPidArr as $pid) {
                        $reloadablePids[$pid] = $pid;
                    }
                }
            }

            $tmp1 = array_intersect($reloadablePids, $workerPids);
            $tmp2 = array_intersect($reloadablePids, $taskPids);
            self::$_reloadPids = array_unique(array_merge($tmp1, $tmp2));
        }

        protected static function reload()
        {
            if (self::$_masterPid === posix_getpid()) {
                if (self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN) {
                    self::log("WORKER[" . basename(self::$_startFile) . "] reloading");
                    self::$_status = self::STATUS_RELOADING;
                }

                $onWorkerPid = current(self::$_reloadPids);
                posix_kill($onWorkerPid, SIGUSR1);

            } else {
                foreach (self::$_workers as $worker) {
                    if ($worker->onWorkerReload && is_callable($worker->onWorkerReload)) {
                        try {
                            call_user_func($worker->onWorkerReload, $worker);
                        } catch (\Exception $e) {
                            self::log($e->getMessage(). " callbackFunc realod error.");
                        }
                    }
                }      

                self::stopAll();
            }
        }

        protected static function writeStatisticsToStatusFile()
        {
            if (posix_getpid() === self::$_masterPid) {
                $loadavg = sys_getloadavg();
                file_put_contents(self::$_statisticsFile,
                    "---------------------------------------GLOBAL STATUS--------------------------------------------\n");
                file_put_contents(self::$_statisticsFile,
                    'WORKRE version:1.0'."          PHP version:" . PHP_VERSION . "\n", FILE_APPEND);
                file_put_contents(self::$_statisticsFile, 'start time:' . date('Y-m-d H:i:s',
                    self::$_globalStatistics['start_timestamp']).'   run '.floor((time() - self::$_globalStatistics['start_timestamp']) / (24 * 60 * 60)).' days '.
                    floor(((time() - self::$_globalStatistics['start_timestamp']) % (24 * 60 * 60)) / (60 * 60))." hours ".
                    floor((((time() - self::$_globalStatistics['start_timestamp']) % (24 * 60 * 60)) % (60 * 60)) / 60)  ." min   \n", FILE_APPEND);
                $load_str = 'load average: ' . implode(", ", $loadavg);
                file_put_contents(self::$_statisticsFile, str_pad($load_str, 33) . "\nevent-loop:libevent\n", FILE_APPEND);
                file_put_contents(self::$_statisticsFile,
                    count(self::$_pidMap) . ' workers       ' . count(self::getAllWorkerPids()) . " processes       ".
                    count(self::getAllTaskPids())." tasks\n",
                    FILE_APPEND);
                file_put_contents(self::$_statisticsFile, "\n", FILE_APPEND);
                file_put_contents(self::$_statisticsFile, "\n", FILE_APPEND);
                file_put_contents(self::$_statisticsFile, 
                    "---------------------------------------PROCESS STATUS--------------------------------------------\n", FILE_APPEND);

                $workerPids = self::getAllWorkerPids();
                foreach ($workerPids as $pid) {
                    posix_kill($pid, SIGUSR2);
                }

                $taskPids = self::getAllTaskPids();
                foreach ($taskPids as $taskPid) {
                    posix_kill($taskPid, SIGUSR2);
                }

            } else { // for child process
                foreach (self::$_workers as $workerId => $worker) {
                    file_put_contents(self::$_statisticsFile,
                        "pid:". posix_getpid() . 
                        "    status:" . self::$_status . 
                        "    name:".$worker->name.
                        "    user:".$worker->user.
                        "    connection_count:".SocketInterface::$statistics['connection_count'].
                        "    total_request:".SocketInterface::$statistics['total_request'].
                        "    throw_exception:".SocketInterface::$statistics['throw_exception'].
                        "    send_fail:".SocketInterface::$statistics['send_fail'].
                        "\n", FILE_APPEND);
                }
            }

            chmod(self::$_statisticsFile, 0722);
        }


        protected static function stopAll()
        {
            self::$_status = self::STATUS_SHUTDOWN;

            if (self::$_masterPid == posix_getpid()) { // for master process
                self::log("Worker Process[".basename(self::$_startFile)."] Stopping ...");
                $workerPids = self::getAllWorkerPids();
                foreach ($workerPids as $pid) {
                    posix_kill($pid, SIGINT);
                    Timer::add(1, function($timerId) use ($pid) {
                        posix_kill($pid, SIGKILL);
                    }, false);
                }

                $taskPids = self::getAllTaskPids();
                foreach ($taskPids as $taskPid) {
                    posix_kill($taskPid, SIGINT);
                    Timer::add(1, function($timerId) use ($taskPid) {
                        posix_kill($taskPid, SIGKILL);
                    }, false);
                }
            } else { // for task_process and  worker_process
                foreach (self::$_workers as $worker) { 
                    $worker->stop(); // 子进程收到信号，我们是用$this 找不到worker对象的，所以用这种方式处理
                }

                self::log("exit task/worker process[".posix_getpid()."] success.");
                exit(0);
            }
        }

        protected function stop()
        {
            if ($this->onWorkerStop && is_callable($this->onWorkerStop)) {
                try {
                    call_user_func($this->onWorkerStop, $this);   
                } catch (\Exception $e) {
                    self::log("onWorkerStop func error.");
                }
            }
        }

        protected static function setMasterPid()
        {
            self::$_masterPid = posix_getpid();
            if (false === @file_put_contents(self::$_pidFile, self::$_masterPid)) {
                throw new \Exception('can not save pid to ' . self::$_pidFile);
                exit(250);
            }
        }

        protected static function daemonize()
        {
/*{{{*/
            if (!self::$_daemonize) {
                return;
            }

            // 先屏蔽所有的权限
            umask(0);
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new Exception('fork fail');
                exit(250);
            } elseif ($pid > 0) {
                // parent process exit;
                exit(0);
            }

            // start child process;
            // 设置新会话
            if (-1 === posix_setsid()) {
                throw new \Exception("setsid fail!");
                exit(250);
            }

            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new \Exception("fork fail!");
                exit(250);
            } elseif (0 !== $pid) {
                // parent process;
                exit(0);
            }
            // start child's child process;
        /*}}}*/
        }

        /**
         * 清空队列 master process running
         */
        protected static function cleanMessageQueue() 
        {
            foreach (self::$_workers as $workerId => $worker) {
                $worker->messageQueue->removeQueue();
            }
        }

        /**
         * 解析命令行
         * php yourfile.php start -d
         */
        protected static function parseCommand()
        {
            /*{{{*/
            global $argv;
            $startFile = $argv[0];
            if (!isset($argv[1])) {
                self::cleanMessageQueue();
                exit("Usage: php yourfile.php {start|stop|restart|reload|status|kill}\n");
            }

            $command = trim($argv[1]);
            $command2 = isset($argv[2]) ? $argv[2] : '';

            $mode = '';
            if ($command === 'start') {
                if ($command2 === '-d' || self::$_daemonize) {
                    $mode = 'in daemon mode';     
                } else {
                    $mode = 'in debug mode';
                }
            }

            self::log("WORKER[$startFile] $command $mode");

            // 获取主进程ID
            $master_pid = @file_get_contents(self::$_pidFile);
            // 给主进程发送一个0信号，返回成功表进程还在
            $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
            // 保证进程存在就不要start，不存在就先要start和restart
            if ($master_is_alive) {
                if ($command === 'start') {
                    self::cleanMessageQueue();
                    self::log("WORKER[$startFile] already running.");
                    exit;
                }
            } elseif ($command !== 'start' && $command !== 'restart') {
                self::cleanMessageQueue();
                self::log("WORKER[$startFile] not run.");
                exit;
            }

            switch ($command) {
                case 'start':
                    if ($command2 === '-d') {
                        self::$_daemonize = true;
                    }
                    break;
                case 'kill': 
                    self::cleanMessageQueue();
                    exec("ps aux | grep $startFile | grep -v grep | awk '{print $2}' |xargs kill -SIGINT");
                    usleep(100000);
                    exec("ps aux | grep $startFile | grep -v grep | awk '{print $2}' |xargs kill -SIGKILL");
                    break;
                case 'status':
                    self::cleanMessageQueue();
                    if (is_file(self::$_statisticsFile)) {
                        @unlink(self::$_statisticsFile);
                    }
                    // 主进程将发送信号给所有的子进程
                    posix_kill($master_pid, SIGUSR2);
                    usleep(400000);
                    @readfile(self::$_statisticsFile);
                    exit(0);
                    break;
                case 'reload':
                    self::cleanMessageQueue();
                    $master_pid && posix_kill($master_pid, SIGUSR1);
                    self::log("WORKER[$startFile] reload.");
                    exit(0);
                    break;
                case 'restart':
                case 'stop':
                    self::log("WORKER[$startFile] is stoping ...");
                    $master_pid && posix_kill($master_pid, SIGINT); // 给主进程发送ctl+c产生的信号
                    // Timeout
                    $timeout = 10;
                    $start_time = time();
                    // 检查主进程是否还是存在状态
                    while(1) {
                        $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                        if ($master_is_alive) {
                            if (time() - $start_time >= $timeout) {
                                self::log("WORKER[$startFile] stop fail.");
                                exit(250);
                            }
                            usleep(10000);
                            continue;
                        }

                        self::log("WORKER[$startFile] stop success");
                        if ($command === 'stop') {
                            self::cleanMessageQueue();
                            exit(0);
                        }

                        if ($command2 === '-d') {
                            self::$_daemonize = true;
                        }
                        break;
                    }
                    break;
                default:
                    exit("Usage: php yourfile.php {start|stop|restart|reload|status|kill}\n");
            }
            /*}}}*/
        }

        /**
         * 初始化每个worker的参数
         */
        protected static function initWorkers() 
        {
            foreach (self::$_workers as $workerId => $worker) {
                self::$_idMap[$workerId]     = array_fill(0, $worker->count, 0);
                self::$_pidMap[$workerId]    = array();
                self::$_idTaskMap[$workerId] = array();

                if (empty($worker->name)) {
                    $worker->name = 'none';
                }

                if (empty($worker->user)) {
                    $userInfo = posix_getpwuid(posix_getuid());
                    $worker->user = $userInfo['name'];
                }

                $worker->createMainSocket();
            }
        }

        /**
         * 给每个WORKER设置一个socket
         */
        protected function createMainSocket()
        {
            /*{{{*/
            if ($this->_socketName) { // json://0.0.0.0:9501
                if ($this->reusePort) {
                    // 是否开启重用端口，好处是可以不用多进程也可以多个socket, bind同一个端口
                    stream_context_set_option($this->_context, 'socket', 'so_reuseport', 1);
                }

                list($scheme, $address) = explode(":", $this->_socketName, 2);
                $localSocket = $this->_socketName;

                if (!isset(self::$_builtinTransports[$scheme])) {
                    $this->_protocol = ucfirst($scheme);
                    $localSocket = $this->_transport .":". $address;
                } else {
                    $this->_transport = self::$_builtinTransports[$scheme];
                }

                $flags = $this->_transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND|STREAM_SERVER_LISTEN;
                $this->_mainSocket = stream_socket_server($localSocket, $this->_errorNo, $this->_errorMsg, $flags, $this->_context);
                if (!$this->_mainSocket) {
                    throw new \Exception($this->_errorMsg);
                    exit(250);
                }

                if (function_exists('socket_import_stream') && $this->_transport === 'tcp') {
                    $socket = socket_import_stream($this->_mainSocket); // 将streamsocket转换成scoket
                    // SO_KEEPALIVE系统默认是设置的2小时的心跳频率。但是它检查不到机器断电、网线拔出、防火墙这些断线。
                    // 而且逻辑层处理断线可能也不是那么好处理
                    @socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1); // 开启心跳模式，保证如果客户端不在了，关闭链接
                    @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1); // 提高tcp的响应能力
                }

                stream_set_blocking($this->_mainSocket, 0); // 我们做的是异步非阻塞，这里接收数据和发送数据都改成非阻塞
                // 到这里位置，在主进程每个WORKER都有了自己要监听的socket
            }
/*}}}*/
        }

        /**
         * 设置当前进程标题
         */
        protected static function setProcessTitle($titleName) 
        {
            // 设置进程标题 
            if (function_exists('cli_set_process_title')) { // >= php5.5
                @cli_set_process_title($titleName);
            } else if (extension_loaded('proctitle') && function_exists('setproctitle')) { // Need proctitle extension when php <= 5.5
                @setproctitle($titleName);
            }
        }

        protected static function initStatistics()
        {
            self::$_globalStatistics['start_timestamp'] = time();
            self::$_globalStatistics['worker_exit_info'] = array();
            self::$_statisticsFile = sys_get_temp_dir() ."/worker.status";
        }

        // start file
        protected static function createStartFile() 
        {
            $backrace = debug_backtrace();
            self::$_startFile = $backrace[count($backrace)-1]['file'];
        }

        // master pid file
        protected static function createPidFile()
        {
            if (empty(self::$_pidFile)) {
                self::$_pidFile = WORKER_DATA."/".str_replace("/", '_', self::$_startFile).".pid";
            }
        }

        // log file
        protected static function createLogFile()
        {
            if (empty(self::$_logFile)) {
                self::$_logFile = WORKER_LOG."/worker.log";
            }
            touch(self::$_logFile);
            chmod(self::$_logFile, 0622);
        }

        // log message
        public static function log($message)
        {
            $message = date('Y-m-d H:i:s')." [".posix_getpid()."] : " . $message."\n";
            if (!self::$_daemonize) {
                echo $message;
            }
            file_put_contents(self::$_logFile, $message, FILE_APPEND|LOCK_EX);
        }

        /**
         * running in callbackFunc for worker-process
         */
        public function send($connectSocket, $data)
        {
            return $connectSocket->send($data);
        }

        public function close($connectSocket)
        {
            $connectSocket->close();
        }

        /**
         * 主进程中无效, 因为主进程在调用的时候，还没有创建Timer监听呢
         * only run for worker/task-process
         */
        public function tick($timeInterval, $func)
        {
            if (is_int($timeInterval)) {
                return Timer::add($timeInterval, $func);
            }

            return false;
        }

        /**
         * 主进程中无效，因为主进程在调用的时候，还没有创建Timer监听呢
         * only run for worker/task-process
         */
        public function after($timeInterval, $func)
        {
            if (is_int($timeInterval)) {
                return Timer::add($timeInterval, $func, false);
            }

            return false;
        }

        /**
         * 主进程中无效，因为主进程在调用的时候，还没有创建Timer监听呢
         * only run for worker/task-process
         */
        public function clearTimer($timerId)
        {
            Timer::del($timerId);
        }

        /**
         * 主进程中无效，因为主进程在调用的时候，还没有创建Timer监听呢
         * only run for worker/task-process
         */
        public function clearAllTimer()
        {
            Timer::delAll();
        }

        /**
         * 可以在主进程和子进程中调用，消息发送给worker所在的messagequeue
         */
        public function task($taskData)
        {
            // 生成TaskID
            $taskID = Util::currentTime().rand(10000, 99999);
            return $this->messageQueue->sendMsg(1, array("id"=>$taskID, "data"=>$taskData), true);
        }

        /**
         * only runing for worker child process  
         */
        public function heartbeat($if_close_connection = false)
        {
            $sockets = array();
            if ($this->connections) {
                $time_now = time();
                foreach ($this->connections as $record_id => $connection) {
                    echo "对进程[".posix_getpid()."] $record_id 的所有连接检查一次.\n";
                    if (empty($connection->lastMessageTime)) {
                        $connection->lastMessageTime = $time_now;
                        continue;
                    }
                    // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
                    if ($time_now - $connection->lastMessageTime > $this->heartbeat_idle_time) {
                        if ($if_close_connection) {
                            $connection->close();
                        } else {
                            $sockets[] = $connection;
                        }
                    }
                }
            }
            return $sockets;
        }
    }
}
