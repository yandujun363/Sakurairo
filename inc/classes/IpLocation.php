<?php

namespace Sakura\API;

/**
 * 获取IP地理位置
 */
class IpLocation 
{
    //要查询的IP地址
    private $ip;
    //国家
    private $country;
    //地区
    private $region;
    //城市
    private $city;

    public function __construct(string $ip)
    {
        $this->ip = $ip;
    }

    /**
     * 通过B站API获取IP地理位置（首选，支持IPv6）
     * 接口文档：https://api.live.bilibili.com/ip_service/v1/ip_service/get_ip_addr
     *
     * @param string $userAgent 可选的User-Agent，用于模拟真实请求
     * @return boolean 成功返回true，失败返回false
     */
    private function getIpLocationByBilibili($userAgent = '')
    {
        if (empty($this->ip)) {
            return false;
        }

        $url = "https://api.live.bilibili.com/ip_service/v1/ip_service/get_ip_addr?ip=" . urlencode($this->ip);
        
        // 优先使用传入的UA，否则使用默认UA
        $ua = !empty($userAgent) ? $userAgent : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0';
        
        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'headers' => array(
                'User-Agent' => $ua
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['code']) && $data['code'] === 0 && isset($data['data'])) {
            $info = $data['data'];
            
            $this->country = !empty($info['country']) ? $info['country'] : '';
            $this->region = !empty($info['province']) ? $info['province'] : '';
            $this->city = !empty($info['city']) ? $info['city'] : '';
            
            // 处理重复：country和region相同时，清空region
            if ($this->country === $this->region) {
                $this->region = '';
            }
            // 处理重复：region和city相同时，清空city
            if ($this->region === $this->city) {
                $this->city = '';
            }
            
            return true;
        }

        return false;
    }

    /**
     * 通过IP-API接口获取IP地理位置（备选）
     * 接口文档：https://ip-api.com/docs/api:json
     *
     * @return boolean 成功返回true，失败返回false
     */
    private function getIpLocationByIpApi()
    {
        if (empty($this->ip)) {
            return false;
        }

        // 检查速率限制
        $isLimit = get_transient('ip_location_rate_limit');
        if ($isLimit) {
            return false;
        }

        // 获取WordPress语言用于本地化
        $lang = get_locale();
        $langMap = array(
            'fr' => 'fr',
            'en' => 'en',
            'zh_CN' => 'zh-CN',
            'de' => 'de',
            'es' => 'es',
            'pt_BR' => 'pt-BR',
            'ja' => 'ja',
            'ru' => 'ru'
        );
        $lang = isset($langMap[$lang]) ? $langMap[$lang] : 'en';
        
        $fields = '49177';
        $url = "http://ip-api.com/json/$this->ip?fields=$fields&lang=$lang";
        $response = wp_remote_get($url, array('timeout' => 5));

        if (is_wp_error($response)) {
            return false;
        }

        $headers = wp_remote_retrieve_headers($response);
        $remainingAmount = $headers['X-Rl'] ?? 45;
        $resetTime = $headers['X-Ttl'] ?? 60;
        
        if ($remainingAmount <= 2) {
            set_transient('ip_location_rate_limit', 'is_limit', $resetTime);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($data) && isset($data['status']) && $data['status'] === 'success') {
            $this->country = $data['country'] ?? '';
            $this->region = $data['regionName'] ?? '';
            $this->city = $data['city'] ?? '';
            return true;
        }

        return false;
    }

    /**
     * 通过Cloudflare获取国家代码（仅备选）
     * 只返回city字段，避免主权问题
     *
     * @return boolean 成功返回true，失败返回false
     */
    private function getIpLocationByCloudflare()
    {
        $countryCode = $_SERVER['HTTP_CF_CITY'] ?? '';
        
        if (empty($countryCode)) {
            return false;
        }

        $this->city = $countryCode;
        $this->country = '';
        $this->region = '';
        
        return true;
    }

    /**
     * 输出地理位置信息
     *
     * @return array 地理位置信息数组
     */
    private function outputLocation()
    {
        return array(
            'country' => $this->country ?? '',
            'region' => $this->region ?? '',
            'city' => $this->city ?? ''
        );
    }

    /**
     * 检查IP地理位置信息是否有效
     *
     * @param array $data 地理位置信息数组
     * @return boolean true有效，false无效
     */
    private function checkCompleteness(array $data)
    {
        return !empty($data['country']) || !empty($data['region']) || !empty($data['city']);
    }

    /**
     * 检查IP地址的合法性（排除空地址、非法地址以及私有地址和保留地址）
     *
     * @param string $ip 待检查的IP地址
     * @return boolean true，合法；false，非法
     */
    public static function checkIpValid(string $ip)
    {
        if (empty($ip)) {
            return false;
        }
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取IP地址的地理位置信息
     * 优先级：B站API > ip-api.com > Cloudflare
     *
     * @param string $userAgent 可选的User-Agent（用于B站API）
     * @return mixed 成功时返回地理位置信息数组；失败时返回false
     */
    public function getLocation($userAgent = '')
    {
        // 检查IP地址的合法性
        if (!static::checkIpValid($this->ip)) {
            return false;
        }

        // 优先级1：B站API（支持IPv6，精度最高）
        if ($this->getIpLocationByBilibili($userAgent)) {
            $data = $this->outputLocation();
            if ($this->checkCompleteness($data)) {
                return $data;
            }
        }

        // 优先级2：ip-api.com（备选）
        if ($this->getIpLocationByIpApi()) {
            $data = $this->outputLocation();
            if ($this->checkCompleteness($data)) {
                return $data;
            }
        }

        // 优先级3：Cloudflare（仅国家代码，放在city字段）
        if ($this->getIpLocationByCloudflare()) {
            $data = $this->outputLocation();
            if ($this->checkCompleteness($data)) {
                return $data;
            }
        }

        // 全炸：不显示任何信息
        return false;
    }
}

