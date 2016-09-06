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

namespace Worker\Lib
{
    class Util
    {
        private static $logLevel = array('DEBUG', 'INFO', 'WARN', 'ERROR');
        /**
         * 获取当前毫秒值
         */
        public static function currentTime()
        {
            list($tmp1, $tmp2) = explode(' ', microtime());
            return (int)sprintf('%.0f', (floatval($tmp1) + floatval($tmp2)) * 1000);
        }

        /**
         * 日志写入固定文件
         * $message 日志信息
         * $logName 日志文件名称
         * $ifOutput 是否打印到控制终端
         * $levelNo 日志级别 0 - 3
         */
        public static function log($message, $logName = "error", $ifOutput= false, $levelNo = 1)
        {
            //2013-12-10 12:11:00 INFO[1923] : ssss
            $message = date('Y-m-d H:i:s')." ".self::$logLevel[$levelNo]."[".posix_getpid()."] : " . $message."\n";
            if ($ifOutput === true) {
                echo "$message";
            }
            file_put_contents(WORKER_LOG."/{$logName}.log", $message, FILE_APPEND|LOCK_EX);
        }
    }
}


