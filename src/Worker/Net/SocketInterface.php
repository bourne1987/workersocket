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
namespace Worker\Net
{
    abstract class SocketInterface 
    {
        public static $statistics = array(
            'connection_count' => 0, // 当前存在的链接总数
            'total_request'    => 0, // 总请求数
            'throw_exception'  => 0, // 异常数
            'send_fail'        => 0, // 发送数据错误数
        );

        public $eventTimers  = array();
        public $eventSignals = array();
        public $eventReads   = array();
        public $eventWrites  = array();
    }
}