/**
 * IP地理位置解析输出
 */
class IpLocationParse
{
    //国家
    public $country;
    //地区
    public $region;
    //城市
    public $city;

    public function __construct(array $data)
    {
        $this->country = $data['country'] ?? '';
        $this->region = $data['region'] ?? '';
        $this->city = $data['city'] ?? '';
    }

    /**
     * 通过HTML格式输出IP地理位置信息
     *
     * @return string HTML格式地理位置信息
     */
    public function getLocationHtml()
    {
        $html = '<div class="ip-location">';
        if (!empty($this->country)) {
            $html .= '<div class="ip-location-country">' . $this->country . '</div>';
        }
        if (!empty($this->region)) {
            $html .= '<div class="ip-location-region">' . $this->region . '</div>';
        }
        if (!empty($this->city)) {
            $html .= '<div class="ip-location-city">' . $this->city . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 获取简洁的IP地址地理信息
     * 格式：国家 省份 城市（空格分隔）
     *
     * @return string 简洁的地理位置字符串
     */
    public function getLocationConcision()
    {
        $parts = array();
        
        if (!empty($this->country)) {
            $parts[] = $this->country;
        }
        if (!empty($this->region) && $this->region !== $this->country) {
            $parts[] = $this->region;
        }
        // if (!empty($this->city) && $this->city !== $this->region && $this->city !== $this->country) {
        //     $parts[] = $this->city;
        // }
        
        return !empty($parts) ? implode(' ', $parts) : '';
    }

    /**
     * 通过评论ID获取IP地理位置信息
     *
     * @param int $comment_id 评论ID
     * @return string 地理位置信息
     */
    public static function getIpLocationByCommentId(int $commentId)
    {
        $ipLocation = get_comment_meta($commentId, 'iro_ip_location', true);
        if ($ipLocation && is_array($ipLocation)) {
            $location = new IpLocationParse($ipLocation);
            return $location->getLocationConcision();
        }
        
        $commentIp = get_comment_author_IP($commentId);
        if (empty($commentIp)) {
            return __('Empty Address');
        }
        
        if (!IpLocation::checkIpValid($commentIp)) {
            return __('Reserved Address');
        }
        
        // 获取评论的 User-Agent
        $commentUserAgent = get_comment_meta($commentId, '_user_agent', true);
        if (empty($commentUserAgent)) {
            // 尝试直接从comment对象获取
            $comment = get_comment($commentId);
            $commentUserAgent = $comment->comment_agent ?? '';
        }
        
        $ipLocation = new IpLocation($commentIp);
        // 传入评论的UA
        $location = $ipLocation->getLocation($commentUserAgent);
        
        if ($location && is_array($location)) {
            if (function_exists('iro_opt') && iro_opt('save_location')) {
                add_comment_meta($commentId, 'iro_ip_location', $location);
            }
            $locationParse = new IpLocationParse($location);
            return $locationParse->getLocationConcision();
        }
        
        return __('Unknown');
    }

    /**
     * 获取单个IP地址地理位置信息
     *
     * @param string $ip IP地址
     * @return string 地理位置信息
     */
    public static function getIpLocationByIp(string $ip)
    {
        if (empty($ip)) {
            return __('Empty Address');
        }
        
        if (!IpLocation::checkIpValid($ip)) {
            return __('Reserved Address');
        }
        
        $ipLocation = new IpLocation($ip);
        $location = $ipLocation->getLocation();
        
        if ($location && is_array($location)) {
            $locationParse = new IpLocationParse($location);
            return $locationParse->getLocationConcision();
        }
        
        return __('Unknown');
    }
}