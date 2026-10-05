<?php
/**
 * Plugin Name:       Push Agent
 * Plugin URI:        https://pushagent.net
 * Description:       Web push notifications for your WordPress site, plus WooCommerce order-status notifications for your customers. Paste your Push Agent access token under Push Agent &rarr; Settings and you're done.
 * Version:           2.2.0
 * Requires at least: 4.7
 * Requires PHP:      5.6
 * Author:            Push Agent
 * Author URI:        https://pushagent.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pushagent
 *
 * Adds the Push Agent script to every front-end page and creates the
 * /pushagent-sw.js service worker file in the site root automatically.
 *
 * WooCommerce (Push Agent Growth plan): sends the customer a notification when their order's status changes,
 * for the statuses the shop owner switches on, with the shop owner's own messages. Custom order statuses
 * registered with WooCommerce are listed automatically.
 *
 * WC requires at least: 3.0
 * WC tested up to:      10.2
 */

if (!defined('ABSPATH')) {
	exit;
}

final class PushAgent_Plugin
{
	const VERSION         = '2.2.0';
	const OPTION          = 'pushagent_settings';
	const PAGE            = 'pushagent';
	/** Marker the Push Agent app looks for when it verifies the service worker. */
	const SW_MARKER       = 'pushagent-v2';
	const SW_FILE         = 'pushagent-sw.js';
	const DEFAULT_APP_URL = 'https://app.pushagent.net/';
	/** The last order updates, shown on the plugin's dashboard. */
	const LOG_OPTION      = 'pushagent_log';

	public static function init()
	{
		self::migrate();

		// Serve /pushagent-sw.js from WordPress when the file can't be written to disk
		add_action('init', array(__CLASS__, 'serve_service_worker'), 0);
		add_action('wp_footer', array(__CLASS__, 'print_script'), 99);

		// WooCommerce order updates
		add_action('woocommerce_order_status_changed', array(__CLASS__, 'on_order_status_changed'), 10, 4);
		add_action('pushagent_order_update', array(__CLASS__, 'send_order_update'), 10, 2);
		add_action('woocommerce_thankyou', array(__CLASS__, 'thankyou_box'), 5);
		add_action('before_woocommerce_init', array(__CLASS__, 'declare_wc_compatibility'));

		if (is_admin()) {
			require_once __DIR__ . '/includes/class-pushagent-admin.php';
			PushAgent_Admin::init(__FILE__);
		}
	}

	/* ------------------------------------------------------------------ settings */

	public static function defaults()
	{
		return array(
			'token'     => '',
			'delay'     => 3,
			'app_url'   => self::DEFAULT_APP_URL,
			'create_sw' => 1,
			'api_key'   => '',
			// WooCommerce order updates
			'wc_on'        => array(),   // status slug => 1
			'wc_title'     => array(),   // status slug => title template
			'wc_body'      => array(),   // status slug => message template
			'wc_title_all' => '',        // used for statuses without their own title
			'wc_body_all'  => '',
			'wc_optin'     => 1,         // "Get updates about this order" box on the order confirmation page
		);
	}

	/**
	 * Version 1.x kept only the access token, in the "pushagent-accesstoken" option.
	 * Carry it over once so sites that update keep working without re-entering it.
	 */
	public static function migrate()
	{
		if (get_option(self::OPTION, false) !== false) {
			return;
		}

		$old = get_option('pushagent-accesstoken', '');
		$new = self::defaults();

		if (is_string($old) && trim($old) !== '') {
			$new = self::sanitize(array_merge($new, array('token' => $old)));
		}

		add_option(self::OPTION, $new);
		delete_option('pushagent-accesstoken');
	}

	public static function settings()
	{
		$s = get_option(self::OPTION, array());

		return array_merge(self::defaults(), is_array($s) ? $s : array());
	}

	/**
	 * The Push Agent address. Fixed to app.pushagent.net; it is not a setting.
	 * Developers testing against their own copy can define PUSHAGENT_APP_URL in wp-config.php.
	 * The argument is ignored (kept so older calls keep working).
	 */
	public static function app_url($unused = '')
	{
		$url = defined('PUSHAGENT_APP_URL') ? trim((string) PUSHAGENT_APP_URL) : '';

		if ($url === '' || !preg_match('~^https?://~i', $url)) {
			$url = self::DEFAULT_APP_URL;
		}

		return rtrim($url, '/') . '/';
	}

