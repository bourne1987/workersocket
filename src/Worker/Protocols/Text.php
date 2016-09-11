<?php
/**
 * TEXT 协议
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
    class Text implements ProtocolInterface
    {
        public static function input($buffer)
        {
            $pos = strpos($buffer, "\n");
            if ($pos === false) {
                return ProtocolInterface::PACKAGE_NOT_COMPLETE;    
            }

            return $pos+1;
        }

        /**
         * 解析一个完整的包的数据
         */
        public static function decode($buffer, $connection)
        {
            return trim($buffer);
        }

        public static function encode($buffer, $connection)
        {
            return $buffer . "\n";
        }
    }
}
