<?php
/**
 * this is part of client socket 
 *
 * 此对象不能在Server.php中运行，因为会造成同一个进程多个libevent对象
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
    use Worker\Events\EventInterface;
    use Worker\Events\Libevent;
    use Worker\Protocols\ProtocolInterface;
    use Worker\Timers\Timer;

    class Client
    {
        const READ_BUFFER_SIZE = 65535; // 每次读取最大1024*1024字节的长度
        const MAX_SEND_BUFFER_SIZE = 10485760; // 允许发送的最大数据长度
        const MAX_PACKAGE_SIZE = 10485760; // 每个包的最大长度

        /**
         * 异步非阻塞
         */
        const SOCKET_ASYNC = 1;
        /**
         * 同步阻塞
         */
        const SOCKET_SYNC = 2;

        protected static $builtinTransports = array(
            'tcp'  => 'tcp',
            'udp'  => 'udp',
        );

        protected $isSync     = "";
        protected $event      = null;
        protected $protocol   = ""; // 当前客户端的需要的解析协议
        protected $transport  = 'tcp'; // 通讯方式, 默认是TCP协议

        protected $socketName = array(); 
        // array("host" => '', 'port' => '', 'local_socket' => 'tcp://127.0.0.1:9501', 'timeOut' => '', 
        // 'flag' => 同步/异步)

        // 注册的函数 "connect", "receive", "error", "close"
        protected $onMethods  = array();
        protected $socket     = null;
        protected $context    = "";
        protected $error      = "";
        protected $errno      = "";
        protected $recvBuffer = "";
        protected $currentPackageLength = 0; // 当前包长度
        protected $isPersistent = false;

        
        /**
         * __construct('json', Client::SOCKET_SYNC);
         * 
         * @param int $scheme 表示当前scheme需要用TCP通讯方式还是UDP通讯方式
         * @param int $isSync 阻塞/非阻塞
         * @param $isPersistent  长链/短链, 表示下次过来这个链接会不会复用
         * @return void
         */
        public function __construct($scheme, $isSync = self::SOCKET_SYNC, $isPersistent = false)
        {
            /*{{{*/
            if (empty($this->event)) {
                $this->event = new Libevent();
                Timer::init($this->event);
            }

            if (strtolower($scheme) !== 'tcp' && strtolower($scheme) !== 'udp') {
                $this->protocol = ucfirst($scheme);
            }

            if (isset(self::$builtinTransports[$scheme])) {
                $this->transport = self::$builtinTransports[$scheme];
            }

            $this->isSync = $isSync;
            $this->isPersistent = $isPersistent;
            /*}}}*/
        }

        /**
         * connect 同步函数
         * 
         * @param mixed $host 地址
         * @param mixed $port 端口
         * @param mixed $timeOut 超时时间
         * @param string $flag 阻塞/非阻塞
         * @return void
         */
        public function connect($host, $port, $timeOut = -1, $flag = "")
        {
            $this->socketName['host'] = $host;
            $this->socketName['port'] = $port;
            $this->socketName['local_socket'] = $this->transport."://".$host.":".$port;
            if ($timeOut === -1) {
                $timeOut = ini_get("default_socket_timeout");
            }
            $this->socketName['timeOut'] = $timeOut;

            if ($this->transport === "tcp") {
                $flag = empty($flag) ? $this->isSync : $flag;
                if ($flag === self::SOCKET_SYNC) {
                    $flag = $this->isPersistent ? STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT : STREAM_CLIENT_CONNECT;
                } else {
                    $flag = STREAM_CLIENT_ASYNC_CONNECT;
                }

                $this->socketName['flag'] = $flag;
                $this->socket = @stream_socket_client($this->socketName['local_socket'], $this->errno, $this->error, $timeOut, $flag);
                if (!$this->isConnected()) {
                    return false;
                }

                if ($this->isSync === self::SOCKET_ASYNC) {
                    stream_set_blocking($this->socket, 0);
                    if (isset($this->onMethods['connect']) && is_callable($this->onMethods['connect'])) {
                        try {
                            call_user_func($this->onMethods['connect'], $this);
                        } catch (\Exception $e) {
                           $this->error($e->getMessage());
                        }
                    }

                    $this->event->add(EventInterface::EV_READ, $this->socket, array($this, "asyncRead"));
                    $this->event->loop();
                    exit(0);
                } 

                // 同步阻塞
                return true;
            } elseif ($this->transport === 'udp') {
                // TODO udp实现
            }
        }

        /**
         * 异步读取socket服务端传递过来的数据
         */
        public function asyncRead()
        {
            $buffer = @fread($this->socket, self::READ_BUFFER_SIZE);
            if ($buffer === '' || $buffer === false) { 
                // if socket been closed ; so closed this socket;
                if (!is_resource($this->socket) || feof($this->socket) || $buffer === '') {
                    $this->close();
                }
                return;
            } else {
                $this->recvBuffer .= $buffer;
            }

            // 接收的数据需要通过协议解析
            if ($this->protocol) {
                $protocol = "\\Worker\\Protocols\\".$this->protocol;
                while ($this->recvBuffer != '') {
                    $this->currentPackageLength = $protocol::input($this->recvBuffer);

                    if ($this->currentPackageLength === ProtocolInterface::PACKAGE_NOT_COMPLETE) {
                        return;
                    } elseif ($this->currentPackageLength > 0) {
                        if ($this->currentPackageLength > strlen($this->recvBuffer)) {
                            return;
                        }
                    } else {
                        echo "worker client receive buffer error!\n";
                        $this->close();
                        return;
                    }

                    $oneCompletePack = "";
                    if ($this->currentPackageLength === $this->recvBuffer) {
                        $OneCompletePack = $this->recvBuffer;
                        $this->recvBuffer = "";
                    } else {
                        $OneCompletePack = substr($this->recvBuffer, 0, $this->currentPackageLength);
                        $this->recvBuffer = substr($this->recvBuffer, $this->currentPackageLength);
                    }

                    if (isset($this->onMethods['receive']) && is_callable($this->onMethods['receive'])) {
                        try {
                            call_user_func($this->onMethods['receive'], $this, $protocol::decode($OneCompletePack, $this));
                        } catch (\Exception $e) {
                            $this->error($e->getMessage());
                        }
                    }
                }
                return;
            }

            if (isset($this->onMethods['receive']) && is_callable($this->onMethods['receive'])) {
                try {
                    call_user_func($this->onMethods['receive'], $this, $this->recvBuffer);
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                }
            }
            $this->recvBuffer = "";
            return;
        }

                
        /**
         * 检查当前client是否连接上了服务器
         */
        public function isConnected()
        {
            if (is_resource($this->socket) && !feof($this->socket)) {
                return true;
            } else {
                return false;
            }
        }

        /**
         * 返回当前连接的socket
         */
        public function getSocket()
        {
            return socket_import_stream($this->socket);
        }

        /**
         * 调用成功返回一个数组，如：array('host' => '127.0.0.1', 'port' => 53652)
         */
        public function getSocketName()
        {
            return $this->socketName;
        }

        /**
         * 同步函数
         * 发送数据到远程服务器，必须在建立连接后，才可向Server发送数据
         * 成功发送返回的已发数据长度
         * 失败返回false
         */
        public function send($sendData)
        {
            if ($this->protocol) {
                $protocol = "\\Worker\\Protocols\\".$this->protocol;
                $sendData = $protocol::encode($sendData, $this);
                if ($sendData === '') {
                    echo "包有问题。\n";
                    return false;
                }
            }

            if (strlen($sendData) > self::MAX_SEND_BUFFER_SIZE) {
                echo "包过大\n";
                $this->close();
                return false;
            }

            $sendLength = "";

            while (strlen($sendData) > 0 && ($sendLength = @fwrite($this->socket, $sendData)) > 0) {
                if ($sendLength === strlen($sendData)) {
                    break;
                }
                $sendData = substr($sendData, $sendLength);
            }

            if (!is_resource($this->socket) || feof($this->socket) || $sendLength === false || $sendLength < 0 ) {
                $this->close();
                return false;
            }

            return true;
        }
        
        /**
         * TODO 
         * 向任意IP:PORT的主机发送UDP数据包
         */
        public function sendto($ip, $port, $data)
        {
                
        }

        /**
         * 同步函数
         * recv 接收数据返回，这个函数会一次收取所有发送过来的数据
         * 
         * @param int $size 长度
         * @param int $flag 是否等待所有数据全部返回 0、non-wait， 1、wait
         * @return void
         */
        public function recv($size = 65535, $flag = 0)
        {
            $readData = "";
            while ($size > 0) {
                $readBuffer = @fread($this->socket, $size);
                if ($readBuffer === '' || $readBuffer === false) { 
                    // if socket been closed ; so closed this socket;
                    if (!is_resource($this->socket) || feof($this->socket) || $readBuffer === '') {
                        $this->close();
                    }
                    return false;
                } else {
                    $readData .= $readBuffer;
                }

                if (strlen($readBuffer) === $size) {
                    break;
                }

                $size = $size - strlen($readBuffer);
                if (0 === $flag) {
                    break;
                }
            }

            //var_dump($readData);    
            if ($this->protocol) {
                $protocol = "\\Worker\\Protocols\\".$this->protocol;
                $length = $protocol::input($readData);
                if ($length === ProtocolInterface::PACKAGE_NOT_COMPLETE) {
                    return false;
                } else if ($length > 0)  {
                    if ($length > strlen($readData)) { // 不够一个包
                        return false;
                    }
                } else {
                    echo "worker client rec method buffer error!\n";
                    $this->close();
                    return false;
                }

                $OneCompletePack = "";
                if ($length === strlen($readData)) {
                    $OneCompletePack = $readData;
                } else {
                    $OneCompletePack = substr($readData, 0, $length);
                }

                $readData = $protocol::decode($OneCompletePack, $this);
                return $readData;
            }

            return $readData;
        }

        /**
         * 关闭客户端
         */
        public function close()
        {
            if ($this->event) {
                $this->event->del(EventInterface::EV_READ, $this->socket);
                $this->event->del(EventInterface::EV_WRITE, $this->socket);
            }

            if (isset($this->onMethods['close']) && is_callable($this->onMethods['close'])) {
                try {
                    call_user_func($this->onMethods['close'], $this);
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                }
            }

            return @fclose($this->socket);
        }


        protected function error($execptionError)
        {
            if (isset($this->onMethods['error']) && is_callable($this->onMethods['error'])) {
                try {
                    call_user_func($this->onMethods['error'], $this, $execptionError);
                } catch (\Exception $e) {
                    exit(0);
                }
            }
        }

        /**
         * 哪里都可以用，只要你可以让cli常驻
         */
        public function tick($time_interval, $func)
        {
            return Timer::add($time_interval, $func, true);
        }

        /**
         * 哪里都可以用
         */
        public function after($time_interval, $func)
        {
            Timer::add($time_interval, $func, false);
        }

        /**
         * 哪里都可以用
         */
        public function clearTimer($timer_id)
        {
            Timer::del($timer_id);
        }

        /**
         * 哪里都可以用
         */
        public function clearAllTimer()
        {
            Timer::delAll();
        }


        public function __destruct()
        {
            //echo "___销毁客户端___\n";
        }

        /**
         * 注册异步函数
         */
        public function on($methodName, $method)
        {
            $methods = array("connect", "receive", "error", "close");
            if (in_array($methodName, $methods) && is_callable($method)) {
                $this->onMethods[$methodName] = $method;
            }
        }
    }
}

