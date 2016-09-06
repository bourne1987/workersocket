<?php
/**
 *  This is part of process for protocol
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

namespace Worker\Protocols 
{
    interface ProtocolInterface 
    {
        // 包错误
        const PACKAGE_ERROR = -1;
        // 不是一个完整的包
        const PACKAGE_NOT_COMPLETE = -2;

        /**
         * input get revBuffer's length
         * 
         * @param mixed $revBuffer
         * @static 
         * @return void
         */
        public static function input($revBuffer);

        /**
         * decode resolve data 
         * 
         * @param mixed $revBuffer
         * @param $connection
         * @static 
         * @return void
         */
        public static function decode($revBuffer, $connection);

        /**
         * encode pack data to send
         * 
         * @param mixed $content
         * @param $connection
         * @static 
         * @return void
         */
        public static function encode($content, $connection);
    } 
}
