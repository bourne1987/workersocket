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
    use Worker\Protocols\ProtocolInterface;
    use Worker\Events\GlobalEvent;

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
        protected $protocol   = ""; // 当前客户端的需要的解析协议
        protected $transport  = 'tcp'; // 通讯方式, 默认是TCP协议

        // connect的时候存储: 
        // 值array("host" => '','port' => '','local_socket' => 'tcp://127.0.0.1:9501','timeOut' => '', 'flag' => 同步/异步)
        protected $socketName = array(); 

        // 注册的函数 "connect", "receive", "error", "close"
        protected $onMethods  = array();
        protected $socket     = null;   // 当前链接的socket
        protected $error      = "";     // 当前链接的错误信息
        protected $errno      = "";     // 当前链接的错误编号
        protected $recvBuffer = "";     // 接收到的所有数据
        protected $sendBuffer = ""; 
        protected $isPersistent = false; // 是否长链
        protected $currentPackageLength = 0; // 当前包长度

        
        /**
         * __construct('json', Client::SOCKET_SYNC);
         * 
         * @param int $scheme 表示当前scheme需要用TCP通讯方式还是UDP通讯方式
         * @param int $isSync 阻塞/非阻塞
         * @param $isPersistent  TRUE长链/FLASE短链, 表示下次过来这个链接会不会复用
         * @return void
         */
        public function __construct($scheme, $isSync = self::SOCKET_SYNC, $isPersistent = false)
        {
            /*{{{*/
            if (strtolower($scheme) !== 'tcp' && strtolower($scheme) !== 'udp') {
                $this->protocol = ucfirst($scheme);
            }

            if (isset(self::$builtinTransports[$scheme])) {
                $this->transport = self::$builtinTransports[$scheme];
            }

            $this->isSync = $isSync; // 同步/异步
            $this->isPersistent = $isPersistent;
            /*}}}*/
        }

        /**
         * connect 同步函数
         * 
         * @param mixed $host 地址
         * @param mixed $port 端口
         * @param mixed $timeOut 超时时间, 如果设置为-1; 那就走默认的超时时间
         * @param string $flag 阻塞/非阻塞 SOCKET_ASYNC/SOCKET_SYNC
         * @return void 成功返回当前socket，失败返回false
         */
        public function connect($host, $port, $timeOut = -1, $flag = "")
        {
            // 如果当前客户端已经有链接连上了， 必须先close掉在然后connect
            if ($this->isConnected()) {
                $this->error("connection been create, please close connection first.");
                return false;
            }

            $this->socketName['host'] = $host;
            $this->socketName['port'] = $port;
            $this->socketName['local_socket'] = $this->transport."://".$host.":".$port;

            if ($timeOut === -1) {
                $timeOut = ini_get("default_socket_timeout");
            }

            $this->socketName['timeOut'] = $timeOut;

            if ($this->transport === "tcp") {
                $flag = empty($flag) ? $this->isSync : $flag;
                $this->socketName['flag'] = $flag;

                if ($flag === self::SOCKET_SYNC) {
                    $flags = $this->isPersistent ? STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT : STREAM_CLIENT_CONNECT;
                } else {
                    $flags = STREAM_CLIENT_ASYNC_CONNECT;
                }

                $this->socket = @stream_socket_client($this->socketName['local_socket'], $this->errno, $this->error, $timeOut, $flags);

                if (!$this->isConnected()) {
                    $this->error("create client connection error.");
                    return false;
                }

                if ($flag === self::SOCKET_ASYNC) {
                    stream_set_blocking($this->socket, 0);
                    if (isset($this->onMethods['connect']) && is_callable($this->onMethods['connect'])) {
                        try {
                            call_user_func($this->onMethods['connect'], $this);
                        } catch (\Exception $e) {
                            $this->error($e->getMessage());
                        }
                    }

                    GlobalEvent::getEvent()->add(EventInterface::EV_READ, $this->socket, array($this, "asyncRead"));
                    GlobalEvent::getEvent()->loop();
                } else {
                    stream_set_timeout($this->socket, $this->socketName['timeOut']); // 设置客户端socket超时时间
                    return $this->socket;
                }
            } elseif ($this->transport === 'udp') {
                // TODO udp实现
            }
        }

        /**
         * 异步读取socket服务端传递过来的数据
         */
        public function asyncRead()
        {
            $buffer = @fread($this->socket, self::READ_BUFFER_SIZE);/*{{{*/
            if ($buffer === '' || $buffer === false) { 
                // if socket been closed ; so closed this socket;
                if (!is_resource($this->socket) || feof($this->socket) || $buffer === '') {
                    $this->error("asyncRead socket been closed.");
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
                        $this->error("WorkerClient asyncRead Data for protocol error!");
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

            return;/*}}}*/
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
            if (is_resource($this->socket)) {
                return socket_import_stream($this->socket);
            }
            return false;
        }

        /**
         * 返回stream socket
         */
        public function getStreamSocket()
        {
            return $this->socket;
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
         * 失败返回false, 没写完返回null， 写完返回true
         */
        public function send($sendData)
        {
            /*{{{*/
            if (!$sendData) { // 不可发送空数据包
                $this->error("can't send null-data to server.");
                return false;
            }

            if ($this->protocol) {
                $protocol = "\\Worker\\Protocols\\".$this->protocol;
                $sendData = $protocol::encode($sendData, $this);
                if ($sendData === '') {
                    $this->error('client pack data error.');
                    return false;
                }
            }

            if ($this->sendBuffer === '') {
                if (self::MAX_SEND_BUFFER_SIZE <= strlen($sendData)) {
                    $this->error("send data to server more than max buffer.");
                    return false;
                }

                $len = @fwrite($this->socket, $sendData);
                if ($len === strlen($sendData)) {
                    return true;
                }

                if ($len > 0) {
                    $this->sendBuffer = substr($sendData, $len);
                } else {
                    if (!is_resource($this->socket) || feof($this->socket)) {
                        $this->error("send data to server error, socket been closed!");
                        $this->close();
                        return false;
                    }

                    $this->sendBuffer = $sendData;
                }
                
                if (self::MAX_SEND_BUFFER_SIZE <= strlen($this->sendBuffer)) {
                    $this->error("sendbuffer is full.");
                }
                
                GlobalEvent::getEvent()->add(EventInterface::EV_WRITE, $this->socket, array($this, 'baseWrite'));

                return null;
            } else {
                if (self::MAX_SEND_BUFFER_SIZE <= strlen($this->sendBuffer)) {
                    $this->error("drop send package, because sendbuffer is full.");
                    return false;
                }

                $this->sendBuffer .= $sendData;

                if (self::MAX_SEND_BUFFER_SIZE <= strlen($this->sendBuffer)) {
                    $this->error("sendbuffer is full.");
                }

                return null;
            }/*}}}*/
        }

        public function baseWrite()
        {
            /*{{{*/
            if (strlen($this->sendBuffer) <= 0) {
                GlobalEvent::getEvent()->del(EventInterface::EV_WRITE, $this->socket);
            }

            $len = @fwrite($this->socket, $this->sendBuffer);
            if ($len === strlen($this->sendBuffer)) {
                GlobalEvent::getEvent()->del(EventInterface::EV_WRITE, $this->socket);
                $this->sendBuffer = '';
            }

            if ($len > 0) {
                $this->sendBuffer = substr($this->sendBuffer, $len);
            } else {
                if (!is_resource($this->socket) || feof($this->socket)) {
                    $this->error("send data to server error, socket been closed!");
                    $this->close();
                }
            }
            /*}}}*/
        }

        /**
         * 同步函数
         * recv 接收数据返回，这个函数会一次收取所有发送过来的数据, 所以数据
         * 有可能是半个包+一个包； 一个包 + 半个包；半个包 + 半个包
         * 
         * @param int $size 长度
         * @param int $flag 是否等待所有数据全部返回 0、non-wait， 1、wait
         * @return void
         */
        public function recv($size = 65535, $flag = 0)
        {
            $readData = "";
            if ($size <= 0) {
                $this->error("recv data's size is null.");
                return false;
            }

            while ($size > 0) {
                $readBuffer = @fread($this->socket, $size);
                if ($readBuffer === '' || $readBuffer === false) { 
                    // if socket been closed ; so closed this socket;
                    if (!is_resource($this->socket) || feof($this->socket) || $readBuffer === '') {
                        $this->error("recv client socket been closed.");
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

            if ($this->protocol) {
                $protocol = "\\Worker\\Protocols\\".$this->protocol;
                $length = $protocol::input($readData);

                if ($length === ProtocolInterface::PACKAGE_NOT_COMPLETE) {
                    $this->error("recv data don't complete.");
                    return false;
                } else if ($length > 0)  {
                    if ($length > strlen($readData)) { // 不够一个包
                        $this->error("recv data don't complete.");
                        return false;
                    }
                } else {
                    $this->error("recv data error.");
                    // 出现解包错误，证明当前链接的协议都不对，必须关掉当前链接
                    $this->close();
                    return false;
                }

                $OneCompletePack = "";
                if ($length === strlen($readData)) {
                    $OneCompletePack = $readData;
                } else {
                    $OneCompletePack = substr($readData, 0, $length);
                }

                return $protocol::decode($OneCompletePack, $this);
            }

            return $readData;
        }

        /**
         * 关闭客户端
         */
        public function close()
        {
            if (GlobalEvent::getEvent()) {
                GlobalEvent::getEvent()->del(EventInterface::EV_READ, $this->socket);
                GlobalEvent::getEvent()->del(EventInterface::EV_WRITE, $this->socket);
            }

            $this->recvBuffer = "";
            $this->sendBuffer = "";

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
                    throw new Exception($e->getMessage());
                }
            }
        }

        /**
         * 定时器
         */
        public static function tick($timeInterval, $func)
        {
            if (is_int($timeInterval)) {
                return GlobalEvent::getEvent()->add(EventInterface::EV_TIMER, $timeInterval, $func);
            }

            return NULL;
        }

        /**
         * 多少时间之后执行
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

        /**
         * 获取最后的链接错误信息
         */
        public function getLastError()
        {
            return $this->error;
        }

        /**
         * 获取最后的链接错误代码
         */
        public function getLastErrorNo()
        {
            return $this->errno;
        }

        /**
         * TODO 
         * 向任意IP:PORT的主机发送UDP数据包
         */
        public function sendto($ip, $port, $data)
        {
                
        }
    }
}

