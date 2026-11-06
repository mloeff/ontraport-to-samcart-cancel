<?php
/**
 * Plugin Name: Ontraport to SamCart Cancel
 * Description: Cancel a SamCart subscription when an Ontraport unsubscribe webhook arrives.
 * Version: 1.1.0
 * Author: Main Street Web Developer
 * Author URI: https://mainstreetwebdeveloper.com
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OSC_Cancel_Plugin {
    private const SETTINGS = 'osc_cancel_settings';
    private const SECRET = 'osc_cancel_webhook_secret';
    private const LAST_RESULT = 'osc_cancel_last_result';
    private const LOG = 'osc_cancel_webhook_log';
    private const LOG_LIMIT = 50;
    private const ROUTE = 'ontraport-samcart/v1/cancel';

    public static function boot(): void {
        add_action('init', array(__CLASS__, 'ensure_secret'));
        add_action('rest_api_init', array(__CLASS__, 'register_route'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('wp_ajax_osc_cancel_fields', array(__CLASS__, 'ajax_fields'));
        add_action('admin_post_osc_cancel_rotate_secret', array(__CLASS__, 'rotate_secret'));
        add_action('admin_post_osc_cancel_clear_log', array(__CLASS__, 'clear_log'));
    }

    public static function ensure_secret(): void {
        if (!get_option(self::SECRET)) {
            add_option(self::SECRET, wp_generate_password(48, false, false), '', false);
        }
    }

    private static function settings(): array {
        return wp_parse_args(get_option(self::SETTINGS, array()), array(
            'ontraport_app_id' => '',
            'ontraport_api_key' => '',
            'samcart_api_key' => '',
            'subscription_field' => '',
            'silent_cancel' => '0',
        ));
    }

    public static function register_settings(): void {
        register_setting('osc_cancel_group', self::SETTINGS, array(
            'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
            'default' => array(),
        ));
    }

    public static function sanitize_settings($input): array {
        $old = self::settings();
        $input = is_array($input) ? $input : array();
        $clean = array();
        foreach (array('ontraport_app_id', 'ontraport_api_key', 'samcart_api_key') as $key) {
            $value = isset($input[$key]) && is_scalar($input[$key]) ? trim((string) wp_unslash($input[$key])) : '';
            $clean[$key] = $value !== '' ? sanitize_text_field($value) : $old[$key];
            if (!empty($input['clear_' . $key])) {
                $clean[$key] = '';
            }
        }
        $field = isset($input['subscription_field']) && is_scalar($input['subscription_field']) ? trim((string) wp_unslash($input['subscription_field'])) : '';
        $clean['subscription_field'] = preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field) ? $field : '';
        if ($field !== '' && $clean['subscription_field'] === '') {
            add_settings_error(self::SETTINGS, 'invalid_field', 'Enter a valid Ontraport field API name (letters, numbers, and underscores).');
        }
        $clean['silent_cancel'] = !empty($input['silent_cancel']) ? '1' : '0';
        return $clean;
    }

    public static function admin_menu(): void {
        add_options_page('Ontraport → SamCart Cancel', 'Ontraport → SamCart', 'manage_options', 'osc-cancel', array(__CLASS__, 'settings_page'));
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::ensure_secret();
        $s = self::settings();
        $url = add_query_arg('token', get_option(self::SECRET), rest_url(self::ROUTE));
        $last = get_option(self::LAST_RESULT, array());
        ?>
        <div class="wrap">
            <h1>Ontraport → SamCart Cancel</h1>
            <p>When your Ontraport unsubscribe automation sends a contact ID, this plugin looks up that contact and cancels the SamCart subscription ID stored on the contact.</p>
            <?php settings_errors(self::SETTINGS); ?>
            <h2>1. Connect the accounts</h2>
            <form method="post" action="options.php">
                <?php settings_fields('osc_cancel_group'); ?>
                <table class="form-table" role="presentation">
                    <?php self::secret_field('ontraport_app_id', 'Ontraport App ID', $s['ontraport_app_id']); ?>
                    <?php self::secret_field('ontraport_api_key', 'Ontraport API key', $s['ontraport_api_key']); ?>
                    <?php self::secret_field('samcart_api_key', 'SamCart API key', $s['samcart_api_key']); ?>
                    <tr><th scope="row"><label for="osc-subscription-field">Subscription ID field</label></th><td>
                        <input class="regular-text" id="osc-subscription-field" name="<?php echo esc_attr(self::SETTINGS); ?>[subscription_field]" value="<?php echo esc_attr($s['subscription_field']); ?>" autocomplete="off" placeholder="e.g. f1234">
                        <p class="description">The Ontraport contact field’s API name, often something like <code>f1234</code>. Save the API credentials, then use the field picker below.</p>
                    </td></tr>
                    <tr><th scope="row">Cancellation email</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS); ?>[silent_cancel]" value="1" <?php checked($s['silent_cancel'], '1'); ?>> Suppress SamCart’s cancellation email</label><p class="description">Off by default, so SamCart can send its usual notice.</p></td></tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>2. Find the subscription field</h2>
            <p>After saving your Ontraport credentials, load the contact fields and pick the one that stores SamCart subscription IDs.</p>
            <p><button id="osc-load-fields" type="button" class="button">Load Ontraport fields</button> <select id="osc-field-picker" aria-label="Ontraport contact field"><option value="">Choose a field…</option></select> <span id="osc-fields-message" role="status"></span></p>

            <h2>3. Set up the Ontraport webhook</h2>
            <p>Add a <strong>Send to a webhook</strong> action to the Ontraport automation that runs when the unsubscribe form is submitted.</p>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Method</th><td><code>POST</code></td></tr>
                <tr><th scope="row">Destination URL</th><td><input class="large-text code" readonly id="osc-webhook-url" value="<?php echo esc_attr($url); ?>" onclick="this.select()"><p class="description">Keep this URL private. Its token authorizes cancellation requests. Use HTTPS for your WordPress site.</p></td></tr>
                <tr><th scope="row">Header info</th><td><code>Content-Type: application/json</code><p class="description">Choose JSON as the content type in Ontraport.</p></td></tr>
                <tr><th scope="row">POST data</th><td><code>{"contact_id":"[Contact ID]"}</code><p class="description">Insert Ontraport’s <strong>Contact ID</strong> merge field using its picker. Do not send the SamCart ID; the plugin reads the saved contact field directly from Ontraport.</p></td></tr>
            </table>
            <p>In Ontraport, send a test webhook from the intended unsubscribe automation after saving all settings. A successful cancellation returns HTTP 200. A valid contact with no subscription ID is skipped with HTTP 200. The webhook log below shows whether the request arrived and which fields were posted.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="osc_cancel_rotate_secret">
                <?php wp_nonce_field('osc_cancel_rotate_secret'); ?>
                <?php submit_button('Generate a new webhook URL', 'secondary', 'submit', false); ?>
                <span class="description">The old URL stops working immediately; update it in Ontraport.</span>
            </form>
            <?php self::render_log($last); ?>
        </div>
        <script>
        (function () {
            const button = document.getElementById('osc-load-fields');
            button.addEventListener('click', async function () {
                const message = document.getElementById('osc-fields-message');
                button.disabled = true;
                message.textContent = 'Loading…';
                try {
                    const body = new URLSearchParams({action: 'osc_cancel_fields', nonce: '<?php echo esc_js(wp_create_nonce('osc_cancel_fields')); ?>'});
                    const response = await fetch('<?php echo esc_url_raw(admin_url('admin-ajax.php')); ?>', {method: 'POST', credentials: 'same-origin', body});
                    const result = await response.json();
                    if (!result.success) throw new Error(result.data && result.data.message || 'Lookup failed.');
                    const picker = document.getElementById('osc-field-picker');
                    picker.replaceChildren(new Option('Choose a field…', ''));
                    result.data.fields.forEach(function (field) { picker.add(new Option(field.label + ' (' + field.key + ')', field.key)); });
                    message.textContent = result.data.fields.length + ' fields loaded. Select the SamCart subscription ID field.';
                    picker.focus();
                } catch (error) { message.textContent = error.message; }
                finally { button.disabled = false; }
            });
            document.getElementById('osc-field-picker').addEventListener('change', function () {
                if (this.value) document.getElementById('osc-subscription-field').value = this.value;
            });
        }());
        </script>
        <?php
    }

    private static function secret_field(string $key, string $label, string $value): void {
        ?>
        <tr><th scope="row"><label for="osc-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td>
            <input id="osc-<?php echo esc_attr($key); ?>" class="regular-text" type="password" name="<?php echo esc_attr(self::SETTINGS . '[' . $key . ']'); ?>" value="" autocomplete="new-password" placeholder="<?php echo $value !== '' ? esc_attr('Saved — leave blank to keep') : esc_attr('Paste key here'); ?>">
            <?php if ($value !== '') : ?><label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS . '[clear_' . $key . ']'); ?>" value="1"> Clear saved value</label><?php endif; ?>
        </td></tr>
        <?php
    }

    private static function render_log($last): void {
        $log = get_option(self::LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        $log = array_slice(array_values(array_filter($log, 'is_array')), 0, self::LOG_LIMIT);
        $token_labels = array('valid' => 'Valid', 'invalid' => 'Invalid', 'missing' => 'Missing');
        ?>
        <h2>Webhook log</h2>
        <p>The <?php echo (int) self::LOG_LIMIT; ?> newest requests to the webhook URL. Reload this page after a test webhook to confirm that Ontraport reached WordPress and to see the posted fields. The webhook token and API keys are not stored.</p>
        <?php if ($log === array()) : ?>
            <p>No webhooks received yet.</p>
            <?php if (is_array($last) && !empty($last['time'])) : ?>
                <p>Previous result, recorded before the webhook log: <?php echo esc_html($last['time'] . ' — ' . (string) ($last['message'] ?? '')); ?></p>
            <?php endif; ?>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">HTTP</th>
                        <th scope="col">Token</th>
                        <th scope="col">Contact ID</th>
                        <th scope="col">Received</th>
                        <th scope="col">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($entry['time'] ?? '')); ?></td>
                            <td><?php echo esc_html(isset($entry['status']) ? (string) $entry['status'] : ''); ?></td>
                            <td><?php echo esc_html($token_labels[$entry['token'] ?? ''] ?? ''); ?></td>
                            <td><?php echo esc_html(($entry['contact_id'] ?? '') !== '' ? (string) $entry['contact_id'] : '(none)'); ?></td>
                            <td><code style="white-space:pre-wrap;word-break:break-word;"><?php echo esc_html(self::format_received($entry)); ?></code></td>
                            <td><?php echo esc_html((string) ($entry['message'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="osc_cancel_clear_log">
                <?php wp_nonce_field('osc_cancel_clear_log'); ?>
                <?php submit_button('Clear webhook log', 'secondary', 'submit', false, array('onclick' => "return confirm('Clear the webhook log?');")); ?>
            </form>
        <?php endif; ?>
        <?php
    }

    private static function format_received(array $entry): string {
        $payload = $entry['payload'] ?? array();
        if (is_array($payload) && $payload !== array()) {
            $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT);
            return is_string($encoded) ? $encoded : '(unreadable body)';
        }
        $raw = isset($entry['raw']) && is_string($entry['raw']) ? $entry['raw'] : '';
        return $raw !== '' ? $raw : '(empty body)';
    }

    public static function clear_log(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', array('response' => 403));
        }
        check_admin_referer('osc_cancel_clear_log');
        delete_option(self::LOG);
        delete_option(self::LAST_RESULT);
        wp_safe_redirect(admin_url('options-general.php?page=osc-cancel'));
        exit;
    }

    public static function rotate_secret(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', array('response' => 403));
        }
        check_admin_referer('osc_cancel_rotate_secret');
        update_option(self::SECRET, wp_generate_password(48, false, false), false);
        wp_safe_redirect(admin_url('options-general.php?page=osc-cancel'));
        exit;
    }

    public static function ajax_fields(): void {
        if (!current_user_can('manage_options') || !check_ajax_referer('osc_cancel_fields', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Not authorized.'), 403);
        }
        $fields = self::lookup_fields(self::settings());
        if (is_wp_error($fields)) {
            wp_send_json_error(array('message' => $fields->get_error_message()), 400);
        }
        wp_send_json_success(array('fields' => $fields));
    }

    public static function register_route(): void {
        register_rest_route('ontraport-samcart/v1', '/cancel', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    private static function positive_id($value): string {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        return preg_match('/^[1-9][0-9]*$/', $value) ? $value : '';
    }

    public static function handle_webhook($request) {
        $secret = (string) get_option(self::SECRET, '');
        $params = $request->get_query_params();
        $token = is_array($params) ? ($params['token'] ?? '') : '';
        $provided = is_string($token) ? $token : '';
        $received = self::received_snapshot($request, $secret, $provided);
        if ($secret === '' || !hash_equals($secret, $provided)) {
            return self::result('Unauthorized.', 401, $received, $provided === '' ? 'missing' : 'invalid');
        }
        $s = self::settings();
        if ($s['ontraport_app_id'] === '' || $s['ontraport_api_key'] === '' || $s['samcart_api_key'] === '' || $s['subscription_field'] === '') {
            return self::result('Plugin setup is incomplete.', 503, $received, 'valid');
        }
        $id = self::positive_id($request->get_param('contact_id'));
        if ($id === '') {
            return self::result('A valid contact_id is required.', 400, $received, 'valid');
        }
        $contact = self::lookup_contact($id, $s);
        if (is_wp_error($contact)) {
            return self::result($contact->get_error_message(), $contact->get_error_code() === 'missing_contact' ? 404 : 502, $received, 'valid');
        }
        $raw_subscription = $contact[$s['subscription_field']] ?? null;
        if ($raw_subscription === null || $raw_subscription === '') {
            return self::result('Contact verified; no valid SamCart subscription ID in the selected field. No cancellation sent.', 200, $received, 'valid');
        }
        $subscription = self::positive_id($raw_subscription);
        if ($subscription === '') {
            return self::result('The selected Ontraport field is not a valid numeric SamCart subscription ID.', 422, $received, 'valid');
        }
        $cache_key = 'osc_done_' . md5($s['samcart_api_key'] . ':' . $id . ':' . $subscription);
        if (get_transient($cache_key)) {
            return self::result('Subscription cancellation was already processed recently.', 200, $received, 'valid');
        }
        $response = wp_remote_post('https://api.samcart.com/v1/subscriptions/' . rawurlencode($subscription) . '/cancel', array(
            'timeout' => 20,
            'redirection' => 0,
            'headers' => array(
                'sc-api' => $s['samcart_api_key'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array('silent_cancel' => $s['silent_cancel'] === '1')),
        ));
        if (is_wp_error($response)) {
            return self::result('SamCart request failed; retry the webhook.', 502, $received, 'valid');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 200) {
            set_transient($cache_key, 1, 7 * DAY_IN_SECONDS);
            return self::result('SamCart subscription canceled.', 200, $received, 'valid');
        }
        if ($status === 404) {
            return self::result('SamCart subscription was not found; check the selected field.', 422, $received, 'valid');
        }
        if ($status === 409) {
            return self::result('SamCart cannot cancel this subscription in its current status.', 409, $received, 'valid');
        }
        return self::result('SamCart rejected the cancellation (HTTP ' . $status . ').', 502, $received, 'valid');
    }

    private static function received_snapshot($request, string $secret, string $provided): array {
        $payload = array();
        if (is_object($request) && method_exists($request, 'get_json_params')) {
            $json = $request->get_json_params();
            if (is_array($json)) {
                $payload = $json;
            }
        }
        if ($payload === array() && is_object($request) && method_exists($request, 'get_body_params')) {
            $form = $request->get_body_params();
            if (is_array($form)) {
                $payload = $form;
            }
        }
        $raw = '';
        if (is_object($request) && method_exists($request, 'get_body')) {
            $body = $request->get_body();
            if (is_string($body)) {
                $raw = $body;
            }
        }
        if ($payload === array() && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $contact = '';
        if (isset($payload['contact_id']) && is_scalar($payload['contact_id'])) {
            $contact = (string) $payload['contact_id'];
        } elseif (is_object($request) && method_exists($request, 'get_param')) {
            $value = $request->get_param('contact_id');
            if (is_scalar($value)) {
                $contact = (string) $value;
            }
        }
        $payload = self::sanitize_payload($payload, $secret, $provided);
        $raw = $payload === array() ? self::clip(self::redact_text($raw, $secret, $provided), 500) : '';
        return array(
            'contact_id' => self::clip(self::redact_text(trim($contact), $secret, $provided), 64),
            'payload' => $payload,
            'raw' => $raw,
        );
    }

    private static function sanitize_payload($value, string $secret, string $provided, int $depth = 0): array {
        if (!is_array($value) || $depth > 3) {
            return array();
        }
        $clean = array();
        foreach ($value as $key => $item) {
            if (count($clean) >= 20) {
                break;
            }
            $name = self::clip(self::redact_text(is_string($key) ? $key : (string) $key, $secret, $provided), 80);
            if (preg_match('/token|secret|password|api[_-]?key|authorization/i', $name)) {
                $clean[$name] = '[redacted]';
                continue;
            }
            if (is_array($item)) {
                $clean[$name] = self::sanitize_payload($item, $secret, $provided, $depth + 1);
            } elseif (is_scalar($item) || $item === null) {
                $clean[$name] = self::clip(self::redact_text(trim((string) $item), $secret, $provided), 200);
            }
        }
        return $clean;
    }

    private static function redact_text(string $text, string $secret, string $provided): string {
        $text = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/", '', $text);
        if (!is_string($text)) {
            return '';
        }
        foreach (array($secret, $provided) as $hidden) {
            if ($hidden !== '') {
                $text = str_replace($hidden, '[redacted]', $text);
            }
        }
        return $text;
    }

    private static function clip(string $value, int $limit): string {
        if (strlen($value) <= $limit) {
            return $value;
        }
        return substr($value, 0, $limit) . '...';
    }

    private static function lookup_contact(string $id, array $s) {
        if ($s['ontraport_app_id'] === '' || $s['ontraport_api_key'] === '') {
            return new WP_Error('not_configured', 'Save the Ontraport credentials first.');
        }
        $url = add_query_arg('id', $id, 'https://api.ontraport.com/1/Contact');
        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'redirection' => 0,
            'headers' => array(
                'Api-Appid' => $s['ontraport_app_id'],
                'Api-Key' => $s['ontraport_api_key'],
                'Accept' => 'application/json',
            ),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('lookup_failed', 'Ontraport lookup failed.');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 404) {
            return new WP_Error('missing_contact', 'Ontraport contact not found.');
        }
        if ($status !== 200) {
            return new WP_Error('lookup_failed', 'Ontraport rejected the lookup (HTTP ' . $status . ').');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            return new WP_Error('lookup_failed', 'Ontraport returned an unexpected response.');
        }
        $contact = isset($body['data'][0]) ? $body['data'][0] : $body['data'];
        if (!is_array($contact) || self::positive_id($contact['id'] ?? null) !== $id) {
            return new WP_Error('missing_contact', 'Ontraport contact not found.');
        }
        return $contact;
    }

    private static function lookup_fields(array $s) {
        if ($s['ontraport_app_id'] === '' || $s['ontraport_api_key'] === '') {
            return new WP_Error('not_configured', 'Save the Ontraport credentials first.');
        }
        $response = wp_remote_get('https://api.ontraport.com/1/Contacts/meta', array(
            'timeout' => 20,
            'redirection' => 0,
            'headers' => array(
                'Api-Appid' => $s['ontraport_app_id'],
                'Api-Key' => $s['ontraport_api_key'],
                'Accept' => 'application/json',
            ),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('lookup_failed', 'Ontraport field lookup failed.');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error('lookup_failed', 'Ontraport rejected the field lookup (HTTP ' . $status . ').');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $metadata = $body['data']['0']['fields'] ?? null;
        if (!is_array($metadata)) {
            return new WP_Error('lookup_failed', 'Ontraport returned unexpected field metadata.');
        }
        $fields = array();
        foreach ($metadata as $key => $info) {
            if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
                $fields[] = array('key' => $key, 'label' => is_array($info) && isset($info['alias']) ? (string) $info['alias'] : $key);
            }
        }
        usort($fields, static function ($a, $b) { return strcasecmp($a['label'], $b['label']); });
        return $fields;
    }

    private static function result(string $message, int $status, array $received, string $token_state): WP_REST_Response {
        $entry = array(
            'time' => current_time('mysql'),
            'status' => $status,
            'message' => $message,
            'token' => $token_state,
            'contact_id' => (string) ($received['contact_id'] ?? ''),
            'payload' => is_array($received['payload'] ?? null) ? $received['payload'] : array(),
            'raw' => is_string($received['raw'] ?? null) ? $received['raw'] : '',
        );
        $log = get_option(self::LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        array_unshift($log, $entry);
        if (count($log) > self::LOG_LIMIT) {
            $log = array_slice($log, 0, self::LOG_LIMIT);
        }
        update_option(self::LOG, $log, false);
        update_option(self::LAST_RESULT, array('time' => $entry['time'], 'message' => $message), false);
        return new WP_REST_Response(array('message' => $message), $status);
    }
}

OSC_Cancel_Plugin::boot();
