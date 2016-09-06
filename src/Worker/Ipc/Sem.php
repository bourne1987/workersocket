<?php
/**
 * 信号量
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
namespace Worker\Icp 
{
    class Sem
    {
        const SEM_PATH = "/tmp/sem";

        private static $_sem    = NULL;
        private static $_semKey = "";

        public static function init($pathName = "", $proj = "")
        {
            $pathName = empty($pathName) ? self::SEM_PATH : $pathName;
            if (!file_exists($pathName)) {
                if (@touch($pathName) === false) {
                    return false;
                }
            }

            $proj = empty($proj) ? 'm' : $proj;
            self::$_semKey = ftok($pathName, $proj);
            if (self::$_semKey === -1) {
                return false;
            }

            self::$_sem = sem_get(self::$_semKey, 1, 0666, 1);
            if (!is_resource(self::$_sem)) {
                return false;
            }
            return true;
        }

        /**
         * nowait = false 非阻塞， true 阻塞
         * 表示没信号来阻塞不阻塞的问题
         * 拿到信号返回true， 没有返回false
         */
        public static function semAcquire($nowait = true)
        {
            return sem_acquire(self::$_sem, $nowait);
        }

        /**
         * 释放信号(释放锁)
         */
        public static function semRelease()
        {
            return sem_release(self::$_sem);
        }

        /**
         * 删除信号锁
         */
        public static function semRemove()
        {
            return sem_remove(self::$_sem);
        }

        public static function getSem()
        {
            return self::$_sem;
        }
    }

}
