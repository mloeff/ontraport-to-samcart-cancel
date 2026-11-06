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
    private $token; private $id; private $json; private $body;
    public function __construct($token, $id, $json = null, $raw = null) {
        $this->token = $token;
        $this->id = $id;
        $this->json = $json;
        if ($raw !== null) {
            $this->body = $raw;
        } elseif ($json !== null) {
            $this->body = json_encode($json);
        } else {
            $this->body = '';
        }
    }
    public function get_query_params() { return array('token' => $this->token); }
    public function get_param($key) {
        if ($key !== 'contact_id') {
            return null;
        }
        if (is_array($this->json) && array_key_exists('contact_id', $this->json)) {
            return $this->json['contact_id'];
        }
        return $this->id;
    }
    public function get_json_params() { return is_array($this->json) ? $this->json : null; }
    public function get_body() { return $this->body; }
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

$log = get_option('osc_cancel_webhook_log', array());
check(is_array($log) && count($log) === 9, 'Each webhook is logged, including rejected tokens');
check($log[0]['status'] === 409 && $log[0]['token'] === 'valid', 'Newest log entry is the latest webhook');
check($log[8]['status'] === 401 && $log[8]['token'] === 'invalid', 'Rejected token is recorded');
$encoded = json_encode($log);
check(strpos($encoded, 'secret') === false, 'Webhook token must not be stored');
check(strpos($encoded, 'samcart-key') === false && strpos($encoded, 'ontraport-key') === false, 'API keys must not be stored');

OSC_Cancel_Plugin::handle_webhook(new Mock_Request('secret', '42', array(
    'contact_id' => '42', 'api_key' => 'super-secret', 'note' => 'from-ontraport',
)));
$log = get_option('osc_cancel_webhook_log', array());
check($log[0]['contact_id'] === '42' && $log[0]['payload']['note'] === 'from-ontraport', 'Received contact ID and fields are stored');
check($log[0]['payload']['api_key'] === '[redacted]' && strpos(json_encode($log[0]), 'super-secret') === false, 'Secret-like received fields are redacted');

OSC_Cancel_Plugin::handle_webhook(new Mock_Request('bad', '', null, 'contact_id=[Contact ID]'));
$log = get_option('osc_cancel_webhook_log', array());
check($log[0]['status'] === 401 && $log[0]['raw'] === 'contact_id=[Contact ID]' && $log[0]['payload'] === array(), 'Unparsed body is stored when JSON is missing');

for ($i = 0; $i < 60; $i++) {
    OSC_Cancel_Plugin::handle_webhook(new Mock_Request('', (string) $i));
}
$log = get_option('osc_cancel_webhook_log', array());
check(count($log) === 50 && $log[0]['token'] === 'missing' && $log[0]['contact_id'] === '59', 'Webhook log keeps the latest 50 requests');

echo "All webhook checks passed.\n";