	public static function sanitize($in)
	{
		$in  = is_array($in) ? $in : array();
		$tab = isset($in['_tab']) ? (string) $in['_tab'] : 'general';

		// Each settings tab saves only its own fields; keep everything else as it is
		$out = array_merge(self::defaults(), self::settings());

		if ($tab === 'woocommerce') {
			return self::sanitize_wc($in, $out);
		}

		$token = isset($in['token']) ? trim((string) $in['token']) : '';

		// Tokens look like XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX; pick it out even if extra text was pasted with it
		if (preg_match('/[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12}/', $token, $m)) {
			$token = $m[0];
		}

		$out['token'] = substr(preg_replace('/[^A-Za-z0-9\-]/', '', $token), 0, 100);

		$delay        = isset($in['delay']) ? (int) $in['delay'] : 3;
		$out['delay'] = max(0, min(120, $delay));

		$out['app_url']   = self::DEFAULT_APP_URL;   // no longer a setting, see app_url()
		$out['create_sw'] = empty($in['create_sw']) ? 0 : 1;

		$key = isset($in['api_key']) ? trim((string) $in['api_key']) : '';
		$out['api_key'] = preg_match('/pak_[a-f0-9]{40}/', $key, $m) ? $m[0] : '';

		self::clear_cache();

		return $out;
	}

	/** Forget the cached connection and service-worker checks (after saving, or "Check again"). */
	public static function clear_cache()
	{
		delete_transient('pushagent_ping');
		delete_transient('pushagent_sw');
	}

	private static function sanitize_wc($in, $out)
	{
		$clean = function ($v, $max) {
			return mb_substr(trim(wp_strip_all_tags((string) $v)), 0, $max);
		};

		$out['wc_on'] = $out['wc_title'] = $out['wc_body'] = array();

		foreach (array('wc_on', 'wc_title', 'wc_body') as $field) {
			if (empty($in[$field]) || !is_array($in[$field])) {
				continue;
			}
			foreach ($in[$field] as $slug => $v) {
				$slug = sanitize_key($slug);
				if ($slug === '') {
					continue;
				}
				if ($field === 'wc_on') {
					if (!empty($v)) {
						$out['wc_on'][$slug] = 1;
					}
				} elseif (trim((string) $v) !== '') {
					$out[$field][$slug] = $clean($v, $field === 'wc_title' ? 120 : 255);
				}
			}
		}

		$out['wc_title_all'] = isset($in['wc_title_all']) ? $clean($in['wc_title_all'], 120) : '';
		$out['wc_body_all']  = isset($in['wc_body_all']) ? $clean($in['wc_body_all'], 255) : '';
		$out['wc_optin']     = empty($in['wc_optin']) ? 0 : 1;

		return $out;
	}

	/* ------------------------------------------------------------------ front end */

	/** Add the subscriber script to every front-end page (before </body>). */
	public static function print_script()
	{
		if (is_admin() || is_feed() || (function_exists('is_customize_preview') && is_customize_preview())) {
			return;
		}

		$s = self::settings();

		if ($s['token'] === '') {
			return;
		}

		// Make sure the service worker exists (cheap check; rewritten only when missing or different)
		if ($s['create_sw']) {
			self::write_service_worker($s['token'], self::app_url($s['app_url']), false);
		}

		$src = self::app_url($s['app_url']) . 'embed.php?t=' . rawurlencode($s['token']) . '&delay=' . (int) $s['delay'];

		// Order updates: link this browser's subscription to the customer (a scrambled code, never the email)
		$code = self::current_customer_code();
		if ($code !== '') {
			echo "\n" . '<script data-cfasync="false" data-no-optimize="1">window.PushAgentUser=' . wp_json_encode($code) . ';</script>';
		}

		// data-cfasync / data-no-optimize keep Cloudflare Rocket Loader and optimisation plugins from rewriting it
		echo "\n" . '<script src="' . esc_url($src) . '" async data-cfasync="false" data-no-optimize="1" data-no-minify="1"></script>' . "\n";
	}

	/**
	 * Fallback: if /pushagent-sw.js isn't a real file (folder not writable, multisite, ...)
	 * the web server passes the request to WordPress, and we answer it here.
	 */
	public static function serve_service_worker()
	{
		if (empty($_SERVER['REQUEST_URI'])) {
			return;
		}

		$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

		if ($path !== '/' . self::SW_FILE) {
			return;
		}

		$s = self::settings();

		if ($s['token'] === '' || !$s['create_sw']) {
			return;
		}

		nocache_headers();
		header('Content-Type: application/javascript; charset=utf-8');
		header('X-Robots-Tag: noindex');
		echo self::service_worker_content($s['token'], self::app_url($s['app_url'])); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}


	/* ------------------------------------------------------------------ WooCommerce order updates */

	public static function wc_active()
	{
		return class_exists('WooCommerce') && function_exists('wc_get_order_statuses');
	}

