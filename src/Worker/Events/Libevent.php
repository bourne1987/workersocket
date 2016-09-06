<?php
/**
 * libevent
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
    use Worker\Lib\Util;

    class Libevent implements EventInterface
    {
        private $_eventBase   = NULL;
        private $_eventSignal = array();
        private $_eventTimer  = array();
        private $_allEvents   = array();

        function __construct()
        {
            $this->_eventBase = event_base_new();
        }

        /**
         * $flag EV_READ/EV_WRITE/EV_TIMER/EV_SIGNAL
         * $fd 文件FD
         * $callbackFunc 回调函数
         * $args 回调函数的参数
         */
        public function add($flag, $fd, $callbackFunc, $args = array())
        {
            switch ($flag) {
                case EventInterface::EV_SIGNAL:
                    $k = (int)$fd; // fd = signo;
                    $event = event_new();
                    if (!event_set($event, $fd, EV_SIGNAL|EV_PERSIST, array($this, 'singalCallback'), $k)) {
                        return false; 
                    }
                    
                    if (!event_base_set($event, $this->_eventBase)) {
                        return false;
                    }

                    if (!event_add($event)) {
                        return false;
                    }

                    $this->_eventSignal[$k] = array($callbackFunc, $event, $fd);
                    return true;
                    break;
                case EventInterface::EV_TIMER:
                case EventInterface::EV_TIMER_ONCE:
                    // fd = 定时时间
                    $event = event_new();
                    $timerId = (int)$event;
                    if (!event_set($event, 0, EV_TIMEOUT, array($this, 'timerCallback'), $timerId)) {
                        return false;
                    }

                    if (!event_base_set($event, $this->_eventBase)) {
                        return false;
                    }

                    $timeout = $fd * 1000000;
                    if (!event_add($event, $timeout)) {
                        return false;
                    }

                    $this->_eventTimer[$timerId] = array($callbackFunc, $event, $flag, $timeout);
                    return $timerId;
                    break;
                default:
                    $k = md5((int)$fd.'-'.$flag);
                    $realFlag = ($flag === EventInterface::EV_READ) ? EV_READ|EV_PERSIST : EV_WRITE|EV_PERSIST;
                    $event = event_new();

                    if (!event_set($event, $fd, $realFlag, array($this, 'fdCallback'), $k)) {
                        return false;
                    }

                    if (!event_base_set($event, $this->_eventBase)) {
                        return false;
                    }

                    if (!event_add($event)) {
                        return false;
                    }

                    $this->_allEvents[$k] = array($callbackFunc, $event, $args);
                    break;
            }
        }

        public function fdCallback($null1, $null2, $k)
        {
            $callbackFunc = $this->_allEvents[$k][0];
            $event = $this->_allEvents[$k][1];
            $args = $this->_allEvents[$k][2];
            if (is_callable($callbackFunc)) {
                try {
                    call_user_func_array($callbackFunc, $args);
                } catch (\Exception $e) {
                    Util::log($e->getMessage());
                    exit(250);
                }
            }
        }

        /**
         * 信号回调函数
         * $callbackFunc, $event, $fd
         */
        public function singalCallback($null1, $null2, $k)
        {
            $callbackFunc = $this->_eventSignal[$k][0];
            $signo = $this->_eventSignal[$k][2];

            if (is_callable($callbackFunc)) {
                try {
                    call_user_func($callbackFunc, $signo);
                } catch (\Exception $e) {
                    Util::log($e->getMessage());
                    exit(250);
                }
            }
        }

        /**
         * 定时器回调
         */
        public function timerCallback($null1, $null2, $timerId)
        {
            $callbackFunc = $this->_eventTimer[$timerId][0];
            $event        = $this->_eventTimer[$timerId][1];
            $flag         = $this->_eventTimer[$timerId][2];
            $timeout      = $this->_eventTimer[$timerId][3];

            if (EventInterface::EV_TIMER === $flag) {
                event_add($event, $timeout);
            }

            if (is_callable($callbackFunc)) {
                try {
                    call_user_func($callbackFunc, $timerId);
                } catch (\Exception $e) {
                    Util::log($e->getMessage());
                    exit(250);
                }
            }
        }

        /**
         * $flag EV_READ/EV_WRITE/EV_TIMER/EV_SIGNAL
         * $fd 文件FD
         */
        public function del($flag, $fd)
        {
            switch ($flag) {
            case EventInterface::EV_SIGNAL:
                $k = (int)$fd; // fd = singo
                if (isset($this->_eventSignal[$k])) {
                    event_del($this->_eventSignal[$k][1]);
                    unset($this->_eventSignal[$k]);
                }
                break;
            case EventInterface::EV_TIMER:
            case EventInterface::EV_TIMER_ONCE:
                // fd = timerId
                if (isset($this->_eventTimer[$fd])) {
                    event_del($this->_eventTimer[$fd][1]);
                    unset($this->_eventTimer[$fd]);
                }
                break;
            default:
                $k = md5((int)$fd ."-".$flag);
                if (isset($this->_allEvents[$k])) {
                    event_del($this->_allEvents[$k][1]);
                    unset($this->_allEvents[$k]);
                }
                break;
            }
            return true;
        }

        // EV_LOOP_BLOCK/EV_LOOP_NONBLOCK/EV_LOOP_NONBLOCK
        public function loop($flag = EventInterface::EV_LOOP_BLOCK)
        {
            event_base_loop($this->_eventBase, $flag);
        }

        public function clearAllTimer()
        {
            foreach ($this->_eventTimer as $k => $timers) {
                event_del($timers[1]);
                unset($this->_eventTimer[$k]);
            }
        }

        public function clearAllEvents()
        {
            foreach ($this->_allEvents as $k => $event) {
                event_del($event[1]);
                unset($this->_allEvents[$k]);
            }
        }

        public function clearAllSignal()
        {
            foreach ($this->_eventSignal as $k => $signal) {
                event_del($signal[1]);
                unset($this->_eventSignal[$k]);
            }
        }
    }
}


