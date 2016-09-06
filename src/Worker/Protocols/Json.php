<?php
/**
 * this is part of process for json protocol 
 *
 * pack('H', length){""}
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
    class Json implements ProtocolInterface
    {
        public static function input($recvBuffer)
        {
            if (strlen($recvBuffer) < 4) {
                return ProtocolInterface::PACKAGE_NOT_COMPLETE;
            }

            $unpack_data = unpack('NdataLength', $recvBuffer);
            return (!is_int($unpack_data['dataLength']) || $unpack_data['dataLength'] > 10485760) 
                ? ProtocolInterface::PACKAGE_ERROR : $unpack_data['dataLength'];
        }

        /**
         * 解析一个完整的包的数据
         */
        public static function decode($recvBuffer, $connection)
        {
            $content = substr($recvBuffer, 4);
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            } else {
                return false;
            }
        }

        public static function encode($content, $connection)
        {
            $content = json_encode($content);
            $dataLength = 4 + strlen($content);
            return pack('N', $dataLength).$content;
        }
    }
}
?>