	/** Order updates are on when an API key is set and at least one status is switched on. */
	public static function wc_enabled()
	{
		$s = self::settings();

		return self::wc_active() && $s['api_key'] !== '' && !empty($s['wc_on']);
	}

	/** The customer code Push Agent knows a customer by: HMAC of the email with the API key. */
	public static function customer_code($email)
	{
		$s     = self::settings();
		$email = strtolower(trim((string) $email));

		if ($email === '' || $s['api_key'] === '') {
			return '';
		}

		return hash_hmac('sha256', 'email:' . $email, $s['api_key']);
	}

	/** Who is looking at this page: the customer on their order confirmation page, or the logged-in user. */
	public static function current_customer_code()
	{
		if (!self::wc_enabled()) {
			return '';
		}

		if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
			$order_id = absint(get_query_var('order-received'));
			$key      = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$order    = $order_id ? wc_get_order($order_id) : false;

			if ($order && $key !== '' && hash_equals((string) $order->get_order_key(), (string) $key)) {
				return self::customer_code($order->get_billing_email());
			}
		}

		if (is_user_logged_in()) {
			$user = wp_get_current_user();

			return self::customer_code($user->user_email);
		}

		return '';
	}

	/** Status slug without the "wc-" prefix. */
	private static function slug($status)
	{
		return sanitize_key(preg_replace('/^wc-/', '', (string) $status));
	}

	public static function on_order_status_changed($order_id, $from, $to, $order = null)
	{
		$s  = self::settings();
		$to = self::slug($to);

		if (!self::wc_enabled() || empty($s['wc_on'][$to])) {
			return;
		}

		// Send in the background (WooCommerce's Action Scheduler) so changing a status stays fast
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action('pushagent_order_update', array((int) $order_id, $to), 'pushagent');
		} else {
			self::send_order_update((int) $order_id, $to);
		}
	}

	/** Build the message from the shop owner's template and send it to the customer through Push Agent. */
	public static function send_order_update($order_id, $status)
	{
		$s     = self::settings();
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;

		if (!$order || $s['api_key'] === '') {
			return;
		}

		$status = self::slug($status);
		$title  = isset($s['wc_title'][$status]) ? $s['wc_title'][$status] : $s['wc_title_all'];
		$body   = isset($s['wc_body'][$status]) ? $s['wc_body'][$status] : $s['wc_body_all'];

		if (trim($title) === '') {
			$order->add_order_note(sprintf(__('Push Agent: no notification sent - there is no message for the "%s" status (Push Agent → Order updates).', 'pushagent'), wc_get_order_status_name($status)));
			self::log_update($order, $status, 'skipped', __('No message written for this status', 'pushagent'));
			return;
		}

		$vars = array(
			'{first_name}'   => $order->get_billing_first_name(),
			'{last_name}'    => $order->get_billing_last_name(),
			'{order_number}' => $order->get_order_number(),
			'{status}'       => wc_get_order_status_name($status),
			'{total}'        => html_entity_decode(wp_strip_all_tags(wc_price($order->get_total(), array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8'),
			'{site_name}'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
		);
		$title = trim(strtr($title, $vars));
		$body  = trim(strtr($body, $vars));

		// The customer may be known by their billing email and by their account email
		$to = array(self::customer_code($order->get_billing_email()));
		if ($order->get_customer_id()) {
			$user = get_user_by('id', $order->get_customer_id());
			if ($user) {
				$to[] = self::customer_code($user->user_email);
			}
		}
		$to = array_values(array_unique(array_filter($to)));

		$url = $order->get_customer_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();

		$res = wp_remote_post(self::app_url($s['app_url']) . 'apiv1.php?r=notify', array(
			'timeout' => 15,
			'headers' => array('Content-Type' => 'application/json', 'X-PushAgent-Key' => $s['api_key']),
			'body'    => wp_json_encode(array(
				'to'    => $to,
				'title' => $title,
				'body'  => $body,
				'url'   => $url,
				'ref'   => '#' . $order->get_order_number(),
				'event' => wc_get_order_status_name($status),
			)),
		));

		if (is_wp_error($res)) {
			$order->add_order_note(sprintf(__('Push Agent: could not send the notification (%s).', 'pushagent'), $res->get_error_message()));
			self::log_update($order, $status, 'failed', $res->get_error_message());
			return;
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$data = json_decode((string) wp_remote_retrieve_body($res), true);

		if ($code !== 200 || !is_array($data)) {
			$err = is_array($data) && !empty($data['error']) ? $data['error'] : 'HTTP ' . $code;
			$order->add_order_note(sprintf(__('Push Agent: could not send the notification (%s).', 'pushagent'), $err));
			self::log_update($order, $status, 'failed', $err);
			return;
		}

		if (empty($data['devices'])) {
			$order->add_order_note(__('Push Agent: no notification sent - the customer has not allowed notifications on any browser yet.', 'pushagent'));
			self::log_update($order, $status, 'nodevice', __('Customer has not turned on notifications', 'pushagent'));
		} elseif (!empty($data['sent'])) {
			$order->add_order_note(sprintf(_n('Push Agent: "%1$s" sent to the customer (%2$d device).', 'Push Agent: "%1$s" sent to the customer (%2$d devices).', (int) $data['sent'], 'pushagent'), $title, (int) $data['sent']));
			self::log_update($order, $status, 'sent', $title, (int) $data['sent']);
		} else {
			$order->add_order_note(__('Push Agent: the notification could not be delivered (the customer\'s browser subscription may have expired).', 'pushagent'));
			self::log_update($order, $status, 'failed', __('The customer\'s browser subscription may have expired', 'pushagent'));
		}
	}

	/** Keep the last 20 order updates for the dashboard (not autoloaded). */
	private static function log_update($order, $status, $result, $detail, $devices = 0)
	{
		$log = get_option(self::LOG_OPTION, array());
		$log = is_array($log) ? $log : array();

		array_unshift($log, array(
			't'       => time(),
			'order'   => (string) $order->get_order_number(),
			'edit'    => method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : '',
			'name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
			'status'  => wc_get_order_status_name($status),
			'result'  => $result,
			'detail'  => mb_substr((string) $detail, 0, 160),
			'devices' => (int) $devices,
		));

		update_option(self::LOG_OPTION, array_slice($log, 0, 20), false);
	}

	/** "Get updates about this order" box on the order confirmation (thank-you) page. */
	public static function thankyou_box($order_id)
	{
		$s = self::settings();

		if (!$s['wc_optin'] || !self::wc_enabled() || $s['token'] === '') {
			return;
		}
		?>
		<div id="pushagent-order-updates" style="display:none;margin:0 0 24px;padding:16px 18px;border:1px solid rgba(0,0,0,.12);border-radius:10px;align-items:center;gap:14px;flex-wrap:wrap">
			<span style="font-size:22px;line-height:1" aria-hidden="true">&#128276;</span>
			<div style="flex:1;min-width:200px">
				<strong><?php esc_html_e('Get updates about this order', 'pushagent'); ?></strong>
				<div class="pa-text" style="opacity:.8"><?php esc_html_e('We\'ll send a notification to this browser when your order status changes.', 'pushagent'); ?></div>
			</div>
			<button type="button" class="button pa-on"><?php esc_html_e('Turn on updates', 'pushagent'); ?></button>
		</div>
		<script data-cfasync="false">
		(function () {
			var box = document.getElementById('pushagent-order-updates');
			if (!box || !('Notification' in window) || !('serviceWorker' in navigator)) return;
			var btn = box.querySelector('.pa-on'), text = box.querySelector('.pa-text');
			function done() {
				btn.style.display = 'none';
				text.textContent = <?php echo wp_json_encode(__('You\'ll get a notification in this browser when your order status changes.', 'pushagent')); ?>;
			}
			if (Notification.permission === 'denied') return;
			box.style.display = 'flex';
			if (Notification.permission === 'granted') { done(); return; }
			btn.addEventListener('click', function () {
				btn.disabled = true;
				var tries = 0;
				(function go() {
					if (window.PushAgent && window.PushAgent.subscribe) {
						Promise.resolve(window.PushAgent.subscribe()).then(function () {
							if (Notification.permission === 'granted') done(); else { btn.disabled = false; }
						});
					} else if (tries++ < 40) { setTimeout(go, 150); } else { btn.disabled = false; }
				})();
			});
		})();
		</script>
		<?php
	}

	/** WooCommerce "High-Performance Order Storage": this plugin only uses WooCommerce's order functions, so it's compatible. */
	public static function declare_wc_compatibility()
	{
		if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		}
	}

	/** Ask Push Agent whether the API key works and what it allows (cached for 10 minutes). */
	public static function api_status()
	{
		$s = self::settings();

		if ($s['api_key'] === '') {
			return array('state' => 'nokey');
		}

		$cached = get_transient('pushagent_ping');
		if (is_array($cached)) {
			return $cached;
		}

		$res = wp_remote_get(self::app_url($s['app_url']) . 'apiv1.php?r=ping', array(
			'timeout' => 10,
			'headers' => array('X-PushAgent-Key' => $s['api_key']),
		));

		if (is_wp_error($res)) {
			return array('state' => 'unreachable', 'error' => $res->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$data = json_decode((string) wp_remote_retrieve_body($res), true);

		if ($code === 401) {
			$out = array('state' => 'invalid');
		} elseif ($code === 200 && is_array($data)) {
			$out = array(
				'state'       => empty($data['api']) ? 'plan' : 'ok',
				'site'        => isset($data['site']['name']) ? (string) $data['site']['name'] : '',
				'plan'        => isset($data['plan']) ? (string) $data['plan'] : '',
				'paused'      => !empty($data['sending_paused']),
				'subscribers' => isset($data['subscribers']) ? (int) $data['subscribers'] : null,
				'account'     => isset($data['account']) && is_array($data['account']) ? $data['account'] : null,
				'tx'          => isset($data['order_updates_30d']) && is_array($data['order_updates_30d']) ? $data['order_updates_30d'] : null,
				'checked'     => time(),
			);
		} else {
			return array('state' => 'unreachable', 'error' => 'HTTP ' . $code);
		}

		set_transient('pushagent_ping', $out, 10 * MINUTE_IN_SECONDS);

		return $out;
	}

	/* ------------------------------------------------------------------ service worker file */

	/** The one-line service worker. Its logic is loaded from Push Agent, so future fixes need no re-upload. */
	public static function service_worker_content($token, $app_url)
	{
		return "// Push Agent service worker - created by the Push Agent WordPress plugin. Do not delete.\n"
			. '// ' . self::SW_MARKER . "\n"
			. "importScripts('" . $app_url . 'sw.php?t=' . rawurlencode($token) . "');\n";
	}

	/**
	 * Folder that is served as the root of the domain (where /pushagent-sw.js must live),
	 * or '' when the plugin shouldn't write there.
	 */
	public static function root_dir()
	{
		// On multisite every site shares one folder, so each site gets its file from serve_service_worker()
		if (is_multisite()) {
			return '';
		}

		$home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);

		if ($home_path === '' || $home_path === '/') {
			if (!function_exists('get_home_path')) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$dir = get_home_path();
		} else {
			// WordPress lives in a sub-folder (example.com/blog): the file belongs in the domain's root folder
			$dir = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
		}

		$dir = rtrim(str_replace('\\', '/', $dir), '/');

		return ($dir !== '' && is_dir($dir)) ? $dir : '';
	}

	public static function sw_path()
	{
		$dir = self::root_dir();

		return $dir === '' ? '' : $dir . '/' . self::SW_FILE;
	}

	/** Create or update pushagent-sw.js in the site root. Returns true when the file is correct afterwards. */
	public static function write_service_worker($token, $app_url, $force)
	{
		$file = self::sw_path();

		if ($file === '') {
			return false;
		}

		$content = self::service_worker_content($token, $app_url);

		if (is_file($file) && filesize($file) === strlen($content)) {
			if (!$force) {
				return true;
			}

			if (@file_get_contents($file) === $content) {
				return true;
			}
		}

		// Never overwrite a service worker that belongs to something else
		if (is_file($file) && strpos((string) @file_get_contents($file), 'importScripts') === false) {
			return false;
		}

		return @file_put_contents($file, $content, LOCK_EX) !== false;
	}

	/**
	 * Check what a browser gets at /pushagent-sw.js.
	 * Returns 'ok', 'outdated', 'missing' or 'unknown' (the site couldn't reach itself, e.g. on localhost).
	 */
	public static function check_service_worker($cached = false)
	{
		if ($cached) {
			$c = get_transient('pushagent_sw');
			if (is_string($c) && $c !== '') {
				return $c;
			}
			$c = self::check_service_worker(false);
			set_transient('pushagent_sw', $c, 5 * MINUTE_IN_SECONDS);

			return $c;
		}

		$url = set_url_scheme(home_url('/'), is_ssl() ? 'https' : null);
		$p   = parse_url($url);
		$url = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . self::SW_FILE;

		// No redirects: browsers refuse a service worker script that redirects
		$res = wp_remote_get(add_query_arg('pa', time(), $url), array('timeout' => 8, 'redirection' => 0, 'sslverify' => false));

		if (is_wp_error($res)) {
			return 'unknown';
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		if ($code !== 200) {
			return 'missing';
		}

		if (strpos($body, self::SW_MARKER) !== false) {
			return 'ok';
		}

		// A different script is there (e.g. from the old Push Agent); anything else (an HTML page) counts as missing
		return stripos((string) wp_remote_retrieve_header($res, 'content-type'), 'javascript') !== false ? 'outdated' : 'missing';
	}
}

PushAgent_Plugin::init();
