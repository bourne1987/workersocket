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
namespace Worker\Events
{
    interface EventInterface
    {
        /**
         *  Read event
         */
        const EV_READ       = 1;

        /**
         *  Write event
         */
        const EV_WRITE      = 2;

        /**
         *  Signal event
         */
        const EV_SIGNAL     = 4;

        /**
         *  Timer event
         */
        const EV_TIMER      = 8;

        /**
         *  Timer once event
         */
        const EV_TIMER_ONCE = 16;

        const EV_LOOP_BLOCK    = 0;  // 阻塞，并循环
        const EV_LOOP_ONCE     = 1;  // 阻塞，只执行一次
        const EV_LOOP_NONBLOCK = 2;  // 不阻塞

        /**
         * $flag EV_READ/EV_WRITE/EV_TIMER/EV_SIGNAL
         * $fd 文件FD
         * $callbackFunc 回调函数
         * $args 回调函数的参数
         */
        public function add($flag, $fd, $callbackFunc, $args);

        /**
         * $flag EV_READ/EV_WRITE/EV_TIMER/EV_SIGNAL
         * $fd 文件FD
         */
        public function del($flag, $fd);

        // EV_LOOP_BLOCK/EV_LOOP_NONBLOCK/EV_LOOP_NONBLOCK
        public function loop($flag);

        public function clearAllTimer();
    }
}

