<?php

namespace fuyelk\wechat;

use Exception;
use fuyelk\db\Db;
use fuyelk\db\DbException;

/**
 * 微信公众平台基类
 * Class Wechat
 * @package fuyelk\wechat
 * @author fuyelk <fuyelk@fuyelk.com>
 */
class Wechat
{
    public $appid = '';
    public $appsecret = '';
    private $access_token = '';
    private $expires_time = 0;

    /**
     * WeChat 构造函数
     * @param array $dbConfig 数据库配置 ['type','host','database','aausernamea','password','port','prefix']
     * @param bool $refreshToken 为真，则刷新token
     * @throws WechatException
     */
    public function __construct(array $dbConfig, bool $refreshToken = false)
    {
        // 初始化数据库配置
        Db::setConfig($dbConfig);

        try {
            $config = $this->getConfig();
        } catch (WechatException $e) {
            throw new WechatException($e->getMessage());
        }
        if (empty($config['appid']) || empty($config['appsecret'])) {
            throw new WechatException('微信配置为空');
        }

        $this->appid = $config['appid'];
        $this->appsecret = $config['appsecret'];

        // 检查access_token是否有效
        if ($refreshToken || empty($config['access_token']) || $config['access_token_expire_time'] < strtotime('+5 minute')) {
            try {
                $this->refreshAccessToken();
            } catch (WechatException $e) {
                throw new WechatException($e->getMessage());
            }
        } else {
            $this->access_token = $config['access_token'];
            $this->expires_time = $config['access_token_expire_time'];
        }
    }

    /**
     * 获取微信配置
     * @return array|bool|false|null
     * @throws WechatException
     * @author fuyelk <fuyelk@fuyelk.com>
     */
    private function getConfig()
    {
        // 检查有没有wechat表
        try {
            $config = Db::name('wechat')->column('value', 'name');
        } catch (DbException $e) {
            // 表不存在
            if ('1146' == $e->getSqlErrorCode()) {
                // 创建表
                try {
                    $prefix = Db::getConfig('prefix');
                    $sqlCreateTable = "CREATE TABLE `{$prefix}wechat` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',`name` varchar(100) CHARACTER SET utf8mb4 NOT NULL COMMENT '数据名',`value` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '值',`description` text CHARACTER SET utf8mb4 COMMENT '描述',`updatetime` int(10) DEFAULT NULL COMMENT '更新时间',PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='微信配置表';";
                    $sqlInsertData = "insert into `{$prefix}wechat` (`id`, `name`, `value`, `description`, `updatetime`) values ('1','appid','','APPID',NULL),('2','appsecret','','APP SECRET',NULL),('3','access_token','','SCCESS TOKEN',NULL),('4','access_token_expire_time','','SCCESS TOKEN过期时间',NULL),('5','qrcode','','二维码',NULL);";
                    Db::query($sqlCreateTable);
                    Db::query($sqlInsertData);
                    return Db::name('wechat')->column('value', 'name');
                } catch (DbException $e) {
                    throw new WechatException($e->getMessage());
                }
            }

            throw new WechatException($e->getMessage());
        }

        return $config;
    }

