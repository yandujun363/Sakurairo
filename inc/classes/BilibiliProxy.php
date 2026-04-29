<?php
namespace Sakura\API;

class BilibiliProxy
{
    private $cookies;
    
    public function __construct()
    {
        $this->cookies = iro_opt('bilibili_cookie');
    }
    
    /**
     * 获取B站用户卡片信息
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_user_card($request)
    {
        // 获取查询参数
        $user_id = $request->get_param('user_id');
        $mid = $request->get_param('mid');
        
        // B站API需要 mid 参数
        $target_mid = $user_id ?: $mid;
        if (empty($target_mid)) {
            return new \WP_REST_Response([
                'code' => -1,
                'message' => 'Missing user_id or mid parameter'
            ], 400);
        }
        
        $target_url = 'https://api.bilibili.com/x/web-interface/card?mid=' . urlencode($target_mid) . '&photo=true';
        
        // 获取前端 UA
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.97 Safari/537.36';
        
        // 构建请求参数
        $args = array(
            'headers' => array(
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Origin' => 'https://www.bilibili.com',
                'Host' => 'api.bilibili.com',
                'Cookie' => $this->cookies, 
                'Referer' => 'https://www.bilibili.com/',
                'User-Agent' => $user_agent,
            ),
            'timeout' => 10,
        );
        
        // 使用 wp_remote_get 发起请求
        $response = wp_remote_get($target_url, $args);
        
        if (is_wp_error($response)) {
            return new \WP_REST_Response([
                'code' => 500,
                'error' => 'Request failed', 
                'message' => $response->get_error_message()
            ], 500);
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        if ($http_code === 200) {
            return new \WP_REST_Response($response_data, 200);
        } else {
            return new \WP_REST_Response([
                'code' => $http_code,
                'error' => 'Bilibili API request failed'
            ], $http_code);
        }
    }
}