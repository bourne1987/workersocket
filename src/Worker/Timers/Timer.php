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

namespace Worker\Timers
{
    use Worker\Events\EventInterface;

    class Timer
    {
        private static $_event = NULL;

        public static function init($event)
        {
            self::$_event = $event;
        }

        /**
         * $timeInterval 间隔时长
         * $func 回调函数
         * $persistent 是否一直执行
         */
        public static function add($timeInterval, $func, $persistent = true)
        {
            if ($timeInterval <= 0) {
                return false;
            }

            if (self::$_event) {
                return self::$_event->add($persistent ? EventInterface::EV_TIMER : EventInterface::EV_TIMER_ONCE, $timeInterval, $func);
            }

            return false;
        }

        public static function del($timerId)
        {
            if (self::$_event) {
                return self::$_event->del(EventInterface::EV_TIMER, $timerId);
            }

            return false;
        }

        public static function delAll()
        {
            if (self::$_event) {
                self::$_event->clearAllTimer();
            }
        }
    }
}
