<?php
/**
 * 1、每次NEW一个WORKERPROCESS对象，只能开一个子进程调用一次start()
 * 2、将当前进程变成守护进程，调用的时候必须在最前面
 * 3、每个WorkerProcessD对象，只能fork一个子进程，父子进程都保存了管道
 * 4、管道的读写，主进程和子进程都可以使用
 * 5、注册信号函数，在使用之后，必须调用loop函数
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
    use Worker\Ipc\Pipe;
    use Worker\Events\EventInterface;
    use Worker\Events\GlobalEvent;
    use Exception;

    class Process
    {
        // 存储所有的Process对象 array("processId"=>$processe...)
        protected static $processes = array();
        protected $processId = "";
        protected static $masterPid = "";
        // 主进程设置 
        protected $callFunc = ""; 
        // 是否重定向主进程设置 
        protected $redirect_stdin_stdout = false;
        // 是否创建管道主进程设置 
        protected $create_pipe = false;
        // 是否已经start了子进程了
        protected $started = false;


        // 当前子进程名
        protected $name = "";
        // 每个进程都有自己的管道
        protected $pipe = ""; 
        protected static $stdoutFile = '/dev/null'; // 重定向文件
        protected static $daemon = false; // 是否守护进程的标记
        public $pid = ""; // 当前子进程ID

        /**
         * function : 回调函数
         * redirect_stdin_stdout 是否重定向
         * $create_pipe 是给当前进程创建管道
         */
        function __construct($function, $redirect_stdin_stdout = false, $create_pipe = true)
        {
            $this->callFunc   = $function;
            $this->create_pipe = $create_pipe;
            $this->redirect_stdin_stdout = $redirect_stdin_stdout;
            $this->processId = spl_object_hash($this);
            self::$processes[$this->processId] = $this;
            if (!self::$masterPid) {
                self::$masterPid = posix_getpid();
            }
        }

        /**
         * 设置进程名称, running for master process
         */
        public function name($name)
        {
            $this->name = $name;
        }

        // 主/子进程调用
        public function write($data)
        {
            return $this->pipe->send($data);
        }

        // 主/子进程调用
        public function read($buffer_size = 8192)
        {
            return $this->pipe->revice($buffer_size);
        }

        // 主进程调用, 并且一个workerprocess对象只能调用一次
        public function start()
        {
            if ($this->started === true) {
                return false;
            }

            $this->started = true;

            // 创建属于当前进程自己的管道文件
            if ($this->create_pipe === true) {
                $this->pipe = new Pipe($this->processId);
                if ($this->pipe->pipeFlag !== true) { // 创建管道文件不成功，退出子进程
                    throw new Exception("create pipe error.");
                    exit(250);
                }
            }

            $pid = pcntl_fork();
            if ($pid > 0) { // for master process
                $this->pid = "";
            } else if ($pid === 0) {  // for child process
                self::$processes = array();
                $this->pid = posix_getpid();
                // 设置进程标题
                if (empty($this->name)) {
                    self::setProcessTitle("WORKER PROCESS : none");
                } else {
                    self::setProcessTitle("WORKER PROCESS : ".$this->name);
                }

                if ($this->redirect_stdin_stdout) {
                    self::resetStd();
                }

                if ($this->callFunc && is_callable($this->callFunc)) {
                    try {
                        call_user_func($this->callFunc, $this);
                    } catch (Exception $e)  {
                        self::log("call process func error : ". $e->getMessage());
                    }                   
                }

                GlobalEvent::getEvent()->loop();
                self::log("process[".$this->pid."] exit sucess.");
                exit(0);
            } elseif ($pid < 0) {
                throw new Exception("cant't create process.");
                exit(250);
            } 

            // master process
            return $pid;
        }

        /**
         * 等待回收一个进程$blocking = true 等待阻塞/ false 不阻塞
         * running only for master process
         */
        public static function wait($blocking = true)
        {
            $status = $pid = 0;

            if ($blocking === false) {
                $pid = pcntl_wait($status, WNOHANG);
            } else {
                $pid = pcntl_wait($status, WUNTRACED);
            }

            return array('code' => $status, 'pid' => $pid);
        }

        /**
         * 重定向标准输入和输出
         */
        protected static function resetStd()
        {
            /*{{{*/
            global $STDOUT, $STDERR;
            $handle = fopen(self::$stdoutFile, "a");
            if ($handle) {
                unset($handle);
                @fclose(STDOUT);
                @fclose(STDERR);
                $STDOUT = fopen(self::$stdoutFile, "a");
                $STDERR = fopen(self::$stdoutFile, "a");
            } else {
                throw new Exception('can not open stdoutFile ' . self::$stdoutFile);
                exit(250);
            }
            /*}}}*/
        }

        /**
         * 修改进程名
         */
        protected static function setProcessTitle($title)
        {
            /*{{{*/
            if (function_exists('cli_set_process_title')) { // >= php5.5
                @cli_set_process_title($title);
            } else if (extension_loaded('proctitle') && function_exists('setproctitle')) { // Need proctitle extension when php <= 5.5
                @setproctitle($title);
            }/*}}}*/
        }

        /**
         * 是当前进程变成守护进程, 调用的时候，一定要在最开始调用
         * nochdir为true表示不修改当前目录。默认false表示将当前目录切换到“/” 
         * $noclose，默认false表示将标准输入和输出重定向到/dev/null
         */
        public static function daemon($nochdir = false, $noclose = false)
        {
            /*{{{*/
            self::$daemon = true;
            umask(0);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new Exception("create daemon process error.");
                exit(250);
            } elseif ($pid > 0) {
                exit(0);
            }

            if (-1 === posix_setsid()) {
                throw new Exception("setsid fail!");
                exit(250);
            }

            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new Exception("create daemon child process error.");
                exit(250);
            } elseif ($pid > 0) {
                exit(0);
            } 

            if (!$noclose) {
                self::resetStd();
            }

            if (!$nochdir) {
                chdir("/");
            }
            /*}}}*/
        }
        
        protected static function log($message)
        {
            $message = date('Y-m-d H:i:s')." [".posix_getpid()."] : " . $message."\n";/*{{{*/
            if (!self::$daemon) {
                echo $message;
            }
            file_put_contents(WORKER_LOG."/process.log", $message, FILE_APPEND|LOCK_EX);/*}}}*/
        }
        
        /**
         * 只能用于event的进程
         */
        public static function signal($signo, $callback)
        {
            GlobalEvent::getEvent()->add(EventInterface::EV_SIGNAL, $signo, $callback);
        }

        /**
         * 给某个进程发送信号
         */
        public static function kill($pid, $signo = 0)
        {
            return @posix_kill($pid, $signo);
        }

        /**
         * 新增事件监听
         */
        public static function event_add($flag, $fd, $callback)
        {
            GlobalEvent::getEvent()->add($flag, $fd, $callback);
        }

        /**
         * 删除某个事件监听
         */
        public static function event_del($flag, $fd)
        {
            GlobalEvent::getEvent()->del($flag, $fd);
        }

        /**
         * 清空所有监听事件
         */
        public static function clearEvents()
        {
            GlobalEvent::clearEvents();
        }

        /**
         * 循环事件
         */
        public static function loop()
        {
            GlobalEvent::getEvent()->loop();
        }

        /**
         * 定时器, 毫秒级
         */
        public static function tick($timeInterval, $func)
        {
            if (is_int($timeInterval)) {
                return GlobalEvent::getEvent()->add(EventInterface::EV_TIMER, $timeInterval, $func);
            }

            return NULL;
        }

        /**
         * 多少时间之后执行, , 毫秒级
         */
        public static function after($timeInterval, $func)
        {
            if (is_int($timeInterval)) {
                return GlobalEvent::getEvent()->add(EventInterface::EV_TIMER_ONCE, $timeInterval, $func);
            }

            return NULL;
        }

        /**
         * 删除某个定时器
         */
        public static function clearTimer($timerId)
        {
            return GlobalEvent::getEvent()->del(EventInterface::EV_TIMER, $timerId);
        }
        
        /**
         * 删除所有定时器
         */
        public static function clearAllTimer()
        {
            GlobalEvent::getEvent()->clearAllTimer();
        }

        public function __destruct()
        {
            /**
             * 主进程退出才销毁
             */
            if (self::$masterPid === posix_getpid() && self::$processes) {
                foreach (self::$processes as $process) {
                    $process->pipe->destroy();
                }
            }
        }
    }
}