    /**
     * 向微信服务器请求获取accessToken,并存储
     * @return bool
     * @throws WechatException
     * @author fuyelk <fuyelk@fuyelk.com>
     */
    private function refreshAccessToken()
    {
        // 向微信服务器发access_token请求
        $data = $this->wechatRequest('https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . $this->appid . '&secret=' . $this->appsecret);
        if (empty($data['access_token'])) {
            throw new WechatException('access_token获取失败');
        }

        $this->access_token = $data['access_token'];
        $this->expires_time = time() + $data['expires_in'];

        try {
            Db::name('wechat')->where('name', 'access_token')->update(['value' => $this->access_token, 'updatetime' => time()]);
            Db::name('wechat')->where('name', 'access_token_expire_time')->update(['value' => $this->expires_time, 'updatetime' => time()]);
        } catch (DbException $e) {
            throw new WechatException($e->getMessage());
        }

        return true;
    }

    /**
     * 网络请求
     * @param string $url http地址
     * @param string $method 请求方式
     * @param array $data 请求数据：
     * <pre>
     *  $data = [
     *      'image' => new \CURLFile($filePath),
     *      'access_token' => 'this-is-access-token'
     *       ...
     *  ]
     * </pre>
     * @return bool|string
     * @throws WechatException
     * @author fuyelk <fuyelk@fuyelk.com>
     */
    public function httpRequest($url, $method = 'GET', $data = [])
    {
        $addHeader = [];
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_ACCEPT_ENCODING => 'gzip,deflate',
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method), // 请求方式
            CURLOPT_USERAGENT => "Mozilla / 5.0 (Windows NT 10.0; Win64; x64)",// 模拟常用浏览器的useragent
            CURLOPT_RETURNTRANSFER => true, // 获取的信息以文件流的形式返回，而不是直接输出
            CURLOPT_SSL_VERIFYPEER => false, // https请求不验证证书
            CURLOPT_SSL_VERIFYHOST => false, // https请求不验证hosts
            CURLOPT_MAXREDIRS => 10, // 最深允许重定向级数
            CURLOPT_CONNECTTIMEOUT => 10,// 最长等待连接成功时间
            CURLOPT_TIMEOUT => 30, // 最长等待响应完成时间
        ]);

        // 发送请求数据
        if ($data) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            array_push($addHeader, 'Content-type:application/json');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $addHeader);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) throw new WechatException($err);
        return $response;
    }

    /**
     * 请求微信接口,并检查请求结果
     * @param string $url 接口地址
     * @param null $data [请求数据]
     * @param bool $callback [是否是回调]
     * @return bool|mixed|string
     * @throws WechatException
     * @author fuyelk <fuyelk@fuyelk.com>
     */
    public function wechatRequest($url, $data = null, $callback = false)
    {
        $baseUrl = $url;
        $url = $url . (strpos($url, '?') ? '&' : '?') . 'access_token=' . $this->access_token;
        try {
            if (!empty($data)) {
                $res = $this->httpRequest($url, 'POST', $data);
            } else {
                $res = $this->httpRequest($url);
            }
        } catch (WechatException $e) {
            throw new WechatException($e->getMessage());
        }

        $res = json_decode($res, true);

        // 接口出错
        if (!empty($res['errcode'])) {
            $log = [
                'url' => $url,
                'data' => $data,
                'response' => $res
            ];

            // 非回调，则检查是否AccessToken错误
            if (!$callback && '40001' == $res['errcode'] && !empty($res['errmsg']) && strpos($res['errmsg'], 'access_token is invalid')) {
                // 刷新AccessToken
                if (!$this->refreshAccessToken()) {
                    return false;
                }

                $this->writeLog($log, '微信接口请求出错，AccessToken失效所致，正在尝试修复');

                // 重新请求微信接口
                return $this->wechatRequest($baseUrl, $data, true);
            }

            $this->writeLog($log, '微信接口请求出错');

            if (!empty($res['errmsg'])) {
                throw new WechatException($res['errmsg']);
            }
            return false;
        }
        return $res;
    }

    /**
     * 写日志
     * @param mixed $log 日志内容
     * @param string $name 数据说明
     * @return string 日志唯一标识
     */
    private function writeLog($log, string $name = '')
    {
        $debug = debug_backtrace();
        $log = is_object($log) ? (array)$log : $log;
        $log_id = uniqid();
        $logFile = dirname(__DIR__) . '/log/' . date('Ym') . '/' . date('d') . '.log';
        if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);

        $msg = "============ [$log_id] 日志开始 ============" . PHP_EOL;;
        $msg .= '[ file ] ' . $debug[0]['file'] . ':' . $debug[0]['line'] . PHP_EOL;
        $msg .= '[ time ] ' . date('Y-m-d H:i:s') . PHP_EOL;
        $msg .= '[ name ] ' . $name . PHP_EOL;
        $msg .= '[ data ] ' . (is_array($log) ? var_export($log, true) : $log) . PHP_EOL;
        $fp = @fopen($logFile, 'a');
        fwrite($fp, $msg);
        fclose($fp);
        return date('Ymd') . $log_id;
    }

    /**
     * 获取日志
     * @param string $logid 日志ID
     * @return bool|false|string
     * @author fuyelk <fuyelk@fuyelk.com>
     */
    private function getLog(string $logid)
    {
        if (empty($logid)) return false;

        $logFile = dirname(__DIR__) . '/log/' . mb_substr($logid, 0, 6) . '/' . mb_substr($logid, 6, 2) . '.log';
        if (!is_file($logFile)) return false;

        $content = file_get_contents($logFile);
        $logName = mb_substr($logid, 8);
        $tag = "============ [$logName] 日志开始 ============";
        $startIndex = mb_strpos($content, $tag);
        $content = mb_substr($content, $startIndex);
        $endIndex = mb_strpos($content, '============ [', 10);

        if (false === $endIndex) return $content;
        return mb_substr($content, 0, $endIndex);
    }


    // TODO 下面的方法放拷贝到公网可以访问的接口中


    /**
     * 在公网接口处重写此方法：验证服务器有效性
     * @return int|mixed
     */
    public function serverValidation()
    {
        $TOKEN = 'milingerfuyelkdoywb';
        $signature = $_GET["signature"] ?? "";
        $timestamp = $_GET["timestamp"] ?? "";
        $nonce = $_GET["nonce"] ?? "";
        $tmpArr = array($TOKEN, $timestamp, $nonce);
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode($tmpArr);
        $tmpStr = sha1($tmpStr);

        if ($tmpStr == $signature) {
            return $_GET["echostr"] ?? '';
        }
        return 'error';
    }

    /**
     * 在公网接口处重写此方法：引导用户进入授权页，并跳转到目标页
     * @param string $url 授权后需要跳转的页面地址，必须进行base64编码
     * @author fuyelk <fuyelk@fuyelk.com>
     */
    public function bootToUrl($url = '')
    {
        $redirect_uri = request()->domain() . '/index/wechat/wechatRedirect?redirect=' . $url;
        $appid = $this->wechat->appid;
        $redirect_uri = urlencode($redirect_uri);
        $scope = 'snsapi_base'; // 静默授权
//        $scope  = 'snsapi_userinfo';
        $wechat_authorize = "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$appid}&redirect_uri={$redirect_uri}&response_type=code&scope={$scope}&state=123#wechat_redirect";
        $this->redirect($wechat_authorize);
    }

    /**
     * 在公网接口处重写此方法：用户授权后微信将用户重定向至此接口并携带code，
     * 使用code可获取access_token及用户openid，完成业务逻辑处理后拼接上openid将用户重定向指指定页面
     */
    public function wechatRedirect()
    {
        $code = input('code');
        $redirect = input('redirect');
        // 获取用户信息
        if ($code) {
            $wechat_url = 'https://api.weixin.qq.com/sns/oauth2/access_token' .
                '?appid=' . $this->wechat->appid .
                '&secret=' . $this->wechat->appsecret .
                '&code=' . input('code') .
                '&grant_type=authorization_code';

            try {
                $res = $this->wechat->httpRequest($wechat_url);
            } catch (Exception $e) {
                $this->error($e->getMessage());
            }

            // {
            //  "access_token":"ACCESS_TOKEN",
            //  "expires_in":7200,
            //  "refresh_token":"REFRESH_TOKEN",
            //  "openid":"OPENID",
            //  "scope":"SCOPE"
            //}
            $res = json_decode($res, true);
            if (empty($res['openid'])) {
                $this->error('登录失败');
            }

            $redirect = base64_decode($redirect);
            $redirect = htmlspecialchars_decode($redirect);
            $query = parse_url(strstr($redirect, '?'), PHP_URL_QUERY);
            parse_str($query, $params);

            // $params 是请求是用户时携带的参数

            // 获取用户信息
            if ('snsapi_userinfo' == $res['scope']) {
                $userinfo = file_get_contents("https://api.weixin.qq.com/sns/userinfo?access_token={$res['access_token']}&openid={$res['openid']}&lang=zh_CN");
                $userinfo = json_decode($userinfo, true);
                if (!empty($userinfo['errcode']) && !empty($userinfo['errmsg'])) {
                    exit($userinfo['errmsg']);
                }
                //    [openid] => ou6NC6kBSloLS94gUhCyKeguIaYI
                //    [nickname] => zhangsan
                //    [sex] => 1 // 1=男，2=女，0=未知
                //    [language] => zh_CN
                //    [city] => 郑州
                //    [province] => 河南
                //    [country] => 中国
                //    [headimgurl] => https://thirdwx.qlogo.cn/mmopen/vi_32/xxxxxxxxxxx/132
                $invite_code = $params['sharecode'] ?? '';

                // TODO 业务处理
                //   ...

                $token = 'abcdefg';

                if (false === $token) {
                    exit('登录失败');
                }

            } else {
                // 静默授权只有openid

                try {
                    // 认证的订阅号在用户有交互的前提下可拉取用户信息
                    $wechat_url = "https://api.weixin.qq.com/cgi-bin/user/info?openid={$res["openid"]}&lang=zh_CN";

                    $userinfo = $this->wechat->wechatRequest($wechat_url);

                    //  [subscribe] => 1,
                    //  [openid] => 'ou6NC6lSIiTfHP02QhR7IYMtxdFc',
                    //  [nickname] => '张三',
                    //  [sex] => 1,
                    //  [language] => 'zh_CN',
                    //  [city] => 'Tonawanda',
                    //  [province] => 'New York',
                    //  [country] => 'US',
                    //  [headimgurl] => 'http://thirdwx.qlogo.cn/mmopen/nParxxxxxxxxxxx/132',
                    //  [subscribe_time] => 1626677249,
                    //  [remark] => '',
                    //  [groupid] => 0,
                    //  [tagid_list]' => []
                    //  [subscribe_scene] => 'ADD_SCENE_QR_CODE',

                } catch (\Exception $e) {
                    $userinfo = [];
                }

                $openid = $res['openid'];
                $token = 'abcdefg';
            }

            $redirect = $redirect . (strpos($redirect, '?') ? '&' : '?') . 'token=' . $token;

            $this->redirect($redirect ? urldecode($redirect) : '/');
        }
        $this->error('授权失败');
    }

}