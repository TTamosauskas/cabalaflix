<?php
/**
 * Plugin Name: MSflix YouTube Cast — GitHub Pages
 * Description: Ponte server-side entre CabalaFlix/MSflix e a YouTube Lounge API, com suporte explícito ao GitHub Pages.
 * Version: 2.0.0
 * Author: MSflix / CabalaFlix
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MSFlix_YouTube_Cast_GitHub_Pages {
    const VERSION = '2.0.0';
    const NAMESPACE = 'msflix/v1';
    const YOUTUBE_BASE = 'https://www.youtube.com/';
    const LOUNGE_TOKEN_URL = 'https://www.youtube.com/api/lounge/pairing/get_lounge_token_batch';
    const BIND_URL = 'https://www.youtube.com/api/lounge/bc/bind';

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'), 999);
        add_filter('rest_pre_serve_request', array(__CLASS__, 'send_cors_headers'), 20, 4);
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
    }

    public static function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/youtube-cast-health',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array(__CLASS__, 'health'),
                'permission_callback' => '__return_true',
            ),
            true
        );

        register_rest_route(
            self::NAMESPACE,
            '/youtube-cast',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array(__CLASS__, 'cast'),
                'permission_callback' => array(__CLASS__, 'cast_permission'),
            ),
            true
        );
    }

    public static function health() {
        return rest_ensure_response(array(
            'ok'      => true,
            'service' => 'MSflix YouTube Cast — GitHub Pages',
            'version' => self::VERSION,
        ));
    }

    private static function normalize_origin($origin) {
        $origin = trim((string) $origin);
        if ($origin === '') {
            return '';
        }

        $parts = wp_parse_url($origin);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        return $normalized;
    }

    public static function allowed_origins() {
        $origins = array(
            'https://ttamosauskas.github.io',
            self::normalize_origin(home_url('/')),
            self::normalize_origin(site_url('/')),
        );

        $origins = apply_filters('msflix_youtube_cast_allowed_origins', $origins);
        $out = array();

        foreach ((array) $origins as $origin) {
            $origin = self::normalize_origin($origin);
            if ($origin !== '') {
                $out[$origin] = true;
            }
        }

        return array_keys($out);
    }

    private static function origin_allowed($origin) {
        $origin = self::normalize_origin($origin);
        if ($origin === '') {
            return false;
        }
        return in_array($origin, self::allowed_origins(), true);
    }

    public static function cast_permission($request) {
        $origin = get_http_origin();

        if (!$origin && is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }

        if (!$origin) {
            return new WP_Error(
                'msflix_origin_missing',
                'Origem ausente.',
                array('status' => 403)
            );
        }

        if (!self::origin_allowed($origin)) {
            return new WP_Error(
                'msflix_origin_forbidden',
                'Origem não autorizada',
                array(
                    'status' => 403,
                    'origin' => self::normalize_origin($origin),
                )
            );
        }

        return true;
    }

    public static function send_cors_headers($served, $result, $request, $server) {
        if (!($request instanceof WP_REST_Request)) {
            return $served;
        }

        $route = $request->get_route();
        if (strpos($route, '/' . self::NAMESPACE . '/') !== 0) {
            return $served;
        }

        $origin = get_http_origin();
        if ($origin && self::origin_allowed($origin)) {
            header('Access-Control-Allow-Origin: ' . self::normalize_origin($origin), true);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS', true);
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce', true);
            header('Access-Control-Max-Age: 600', true);
            header('Vary: Origin', false);
        }

        return $served;
    }

    private static function param($request, $name, $default = '') {
        $value = $request->get_param($name);
        return $value === null ? $default : $value;
    }

    public static function cast($request) {
        $action = strtolower(trim((string) self::param($request, 'action')));
        $screen_id = trim((string) self::param($request, 'screen_id'));
        $video_id = trim((string) self::param($request, 'video_id'));
        $device_id = trim((string) self::param($request, 'device_id'));
        $device_name = trim((string) self::param($request, 'device_name', 'CabalaFlix'));

        if (!in_array($action, array('play', 'enqueue'), true)) {
            return new WP_Error(
                'msflix_invalid_action',
                'Ação inválida. Use "play" ou "enqueue".',
                array('status' => 400)
            );
        }

        if ($screen_id === '' || strlen($screen_id) > 512 || preg_match('/[\x00-\x1F\x7F]/', $screen_id)) {
            return new WP_Error(
                'msflix_invalid_screen_id',
                'screen_id inválido.',
                array('status' => 400)
            );
        }

        if (!preg_match('/^[A-Za-z0-9_-]{6,32}$/', $video_id)) {
            return new WP_Error(
                'msflix_invalid_video_id',
                'video_id inválido.',
                array('status' => 400)
            );
        }

        $device_id = preg_replace('/[^A-Za-z0-9_-]/', '', $device_id);
        if ($device_id === '') {
            $device_id = substr(hash('sha256', home_url('/') . '|msflix'), 0, 26);
        }
        $device_id = substr($device_id, 0, 64);

        $device_name = sanitize_text_field($device_name);
        if ($device_name === '') {
            $device_name = 'CabalaFlix';
        }
        $device_name = function_exists('mb_substr') ? mb_substr($device_name, 0, 80) : substr($device_name, 0, 80);

        $token = self::get_lounge_token($screen_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $session = self::bind_session($token, $device_id, $device_name);
        if (is_wp_error($session)) {
            return $session;
        }

        $sent = self::send_action($token, $session, $action, $video_id);
        if (is_wp_error($sent)) {
            return $sent;
        }

        return rest_ensure_response(array(
            'ok'       => true,
            'action'   => $action,
            'video_id' => $video_id,
            'queued'   => ($action === 'enqueue'),
            'version'  => self::VERSION,
        ));
    }

    private static function youtube_headers($extra = array()) {
        $headers = array(
            'Origin'       => self::YOUTUBE_BASE,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => '*/*',
            'User-Agent'   => 'Mozilla/5.0 (MSflix YouTube Cast; WordPress)',
        );

        foreach ($extra as $key => $value) {
            $headers[$key] = $value;
        }

        return $headers;
    }

    private static function post_form($url, $data, $headers = array(), $timeout = 20) {
        $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $headers = self::youtube_headers($headers);
        $headers['Content-Length'] = (string) strlen($body);

        $response = wp_remote_post(
            $url,
            array(
                'timeout'     => $timeout,
                'redirection' => 2,
                'sslverify'   => true,
                'headers'     => $headers,
                'body'        => $body,
                'data_format' => 'body',
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'msflix_youtube_network',
                'Falha de rede ao acessar a YouTube Lounge API: ' . $response->get_error_message(),
                array('status' => 502)
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $text = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            $preview = preg_replace('/\s+/', ' ', wp_strip_all_tags($text));
            $preview = substr((string) $preview, 0, 220);
            return new WP_Error(
                'msflix_youtube_http',
                'YouTube Lounge respondeu HTTP ' . $status . ($preview !== '' ? ': ' . $preview : ''),
                array(
                    'status'         => 502,
                    'youtube_status' => $status,
                )
            );
        }

        return array(
            'status' => $status,
            'body'   => $text,
        );
    }

    private static function get_lounge_token($screen_id) {
        $response = self::post_form(
            self::LOUNGE_TOKEN_URL,
            array('screen_ids' => $screen_id)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $json = json_decode($response['body'], true);
        if (
            !is_array($json) ||
            empty($json['screens']) ||
            !is_array($json['screens']) ||
            empty($json['screens'][0]['loungeToken'])
        ) {
            return new WP_Error(
                'msflix_lounge_token',
                'O YouTube não retornou um loungeToken para este screen_id.',
                array('status' => 502)
            );
        }

        return (string) $json['screens'][0]['loungeToken'];
    }

    private static function bind_session($lounge_token, $device_id, $device_name) {
        $bind_data = array(
            'device'       => 'REMOTE_CONTROL',
            'id'           => $device_id,
            'name'         => $device_name,
            'mdx-version'  => 3,
            'pairing_type' => 'cast',
            'app'          => 'android-phone-13.14.55',
        );

        $url = add_query_arg(
            array(
                'RID'  => 0,
                'VER'  => 8,
                'CVER' => 1,
            ),
            self::BIND_URL
        );

        $response = self::post_form(
            $url,
            $bind_data,
            array('X-YouTube-LoungeId-Token' => $lounge_token)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = (string) $response['body'];
        $sid = '';
        $gsession_id = '';

        $sid_patterns = array(
            '/\["c","([^"]+)"/',
            '/"c","([^"]+)"/',
        );
        foreach ($sid_patterns as $pattern) {
            if (preg_match($pattern, $body, $match)) {
                $sid = $match[1];
                break;
            }
        }

        $gsession_patterns = array(
            '/\["S","([^"]+)"\]/',
            '/"S","([^"]+)"\]/',
            '/"S","([^"]+)"/',
        );
        foreach ($gsession_patterns as $pattern) {
            if (preg_match($pattern, $body, $match)) {
                $gsession_id = $match[1];
                break;
            }
        }

        if ($sid === '' || $gsession_id === '') {
            return new WP_Error(
                'msflix_bind_parse',
                'O YouTube abriu a sessão, mas a resposta de bind não trouxe SID/gsessionid reconhecíveis.',
                array('status' => 502)
            );
        }

        return array(
            'sid'         => $sid,
            'gsession_id' => $gsession_id,
        );
    }

    private static function send_action($lounge_token, $session, $action, $video_id) {
        if ($action === 'play') {
            $data = array(
                'count'             => 1,
                'req0__sc'          => 'setPlaylist',
                'req0_listId'       => '',
                'req0_currentTime'  => '0',
                'req0_currentIndex' => '-1',
                'req0_audioOnly'    => 'false',
                'req0_videoId'      => $video_id,
            );
        } else {
            $data = array(
                'count'        => 1,
                'req0__sc'     => 'addVideo',
                'req0_videoId' => $video_id,
            );
        }

        $url = add_query_arg(
            array(
                'SID'        => $session['sid'],
                'gsessionid' => $session['gsession_id'],
                'RID'        => 1,
                'VER'        => 8,
                'CVER'       => 1,
            ),
            self::BIND_URL
        );

        return self::post_form(
            $url,
            $data,
            array('X-YouTube-LoungeId-Token' => $lounge_token)
        );
    }

    public static function admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active = (array) get_option('active_plugins', array());
        if (in_array('msflix-youtube-cast/msflix-youtube-cast.php', $active, true)) {
            echo '<div class="notice notice-warning"><p><strong>MSflix YouTube Cast — GitHub Pages:</strong> o plugin antigo "MSflix YouTube Cast" também está ativo. Este plugin sobrescreve as mesmas rotas REST, mas é recomendável desativar o antigo depois de confirmar o funcionamento.</p></div>';
        }
    }
}

MSFlix_YouTube_Cast_GitHub_Pages::boot();
