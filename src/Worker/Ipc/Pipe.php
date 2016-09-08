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

    class Pipe
    {
        const WAIT    = 1; // 等待/不等待 数据读够为止
        const NO_WAIT = 2;

        const READ    = 1; // 管道读/写地址
        const WRITE   = 2;

        const MAX_SEND_DATA = 4096;

        // 创建管道是否成功标记
        public $pipeFlag   = false;
        private $_pipePath = ""; // 管道地址
        private $_w_pipe   = NULL; // write's pipe resource
        private $_r_pipe   = NULL; // read's pipe resource

        public function __construct($name = "", $mode = 0666)
        {
            $pipePath = (!empty($name)) ? "/tmp/pipe.".$name : "/tmp/pipe";
            if (!file_exists($pipePath)) {
                if (!posix_mkfifo($pipePath, $mode)) {
                    return;
                }
            } 

            $this->pipeFlag  = true;
            $this->_pipePath = $pipePath;
        }

        public function openPipe($status = self::READ)
        {
            if ($status === self::READ && !is_resource($this->_r_pipe)) {
                // 一定要是r+, 否则如果没有写管道，会阻塞卡死
                $r_pipe = fopen($this->_pipePath, "r+");
                if ($r_pipe === false) {
                    Util::log("open read pipe error.");
                    return false;
                }
                $this->_r_pipe = $r_pipe;
                // 读（非阻塞）, 因为如果阻塞的模式，一定会等待读取的长度够了fread才会返回(其他的fread并不会)
                stream_set_blocking($this->_r_pipe, 0);
            } else if ($status === self::WRITE && !is_resource($this->_w_pipe)) {
                // 最好使用追加，防止用w+写入，会截断管道的数据（待测试）
                $w_pipe = fopen($this->_pipePath, 'a+');
                if ($w_pipe === false) {
                    Util::log("open write pipe error.");
                    return false;
                }   
                $this->_w_pipe = $w_pipe;
            } else {
                Util::log("open pipe error.");
                return false;
            }

            return true;
        }

        public function send($sendData)
        {
            if (!is_resource($this->_w_pipe)) {
                if ($this->openPipe(self::WRITE) === false) {
                    return false;
                }
            }

            $sendLength = strlen($sendData);
            if ($sendLength > self::MAX_SEND_DATA) {
                Util::log("send data to pipe too large.");
                return false;
            }

            // 并没有判断管道是否feof, 方式有一个读，多个写，其中一个管道写完了会自动发送EOF信号
            while ($sendLength > 0 && is_resource($this->_w_pipe) && ($sendLength = @fwrite($this->_w_pipe, $sendData)) > 0) {
                if ($sendLength === strlen($sendData)) {
                    break;
                }
                $sendData = substr($sendData, $sendLength);
            }

            if ($sendLength < 0 || $sendLength == false) {
                $this->closePipe(self::WRITE);
                return false;
            }

            return true;
        }

        /**
         * revice 从管道中获取数据，WAIT/NO_WAIT
         */
        public function revice($bytes = 8196, $wait = self::NO_WAIT)
        {
            if ($bytes <= 0) {
                return false;
            }

            if (!is_resource($this->_r_pipe)) {
                if ($this->openPipe(self::READ) === false) {
                    return false;
                }
            }

            $data = "";
            while (is_resource($this->_r_pipe) && ($readData = fread($this->_r_pipe, $bytes)) > 0) {
                $data .= $readData;
                if (strlen($readData) === $bytes) {
                    break;
                }

                $bytes = $bytes - strlen($data);
                if ($wait === self::NO_WAIT) {
                    break;
                }
            }

            return $data;
        }

        /**
         * 关闭管道，
         */
        public function closePipe($status = self::READ)
        {
            if (self::WRITE === $status) {
                return @fclose($this->_w_pipe);
            } else {
                return @fclose($this->_r_pipe);
            }
        }

        /**
         * 销毁管道
         */
        public function destroy()
        {
            if (is_resource($this->_w_pipe)) {
                $this->closePipe(self::WRITE);
            } 

            if (is_resource($this->_r_pipe)) {
                $this->closePipe(self::READ);
            }

            return @unlink($this->_pipePath);
        }

        public function getPipeResource($type = self::READ)
        {
            if ($type === self::READ) {
                return $this->_r_pipe;
            } else {
                return $this->_w_pipe;
            }
        }

        public function __destruct()
        {
            //$this->destroy();
        }
    }
}

