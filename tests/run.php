<?php
// WordPress and HTTP stubs: these checks never contact the real APIs.
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
$options = array('osc_cancel_settings' => array(
    'ontraport_app_id' => 'app', 'ontraport_api_key' => 'ontraport-key',
    'samcart_api_key' => 'samcart-key', 'subscription_field' => 'f1234', 'silent_cancel' => '0',
), 'osc_cancel_webhook_secret' => 'secret');
$transients = $calls = array();
$contact = array('id' => '42', 'f1234' => '4872274');
$ontraport_status = $samcart_status = 200;

function add_action(...$args) {}
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; }
function add_option($key, $value, $deprecated = '', $autoload = null) { global $options; $options[$key] = $value; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function add_query_arg($key, $value, $url) { return $url . '?' . urlencode($key) . '=' . urlencode($value); }
function wp_remote_get($url, $args) {
    global $calls, $contact, $ontraport_status;
    $calls[] = array('GET', $url, $args);
    if (strpos($url, '/Contacts/meta') !== false) {
        return array('response' => array('code' => 200), 'body' => json_encode(array('data' => array('0' => array('fields' => array('f1234' => array('alias' => 'SamCart Subscription ID'), 'email' => array('alias' => 'Email')))))));
    }
    return array('response' => array('code' => $ontraport_status), 'body' => json_encode(array('code' => 0, 'data' => $contact)));
}
function wp_remote_post($url, $args) {
    global $calls, $samcart_status;
    $calls[] = array('POST', $url, $args);
    return array('response' => array('code' => $samcart_status), 'body' => '{}');
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $seconds) { global $transients; $transients[$key] = $value; }
function current_time($format) { return '2026-10-30 22:00:00'; }
class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
class Mock_Request {
    private $token; private $id;
    public function __construct($token, $id) { $this->token = $token; $this->id = $id; }
    public function get_query_params() { return array('token' => $this->token); }
    public function get_param($key) { return $this->id; }
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }

require dirname(__DIR__) . '/ontraport-samcart-cancel.php';

$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('bad', '42'));
check($response->status === 401 && count($calls) === 0, 'Invalid token must not call APIs');
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', 'nope'));
check($response->status === 400 && count($calls) === 0, 'Invalid contact ID must not call APIs');
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '42'));
check($response->status === 200 && count($calls) === 2, 'Valid contact should be canceled');
check(strpos($calls[0][1], '/1/Contact?id=42') !== false, 'Must use single contact endpoint');
check($calls[0][2]['headers']['Api-Key'] === 'ontraport-key', 'Ontraport authentication missing');
check($calls[1][1] === 'https://api.samcart.com/v1/subscriptions/4872274/cancel', 'Wrong SamCart endpoint');
check($calls[1][2]['headers']['sc-api'] === 'samcart-key', 'SamCart authentication missing');
check(json_decode($calls[1][2]['body'], true)['silent_cancel'] === false, 'Default email setting wrong');
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '42'));
check($response->status === 200 && count($calls) === 3, 'Duplicate must not call SamCart again');

$contact['id'] = '43'; $contact['f1234'] = '';
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '43'));
check($response->status === 200 && count($calls) === 4, 'Empty subscription should skip SamCart');
$contact['id'] = '44'; $contact['f1234'] = 'abc';
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '44'));
check($response->status === 422 && count($calls) === 5, 'Invalid subscription should fail without SamCart call');
$contact['id'] = '99'; $contact['f1234'] = '123';
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '45'));
check($response->status === 404 && count($calls) === 6, 'Mismatched contact must be rejected');

$contact['id'] = '46'; $ontraport_status = 401;
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '46'));
check($response->status === 502 && count($calls) === 7, 'Ontraport auth failure must not cancel');
$ontraport_status = 200; $samcart_status = 409;
$response = OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '46'));
check($response->status === 409 && count($calls) === 9, 'SamCart conflict should be reported');

$method = new ReflectionMethod('OSC_Cancel_Plugin', 'lookup_fields');
$method->setAccessible(true);
$fields = $method->invoke(null, $options['osc_cancel_settings']);
check($fields[1]['key'] === 'f1234' && $fields[1]['label'] === 'SamCart Subscription ID', 'Field picker should use Ontraport metadata aliases');

echo "All webhook checks passed.\n";
