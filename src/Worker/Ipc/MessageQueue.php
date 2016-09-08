<?php
/**
 * this is part of tenacious
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
namespace Worker\Ipc
{
    use Worker\Lib\Util;

    class MessageQueue
    {
        /**
         * 消息队列路径
         */
        const QUEUE_PATH   = "/tmp/msg.queue";
        private $_path = "";
        public  $errorCode = "";
        private $_msg      = NULL;
        private $_queueKey = "";

        public function __construct($pathName = "", $proj = "")
        {
            $pathName = empty($pathName) ? self::QUEUE_PATH : self::QUEUE_PATH.".".$pathName;
            if (!file_exists($pathName)) {
                if (@touch($pathName) === false) {
                    return false;
                }
            }

            $this->_path = $pathName;

            $proj = empty($proj) ? 'c' : $proj;
            $this->_queueKey = ftok($pathName, $proj);
            if ($this->_queueKey === -1) {
                Util::log("create message queue's key error.");
                return false;
            }

            $this->_msg = msg_get_queue($this->_queueKey, 0666);
            if (!is_resource($this->_msg)) {
                Util::log("create message queue error.");
                return false;
            }

            // 修改队列的长度 msg_qbytes, macbook pro 修改无效默认1024byte
            msg_set_queue($this->_msg, array('msg_qbytes' => 65535));
            return true;
        }

        /**
         * $msgType 类型
         * $message 消息内容
         * $blocking true/false 队列满阻塞/不阻塞
         */
        public function sendMsg($msgType, $message, $blocking = true)
        {
            if (@msg_send($this->_msg, $msgType, $message, true, $blocking, $this->errorCode)) {
                return true;
            }

            return false;
        }

        /**
         * $desiredmsgtype 0， 获取所有类型消息， 不为零获取相应类型的数据
         * nowait = MSG_IPC_NOWAIT, 默认为0 是阻塞的, 阻塞/不阻塞等待消息来
         */
        public function receiveMsg($desiredmsgtype, $nowait = 0)
        {
            $msgType = $data =  "";
            if (@msg_receive($this->_msg, $desiredmsgtype, $msgType, 8192, $data, true, $nowait, $this->errorCode)) {
                return array("msg_type" => $msgType, "content" => $data);
            }

            return false;
        }

        public function queueStat()
        {
            return @msg_stat_queue($this->_msg);
        }

        public function removeQueue()
        {
            $flag = @msg_remove_queue($this->_msg);
            @unlink($this->_path);
            return $flag;
        }

        public function queueExists($key = null)
        {
            if (empty($key)) {
                return @msg_queue_exists($this->_queueKey);
            } else {
                return @msg_queue_exists($key);
            }
        }

        public function getLastErrorCode()
        {
            return $this->errorCode;
        }
    }    
}
