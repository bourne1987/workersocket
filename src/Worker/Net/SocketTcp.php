<?php
/**
 * 
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

namespace Worker\Net 
{
    use Worker\Events\EventInterface;
    use Worker\Server;
    use Worker\Protocols\Http;
    use Worker\Protocols\ProtocolInterface;

    class SocketTcp extends SocketInterface      
    {
        const READ_BUFFER_SIZE = 65535; // 每次读取最大1024*1024字节的长度
        const MAX_SEND_BUFFER_SIZE = 10485760; // 允许发送的最大数据长度
        const MAX_PACKAGE_SIZE = 10485760; // 每个包的最大长度

        const STATUS_ESTABLISH = 0; // create connection socket from main socket;
        const STATUS_CONNECTING = 1; // connection socket callback connect's method
        const STATUS_CLOSED = 2; // connection socket will be closed

        private $_serv = null;
        private $_mainSocket = null;
        private $_socket = ""; // 链接上的socket
        private $_remote_address = ""; // 远程客户端的信息
        private $_recvBuffer = ""; // 接收的数据
        private $_sendBuffer = ""; // 发送的数据
        private $_id = 0; // id copy
        // 因为同一个进程，可以new多个SocketTcp来处理链接，有时候一次读取或者发送数据并不能完成, 
        // 所以，我们就需要用id标识，标识当前进程的链接
        private static $_idRecorder = 1; // 用于计数当前的链接socket的唯一标识
        private $_protocol = ''; // 当前链接使用什么协议
        private $_currentPackageLength = 0; // 当前拿到的链接传过来的包的长度
        private $_status = self::STATUS_ESTABLISH; // 初始化状态

        public $id = 0; // 当前链接的ID
        public $socketTcp = true; // 判断是否创建此类成功
        
        public $lastMessageTime = 0;

        public function __construct($mainSocket, $serv, $protocol)
        {
            // 子进程从listen队列里面拿出一个socket链接
            $remote_address = '[]';
            $this->_socket = @stream_socket_accept($mainSocket, 0, $remote_address);

            if ($this->_socket) {
                $this->_protocol = $protocol;
                $this->_serv = $serv;
                $this->_mainSocket = $mainSocket;
                $this->_remote_address = $remote_address;
                SocketInterface::$statistics['connection_count']++;
                stream_set_blocking($this->_socket, 0);
                stream_set_read_buffer($this->_socket, 0);
                $this->id = $this->_id = self::$_idRecorder++;
            } else {
                $this->socketTcp = false;
            }
        }

        /**
         * connect 链接回调
         * 
         * @return void
         */
        public function connect()
        {
            $this->_status = self::STATUS_CONNECTING;
            // 链接之后，要链接的socket监听读取, 可读的时候就会返回, 一直可读不断返回（用于数据太多，一次读不完的情况下）, 
            // 只有当客户端发送数据过来了，才是可读的
            Server::$_globalEvent->add(EventInterface::EV_READ, $this->_socket, array($this, "receive"));

            if (isset($this->_serv->_methods['connect']) && is_callable($this->_serv->_methods['connect'])) {
                try {
                    call_user_func_array($this->_serv->_methods['connect'], array($this->_serv, $this, $this->_id));
                } catch (\Exception $e) {
                    SocketInterface::$statistics['throw_exception']++;
                    Server::log($e->getMessage()." connect callbackFunc fail.");
                }
            }
        }

        /**
         * receive 接收数据之后并回调, 一次只能读取1M的数据
         */
        public function receive()
        {
            SocketInterface::$statistics['total_request']++;
            // 从当前子进程的链接中读取READ_BUFFER_SIZE个数据
            $buffer = fread($this->_socket, self::READ_BUFFER_SIZE);

            if ($buffer === '' || $buffer === false) { 
                // if client been closed , server will be closed this conneciton;
                if (feof($this->_socket) || !is_resource($this->_socket) || $buffer === false) { 
                    $this->destroy();
                    return;
                }
                SocketInterface::$statistics['throw_exception']++;
                return;
            } else {
                $this->_recvBuffer .= $buffer;
            }

            /*{{{*/
            // 从这里开始; revBuffer 必定不为空
            if ($this->_protocol) { // 如果需要用协议解析, 进入协议解析模式
                $protocol = "\\Worker\\Protocols\\".$this->_protocol;

                while ($this->_recvBuffer !== '') {
                    $this->_currentPackageLength = $protocol::input($this->_recvBuffer);

                    if ($this->_currentPackageLength === ProtocolInterface::PACKAGE_NOT_COMPLETE) {
                        return;
                    } elseif ($this->_currentPackageLength > 0) {
                        // 证明当前接收到的数据还不完整, 需要继续接收, 返回等待下一次调度
                        if ($this->_currentPackageLength > strlen($this->_recvBuffer)) {
                            return;
                        }
                    } else {
                        SocketInterface::$statistics['throw_exception']++;
                        Server::log("error package, packageLength=".var_export($this->_currentPackageLength, true));
                        $this->close();
                        return;
                    }

                    // 到这里，只有，packageLength > 0 && packageLength <= recvBuffer
                    $OneCompletePack = "";
                    if (strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                        // 表示，当前包是一个完整的包了 
                        $OneCompletePack = $this->_recvBuffer;
                        $this->_recvBuffer = "";
                    } else {
                        $OneCompletePack = substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                        $this->_recvBuffer = substr($this->_recvBuffer, $this->_currentPackageLength);
                    }

                    if (isset($this->_serv->_methods['receive']) && is_callable($this->_serv->_methods['receive'])) {
                        try {
                            call_user_func_array($this->_serv->_methods['receive'], 
                                array($this->_serv, $this, $this->_id, $protocol::decode($OneCompletePack, $this)));
                        } catch (\Exception $e) {
                            SocketInterface::$statistics['throw_exception']++;
                            Server::log($e->getMessage()." receive callbackFunc fail.");
                        }
                    }
                }

                $this->lastMessageTime = time();
                return;
            }/*}}}*/

            if (isset($this->_serv->_methods['receive']) && is_callable($this->_serv->_methods['receive'])) {
                try {
                    call_user_func_array($this->_serv->_methods['receive'], 
                        array($this->_serv, $this, $this->_id, $this->_recvBuffer));
                } catch (\Exception $e) {
                    SocketInterface::$statistics['throw_exception']++;
                    Server::log($e->getMessage()." receive callbackFunc fail.");
                }
            } 

            // clean recBuffer
            $this->_recvBuffer = '';
            $this->lastMessageTime = time();
            return;
        }

        /**
         * send 发送数据
         */
        public function send($send_buffer) 
        {
            // 需要发送的数据是不是需要通过协议做包装
            if ($this->_protocol) {
                $protocol = "\\Worker\\Protocols\\".$this->_protocol;
                $send_buffer = $protocol::encode($send_buffer, $this);
                if ($send_buffer === '') {
                    Server::log("send buffer error.");
                    SocketInterface::$statistics['send_fail']++;
                    return null;
                }
            }

            // first call this method send data to client
            if ($this->_sendBuffer === '') {
                $len = fwrite($this->_socket, $send_buffer);
                // send data success for one time
                if ($len === strlen($send_buffer)) {
                    return true;
                } 

                // a part of data been send success
                if ($len > 0) {
                    // save not send data
                    $this->_sendBuffer = substr($send_buffer, $len);
                } else { 
                    SocketInterface::$statistics['send_fail']++;
                    // send data fail
                    if (!is_resource($this->_socket) || feof($this->_socket)) {
                        if (isset($this->_serv->_methods['error']) && is_callable($this->_serv->_methods['error'])) {
                            try {
                                call_user_func($this->_serv->_methods['error'], $this->_serv, $this, $this->_id, "client closed");
                            } catch (\Exception $e) {
                                SocketInterface::$statistics['throw_exception']++;
                                Server::log($e->getMessage()." error callbackFunc fail.");
                            }
                        }
                        $this->destroy();
                        return false;
                    }

                    // if client don't closed , send data to client again;
                    $this->_sendBuffer = $send_buffer;
                }

                // 检查未发送的数据的长度，超过我的最大发送buffer, 
                $this->checkSendBufferIsFull();

                // add event for residual data
                Server::$_globalEvent->add(EventInterface::EV_WRITE, $this->_socket, array($this, "baseWrite"));
                return null;
            } else { 
                // 有可能，对同一个socket链接调用了多次发送数据， 那么在第二次调用send的时候，$this->_sendBuffer 可能不为空
                // 因为，第一次调用send发送数据的时候，数据有可能发送不完
                if (self::MAX_SEND_BUFFER_SIZE <= strlen($this->_sendBuffer)) {
                    SocketInterface::$statistics['send_fail']++;
                    if (isset($this->_serv->_methods['error']) && is_callable($this->_serv->_methods['error'])) {
                        try {
                            call_user_func($this->_serv->_methods['error'], $this->_serv, $this, $this->_id,"sent buffer full and drop package");
                        } catch (\Exception $e) {
                            SocketInterface::$statistics['throw_exception']++;
                            Server::log($e->getMessage()." error callbackFunc fail.");
                        }
                    }

                    // send buffer is full, so drop package;
                    return false;
                }

                $this->_sendBuffer .= $send_buffer;
                $this->checkSendBufferIsFull();
                return null;
            }
        }

        public function checkSendBufferIsFull()
        {
            if (self::MAX_SEND_BUFFER_SIZE <= strlen($this->_sendBuffer)) {
                if (isset($this->_serv->_methods['sendBufferFull']) && is_callable($this->_serv->_methods['sendBufferFull'])) {
                    try {
                        call_user_func($this->_serv->_methods['sendBufferFull'], $this->_serv, $this, $this->_id, "send buffer full");
                    } catch (Exception $e) {
                        SocketInterface::$statistics['throw_exception']++;
                        Server::log($e->getMessage()." sendBufferFull callbackFunc fail.");
                    }
                }
            }
        }

        /**
         * 发送之前没有发送的数据, 这些数据被存储到了buffer里面;
         * $this->_sendBuffer 一旦满了，是不会再增加了
         */
        public function baseWrite()
        {
            if (strlen($this->_sendBuffer) <= 0) {
                Server::$_globalEvent->del(EventInterface::EV_WRITE, $this->_socket);
                $this->_sendBuffer = "";
                if ($this->_status === self::STATUS_CLOSED) {
                    $this->destroy();
                }
                return ;
            }

            $len = @fwrite($this->_socket, $this->_sendBuffer);
            // send buffer over
            if ($len === strlen($this->_sendBuffer)) {
                Server::$_globalEvent->del(EventInterface::EV_WRITE, $this->_socket);
                $this->_sendBuffer = '';

                // 要发送的数据发送完了， 如果当前的状态是closed，那么我们就可以关闭了
                if ($this->_status === self::STATUS_CLOSED) {
                    $this->destroy();
                }
            } 

            if ($len > 0) {
                $this->_sendBuffer = substr($this->_sendBuffer, $len);
            } else {
                SocketInterface::$statistics['send_fail']++;
                $this->destroy();
            }
        }

        /**
         * 删除当前进程中对当前连接的read/write的监听, 并关闭连接
         * 将当前状态改成closed
         */
        public function destroy()
        {
            // 避免重复删除监听事件
            if ($this->_status === self::STATUS_CLOSED) {
                return;
            }

            $this->_status = self::STATUS_CLOSED;
            Server::$_globalEvent->del(EventInterface::EV_READ, $this->_socket);
            Server::$_globalEvent->del(EventInterface::EV_WRITE, $this->_socket);
            @fclose($this->_socket);

            if ($this->_serv && isset($this->_serv->connections[$this->_id])) {
                unset($this->_serv->connections[$this->_id]);
            }

            if (isset($this->_serv->_methods['close']) && is_callable($this->_serv->_methods['close'])) {
                try {
                    call_user_func($this->_serv->_methods['close'], $this->_serv, $this->_id);
                } catch (\Exception $e) {
                    Server::log($e->getMessage()." close callbackFunc error.");
                    SocketInterface::$statistics['throw_exception']++;
                }
            }
        }

        public function close()
        {
            // if status is closed, don't do close again;
            if ($this->_status === self::STATUS_CLOSED) {
                return;
            } 

            // 需要发送的数据已经发送完了，直接调用关闭吧
            if ($this->_sendBuffer === '') {
                $this->destroy();
            }
        }

        /**
         * 获取客户端IP
         */
        public function getRemoteIp()
        {
            $pos = strrpos($this->_remote_address, ':');
            if ($pos) {
                return trim(substr($this->_remote_address, 0, $pos), '[]');
            }
            return '';
        }

        /**
         * 获取客户端端口
         */
        public function getRemotePort()
        {
            if ($this->_remote_address) {
                return (int)substr(strrchr($this->_remote_address, ':'), 1);
            }
            return 0;
        }
        
        public function __destruct()
        {
            if ($this->socketTcp) {
                echo "销毁:【".posix_getpid()."】; RecordID = ".$this->_id."\n";
                SocketInterface::$statistics['connection_count']--;
            }
        }
    }
}

