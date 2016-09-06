<?php
/**
 * this is part of process
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
    use Worker\Net\SocketTcp;

    class Http implements ProtocolInterface
    {
        /**
         * http1.0报文格式"header/r/n/r/ncontent/r/n/r/n"
         * 获取接收数据的真实需要接收多长
         * $recvBuffer 有可能是一个包的长度；也有可能是多个包粘连在一起了
         */
        public static function input($recvBuffer)
        {
            // 如果获取的数据没有/r/n/r/n , 证明收到的数据不是一个完整的包/*{{{*/
            if (!strpos($recvBuffer, "\r\n\r\n")) { 
                if (strlen($recvBuffer) >= SocketTcp::MAX_PACKAGE_SIZE) {
                    return ProtocolInterface::PACKAGE_ERROR;
                }

                // recvBuffer is part of package;
                return ProtocolInterface::PACKAGE_NOT_COMPLETE;
            } 

            // 到这里, 这个包一定是有header是全的
            list($header, ) = explode("\r\n\r\n", $recvBuffer, 2);
            if (0 === strpos($recvBuffer, "POST")) {
                // find Content-Length, Method is "POST" , header have Content-Length's param
                $match = array();
                if (preg_match("/\r\nContent-Length: ?(\d+)/i", $header, $match)) {
                    $contentLength = $match[1];
                    return $contentLength + strlen($header) + 4;
                } else {
                    // POST method commit can't find content-length param; so this is error package. 
                    return ProtocolInterface::PACKAGE_ERROR;
                }
            } elseif (0 === strpos($recvBuffer, "GET")) {
                // if method === GET , content's length is strlen(header) + 4;
                return strlen($header) + 4;
            } else {
                return ProtocolInterface::PACKAGE_ERROR;
            }        /*}}}*/
        }

        /**
         * 将接收的数据，格式化, 分开包头，包体和其他参数
         */
        public static function decode($recv_buffer, $connection)
        {
            /*{{{*/
            $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = $_FILES = array();
            $GLOBALS['HTTP_RAW_POST_DATA'] = '';
            // Clear cache.
            HttpCache::$header   = array('Connection' => 'Connection: keep-alive');
            HttpCache::$instance = new HttpCache();
            // $_SERVER
            $_SERVER = array(
                'QUERY_STRING'         => '',
                'REQUEST_METHOD'       => '',
                'REQUEST_URI'          => '',
                'SERVER_PROTOCOL'      => '',
                'SERVER_SOFTWARE'      => 'PROCESSWORKER/1.0.0',
                'SERVER_NAME'          => '',
                'HTTP_HOST'            => '',
                'HTTP_USER_AGENT'      => '',
                'HTTP_ACCEPT'          => '',
                'HTTP_ACCEPT_LANGUAGE' => '',
                'HTTP_ACCEPT_ENCODING' => '',
                'HTTP_COOKIE'          => '',
                'HTTP_CONNECTION'      => '',
                'REMOTE_ADDR'          => '',
                'REMOTE_PORT'          => '0',
            );

            list($http_header, $http_body) = explode("\r\n\r\n", $recv_buffer, 2);
            $header_data = explode("\r\n", $http_header);
            list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $header_data[0]);

            $http_post_boundary = '';
            unset($header_data[0]);
            foreach ($header_data as $content) {
                // \r\n\r\n
                if (empty($content)) {
                    continue;
                }
                list($key, $value) = explode(':', $content, 2);
                $key   = str_replace('-', '_', strtoupper($key));
                $value = trim($value);
                $_SERVER['HTTP_' . $key] = $value;
                switch ($key) {
                    // HTTP_HOST
                case 'HOST':
                    $tmp = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                    // cookie
                case 'COOKIE':
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                    // content-type
                case 'CONTENT_TYPE':
                    if (!preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                        $_SERVER['CONTENT_TYPE'] = $value;
                    } else {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $http_post_boundary = '--' . $match[1];
                    }
                case 'CONTENT_LENGTH':
                    $_SERVER['CONTENT_LENGTH'] = $value;
                    break;
                }
            }

            // Parse $_POST.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'multipart/form-data') {
                    self::_parseUploadFiles($http_body, $http_post_boundary);
                } else {
                    parse_str($http_body, $_POST);
                    // $GLOBALS['HTTP_RAW_POST_DATA']
                    $GLOBALS['HTTP_RAW_POST_DATA'] = $http_body;
                }
            }

            // QUERY_STRING
            $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
            if ($_SERVER['QUERY_STRING']) {
                // $GET
                parse_str($_SERVER['QUERY_STRING'], $_GET);
            } else {
                $_SERVER['QUERY_STRING'] = '';
            }

            // REQUEST
            $_REQUEST = array_merge($_GET, $_POST);

            // REMOTE_ADDR REMOTE_PORT
            $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
            $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();

            return array('get' => $_GET, 'post' => $_POST, 'cookie' => $_COOKIE, 'server' => $_SERVER, 'files' => $_FILES);
            /*}}}*/
        }

        /**
         * 将内容封装成完整的HTTP包头+包体 返回给浏览器
         * HTTP/1.1 200 OK\r\n
         * Content-Type: text/html;charset=utf-8\r\n
         * PHPSESSID=d6misev74bt3cja9r07g2qcd00;\r\npath=/\r\n
         * Server: WORKRE/1.0\r\nContent-Length: 128\r\n\r\n
         * 内容....\r\n\r\n
         */
        public static function encode($content, $connection)
        {
            /*{{{*/
            if (!isset(HttpCache::$header['Http-Code'])) {
                $header = "HTTP/1.1 200 OK\r\n";
            } else {
                $header = HttpCache::$header['Http-Code'] . "\r\n";
                unset(HttpCache::$header['Http-Code']);
            }

            if (!isset(HttpCache::$header['Content-Type'])) {
                $header .= "Content-Type: text/html;charset=utf-8\r\n";
            }

            // other headers
            foreach (HttpCache::$header as $key => $item) {
                if ('Set-Cookie' === $key && is_array($item)) {
                    foreach ($item as $it) {
                        $header .= $it . "\r\n";
                    }
                } else {
                    $header .= $item . "\r\n";
                }
            }

            $header .= "Server: PROCESSWORKER/1.0\r\nContent-Length: " . strlen($content) . "\r\n\r\n";
            self::sessionWriteClose();
            return $header.$content;
            /*}}}*/
        }

        /**
         * Save session.
         *
         * @return bool
         */
        public static function sessionWriteClose()
        {
            if (PHP_SAPI != 'cli') {
                return session_write_close();
            }
            if (!empty(HttpCache::$instance->sessionStarted) && !empty($_SESSION)) {
                $session_str = session_encode();
                if ($session_str && HttpCache::$instance->sessionFile) {
                    return file_put_contents(HttpCache::$instance->sessionFile, $session_str);
                }
            }
            return empty($_SESSION);
        }

        /**
         * 如果要使用session，必须调用协议的此函数
         * sessionStart
         *
         * @return bool
         */
        public static function sessionStart()
        {
            if (PHP_SAPI != 'cli') {
                return session_start();
            }
            if (HttpCache::$instance->sessionStarted) {
                echo "already sessionStarted\nn";
                return true;
            }
            HttpCache::$instance->sessionStarted = true;
            // Generate a SID.
            if (!isset($_COOKIE[HttpCache::$sessionName]) || !is_file(HttpCache::$sessionPath . '/ses' . $_COOKIE[HttpCache::$sessionName])) {
                $file_name = tempnam(HttpCache::$sessionPath, 'ses');
                if (!$file_name) {
                    return false;
                }
                HttpCache::$instance->sessionFile = $file_name;
                $session_id                       = substr(basename($file_name), strlen('ses'));
                return self::setcookie(
                    HttpCache::$sessionName
                    , $session_id
                    , ini_get('session.cookie_lifetime')
                    , ini_get('session.cookie_path')
                    , ini_get('session.cookie_domain')
                    , ini_get('session.cookie_secure')
                    , ini_get('session.cookie_httponly')
                );
            }
            if (!HttpCache::$instance->sessionFile) {
                HttpCache::$instance->sessionFile = HttpCache::$sessionPath . '/ses' . $_COOKIE[HttpCache::$sessionName];
            }
            // Read session from session file.
            if (HttpCache::$instance->sessionFile) {
                $raw = file_get_contents(HttpCache::$instance->sessionFile);
                if ($raw) {
                    session_decode($raw);
                }
            }
            return true;
        }

        /**
         * Set cookie.
         *
         * @param string  $name
         * @param string  $value
         * @param integer $maxage
         * @param string  $path
         * @param string  $domain
         * @param bool    $secure
         * @param bool    $HTTPOnly
         * @return bool|void
         */
        private static function setcookie( $name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) 
        {
            if (PHP_SAPI != 'cli') {
                return setcookie($name, $value, $maxage, $path, $domain, $secure, $HTTPOnly);
            }
            return self::header(
                'Set-Cookie: ' . $name . '=' . rawurlencode($value)
                . (empty($domain) ? '' : '; Domain=' . $domain)
                . (empty($maxage) ? '' : '; Max-Age=' . $maxage)
                . (empty($path) ? '' : '; Path=' . $path)
                . (!$secure ? '' : '; Secure')
                . (!$HTTPOnly ? '' : '; HttpOnly'), false);
        }

        /**
         * 设置http头
         *
         * @return bool|void
         */
        private static function header($content, $replace = true, $http_response_code = 0)
        {
            if (PHP_SAPI != 'cli') {
                return $http_response_code ? header($content, $replace, $http_response_code) : header($content, $replace);
            }
            if (strpos($content, 'HTTP') === 0) {
                $key = 'Http-Code';
            } else {
                $key = strstr($content, ":", true);
                if (empty($key)) {
                    return false;
                }
            }

            if ('location' === strtolower($key) && !$http_response_code) {
                return self::header($content, true, 302);
            }

            if (isset(HttpCache::$codes[$http_response_code])) {
                HttpCache::$header['Http-Code'] = "HTTP/1.1 $http_response_code " . HttpCache::$codes[$http_response_code];
                if ($key === 'Http-Code') {
                    return true;
                }
            }

            if ($key === 'Set-Cookie') {
                HttpCache::$header[$key][] = $content;
            } else {
                HttpCache::$header[$key] = $content;
            }

            return true;
        }

        private static function _parseUploadFiles($http_body, $http_post_boundary)
        {
            $http_body = substr($http_body, 0, strlen($http_body) - (strlen($http_post_boundary) + 4));/*{{{*/
            $boundary_data_array = explode($http_post_boundary . "\r\n", $http_body);
            if ($boundary_data_array[0] === '') {
                unset($boundary_data_array[0]);
            }
            foreach ($boundary_data_array as $boundary_data_buffer) {
                list($boundary_header_buffer, $boundary_value) = explode("\r\n\r\n", $boundary_data_buffer, 2);
                // Remove \r\n from the end of buffer.
                $boundary_value = substr($boundary_value, 0, -2);
                foreach (explode("\r\n", $boundary_header_buffer) as $item) {
                    list($header_key, $header_value) = explode(": ", $item);
                    $header_key = strtolower($header_key);
                    switch ($header_key) {
                    case "content-disposition":
                        // Is file data.
                        if (preg_match('/name=".*?"; filename="(.*?)"$/', $header_value, $match)) {
                            // Parse $_FILES.
                            $_FILES[] = array(
                                'file_name' => $match[1],
                                'file_data' => $boundary_value,
                                'file_size' => strlen($boundary_value),
                            );
                            continue;
                        } // Is post field.
                        else {
                            // Parse $_POST.
                            if (preg_match('/name="(.*?)"$/', $header_value, $match)) {
                                $_POST[$match[1]] = $boundary_value;
                            }
                        }
                        break;
                    }
                }
            }/*}}}*/
        }

        public static function getMimeTypesFile()
        {
            return __DIR__ . '/Http/mime.types';
        }
    }

    class HttpCache
    {
        /*{{{*/
        public static $codes = array(
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => '(Unused)',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
        );

        /**
         * @var HttpCache
         */
        public static $instance = null;

        public static $header = array();
        public static $sessionPath = '';
        public static $sessionName = '';
        public $sessionStarted = false;
        public $sessionFile = '';

        public static function init()
        {
            self::$sessionName = ini_get('session.name');
            self::$sessionPath = session_save_path();
            if (!self::$sessionPath) {
                self::$sessionPath = sys_get_temp_dir();
            }
            @\session_start();
        }
        /*}}}*/
    }
}
