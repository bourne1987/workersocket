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

namespace Worker\Icp 
{
    class Shm
    {
        const SHM_PATH = "/tmp/shm";
        private static $_shm    = NULL;
        private static $_shmKey = "";

        public static function init($pathName = "", $proj = "")
        {
            $pathName = empty($pathName) ? self::SHM_PATH : $pathName;
            if (!file_exists($pathName)) {
                if (@touch($pathName) === false) {
                    return false;
                }
            }

            $proj = empty($proj) ? 's' : $proj;
            self::$_shmKey = ftok($pathName, $proj);
            if (self::$_shmKey === -1) {
                return false;
            }

            self::$_shm = shm_attach(self::$_shmKey, 1024000, 0666);
            if (!is_resource(self::$_shm)) {
                return false;
            }

            return true;
        }

        public static function get($key)
        {
            $variable = shm_get_var(self::$_shm, $key);
            return $variable;
        }

        public static function set($key, $value)
        {
            return shm_put_var(self::$_shm, $key, $value);
        }

        /**
         * 判断是否存在
         */
        public static function exist($key)
        {
            return shm_has_var(self::$_shm, $key);
        }

        public static function del($key)
        {
            return shm_remove_var(self::$_shm, $key);
        }

        public static function destroy()
        {
            if (is_resource(self::$_shm)) {
                return shm_remove(self::$_shm);
            } 

            return true;
        }
    }
}

