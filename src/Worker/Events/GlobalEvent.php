<?php
/**
 * 保证事件对象一个进程只存在一个
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
    class GlobalEvent
    {
        private static $_event = NULL;

        public static function init()
        {
            if (empty(self::$_event)) {
                self::$_event = new Libevent();
            }
        }

        public static function getEvent()
        {
            if (empty(self::$_event)) {
                self::init();
            }

            return self::$_event;
        }

        public static function clearEvents()
        {
            if (self::$_event) {
                self::$_event->clearAllTimer();
                self::$_event->clearAllSignal();
                self::$_event->clearAllEvents();
                self::$_event = null;
            }
        }
    }
}

?>
