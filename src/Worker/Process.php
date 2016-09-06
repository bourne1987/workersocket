<?php
/**
 * 1、PHP用libevent没有办法监听管道FD，做不了异步，所以改成用text协议来做通讯
 * 这个并没有做好，暂时先不要用
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
        public $name = "";// 当前子进程名

        // 进程执行函数 主进程设置 *
        protected $callFunc = ""; 
        // 是否重定向主进程设置 *
        protected $redirect_stdin_stdout = false;
        // 是否创建管道主进程设置 *
        protected $create_pipe = false;

        protected static $pipe      = ""; // 当前子进程创建的管道的描述符, 子进程设置
        protected static $pid       = 0;  // 当前创建的子进程ID; 子进程设置 *
        protected static $pids      = array(); // 存储所有的子进程
        protected static $masterPid = 0;

        protected static $stdoutFile = '/dev/null'; // 重定向文件*
        private static $_daemon = false; // 是否守护进程的标记

        /**
         * function : 回调函数
         * redirect_stdin_stdout 是否重定向
         * $create_pipe 是给当前进程创建管道
         */
        function __construct($function, $redirect_stdin_stdout = false, $create_pipe = true)
        {
            $this->callFunc   = $function;
            $this->create_pipe = $create_pipe;
            $this->redirect_stdin_stdout   = $redirect_stdin_stdout;
        }

        // 子进程调用
        public function write($data)
        {
            return self::$pipe->send($data);
        }

        // 子进程调用
        public function read($buffer_size = 8192)
        {
            return self::$pipe->revice($buffer_size);
        }

        // 主进程调用
        public function start()
        {
            $pid = pcntl_fork();
            if ($pid === 0) {  // for child process
                // 设置进程标题
                if (empty($this->name)) {
                    self::setProcessTitle("WORKER PROCESS : none");
                } else {
                    self::setProcessTitle("WORKER PROCESS : ".$this->name);
                }

                self::$pids = array();
                self::$pid =  posix_getpid(); // 设置当前进程ID

                if ($this->redirect_stdin_stdout) {
                    self::resetStd();
                }

                if ($this->create_pipe && empty(self::$pipe)) {
                    // 给当前子进程创建一个管道
                    self::$pipe = new Pipe();
                    if (self::$pipe->pipeFlag !== true) {
                        self::$pipe = NULL;
                        throw new Exception("create pipe error.");
                        exit(250);
                    }
                    // 忽略对SIGPIPE信号当前进程接收到之后不做任何处理, 直接忽略
                    pcntl_signal(SIGPIPE, SIG_IGN, false);
                }

                if ($this->callFunc && is_callable($this->callFunc)) {
                    try {
                        call_user_func($this->callFunc, $this);
                    } catch (Exception $e)  {
                        self::log("call process func error : ". $e->getMessage());
                    }                   
                }

                GlobalEvent::getEvent()->loop();
                self::log("process[".self::$pid."] exit sucess.");
                exit(0);
            } elseif ($pid < 0) {
                self::waitAll();
                throw new Exception("cant't create process.");
                exit(250);
            } 
            
            // for master process
            self::$pids[$pid] = $pid;
            if (!self::$masterPid) {
                self::$masterPid = posix_getpid();
            }
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
         * 阻塞回收所有的进程
         */
        public static function waitAll()
        {
            while (count(self::$pids) > 0) {
                pcntl_signal_dispatch();
                $status = 0;
                $pid = pcntl_wait($status, WUNTRACED);   
                if (isset(self::$pids[$pid])) {
                    unset(self::$pids[$pid]);
                }
                pcntl_signal_dispatch();
                self::log("回收进程[$pid]---状态[$status]");
            }
        }

        /**
         * 重定向标准输入和输出
         */
        protected static function resetStd()
        {
            /*{{{*/
            global $STDOUT, $STDERR;
            $handle = fopen(self::$_stdoutFile, "a");
            if ($handle) {
                unset($handle);
                @fclose(STDOUT);
                @fclose(STDERR);
                $STDOUT = fopen(self::$_stdoutFile, "a");
                $STDERR = fopen(self::$_stdoutFile, "a");
            } else {
                throw new Exception('can not open stdoutFile ' . self::$_stdoutFile);
                exit(250);
            }
            /*}}}*/
        }

        /**
         * 修改进程名
         */
        protected static function setProcessTitle($title)
        {
            if (function_exists('cli_set_process_title')) { // >= php5.5/*{{{*/
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
            self::$_daemon = true;
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
            if (!self::$_daemon) {
                echo $message;
            }
            file_put_contents(WORKER_LOG."/process.log", $message, FILE_APPEND|LOCK_EX);/*}}}*/
        }
        
        public static function signal($signo, $callback)
        {
            if (posix_getpid() !== self::$masterPid) {
                // 子进程的信号用event, 子进程 没有while，用event处理信号，不用dispatch
                GlobalEvent::getEvent()->add(EventInterface::EV_SIGNAL, $signo, $callback);
            } else {
                // 主进程设置信号用pcntl_signal, 因为主进程用while循环去dispatch，注意pcntl_wait会被信号打断
                pcntl_signal($signo, $callback, false);
            }
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
         * 定时器
         */
        public static function tick($timeInterval, $func)
        {
            GlobalEvent::getEvent()->add(EventInterface::EV_TIMER, $timeInterval, $func);
        }

        /**
         * 多少时间之后执行
         */
        public static function after($timeInterval, $func)
        {
            GlobalEvent::getEvent()->add(EventInterface::EV_TIMER_ONCE, $timeInterval, $func);
        }

        /**
         * 删除某个定时器
         */
        public static function clearTimer($timerId)
        {
            GlobalEvent::getEvent()->del(EventInterface::EV_TIMER, $timerId);
        }
        
        /**
         * 删除所有定时器
         */
        public static function clearAllTimer()
        {
            GlobalEvent::getEvent()->clearAllTimer();
        }
    }
}
