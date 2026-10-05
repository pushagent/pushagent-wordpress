<?php
/**
 * Push Agent admin screens: Dashboard, Settings, Order updates, Help.
 *
 * Kept PHP 5.6 compatible (no ??, no typed arguments, no arrow functions).
 */

if (!defined('ABSPATH')) {
	exit;
}

final class PushAgent_Admin
{
	const PAGE_DASHBOARD = 'pushagent';
	const PAGE_SETTINGS  = 'pushagent-settings';
	const PAGE_ORDERS    = 'pushagent-orders';
	const PAGE_HELP      = 'pushagent-help';

	/** Main plugin file (for plugins_url / plugin_basename). */
	private static $file = '';

	public static function init($file)
	{
		self::$file = $file;

		add_action('admin_menu', array(__CLASS__, 'menu'));
		add_action('admin_init', array(__CLASS__, 'admin_init'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
		add_action('admin_notices', array(__CLASS__, 'admin_notices'));
		add_filter('plugin_action_links_' . plugin_basename($file), array(__CLASS__, 'action_links'));
	}

	/* ------------------------------------------------------------------ setup */

	public static function url($page = self::PAGE_DASHBOARD, $args = array())
	{
		return add_query_arg($args, admin_url('admin.php?page=' . $page));
	}

	private static function pages()
	{
		$pages = array(
			self::PAGE_DASHBOARD => array(__('Dashboard', 'pushagent'), 'dashicons-dashboard'),
			self::PAGE_SETTINGS  => array(__('Settings', 'pushagent'), 'dashicons-admin-generic'),
		);

		if (PushAgent_Plugin::wc_active()) {
			$pages[self::PAGE_ORDERS] = array(__('Order updates', 'pushagent'), 'dashicons-cart');
		}

		$pages[self::PAGE_HELP] = array(__('Help', 'pushagent'), 'dashicons-sos');

		return $pages;
	}

	/** Bell icon for the admin menu; WordPress recolours it to match the admin colour scheme. */
	private static function menu_icon()
	{
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.6c-.7 0-1.2.5-1.2 1.2v.6C6.3 4 4.6 6.2 4.6 8.8v3.5L3 14.2v1h14v-1l-1.6-1.9V8.8c0-2.6-1.7-4.8-4.2-5.4v-.6c0-.7-.5-1.2-1.2-1.2zM7.9 16.2a2.1 2.1 0 0 0 4.2 0z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	public static function menu()
	{
		$cb = array(__CLASS__, 'render');

		add_menu_page(__('Push Agent', 'pushagent'), __('Push Agent', 'pushagent'), 'manage_options', self::PAGE_DASHBOARD, $cb, self::menu_icon(), 58.7);

		foreach (self::pages() as $slug => $p) {
			add_submenu_page(self::PAGE_DASHBOARD, $p[0] . ' ‹ ' . __('Push Agent', 'pushagent'), $p[0], 'manage_options', $slug, $cb);
		}
	}

	public static function admin_init()
	{
		register_setting(PushAgent_Plugin::PAGE, PushAgent_Plugin::OPTION, array('PushAgent_Plugin', 'sanitize'));

		global $pagenow;

		// Version 2.1 lived under Settings → Push Agent: send old bookmarks to the new place
		if ($pagenow === 'options-general.php' && isset($_GET['page']) && $_GET['page'] === PushAgent_Plugin::PAGE) { // phpcs:ignore WordPress.Security.NonceVerification
			$tab = isset($_GET['tab']) && $_GET['tab'] === 'woocommerce' ? self::PAGE_ORDERS : self::PAGE_SETTINGS; // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect(self::url($tab));
			exit;
		}

		// "Check again" button: forget cached checks
		if (isset($_GET['pa_refresh'], $_GET['page']) && strpos((string) $_GET['page'], 'pushagent') === 0 && current_user_can('manage_options')) { // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer('pushagent_refresh');
			PushAgent_Plugin::clear_cache();
			wp_safe_redirect(remove_query_arg(array('pa_refresh', '_wpnonce')));
			exit;
		}
	}

	private static function current_page()
	{
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		return array_key_exists($page, self::pages()) ? $page : '';
	}

	public static function assets()
	{
		if (self::current_page() === '') {
			return;
		}

		wp_enqueue_style('pushagent-admin', plugins_url('assets/admin.css', self::$file), array(), PushAgent_Plugin::VERSION);
	}

	public static function action_links($links)
	{
		array_unshift(
			$links,
			'<a href="' . esc_url(self::url(self::PAGE_SETTINGS)) . '">' . esc_html__('Settings', 'pushagent') . '</a>',
			'<a href="' . esc_url(self::url()) . '">' . esc_html__('Dashboard', 'pushagent') . '</a>'
		);

		return $links;
	}

	/** Reminder on the WordPress Dashboard and Plugins screens until a token has been entered. */
	public static function admin_notices()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (!$screen || !in_array($screen->id, array('dashboard', 'plugins'), true)) {
			return;
		}

		$s = PushAgent_Plugin::settings();

		if ($s['token'] !== '') {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Push Agent', 'pushagent') . ':</strong> '
			. esc_html__('paste your access token to start collecting subscribers.', 'pushagent')
			. ' <a href="' . esc_url(self::url(self::PAGE_SETTINGS)) . '">' . esc_html__('Open settings', 'pushagent') . '</a></p></div>';
	}

	/* ------------------------------------------------------------------ layout */

	public static function render()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$page    = self::current_page();
		$page    = $page === '' ? self::PAGE_DASHBOARD : $page;
		$pages   = self::pages();
		$s       = PushAgent_Plugin::settings();
		$app_url = PushAgent_Plugin::app_url($s['app_url']);
		$saved   = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'; // phpcs:ignore WordPress.Security.NonceVerification

		// Just saved (or first visit): create / refresh the service worker file straight away
		$file_ok = null;
		if ($s['token'] !== '' && $s['create_sw']) {
			$file_ok = PushAgent_Plugin::write_service_worker($s['token'], $app_url, $saved);
		}

		$ctx = array(
			's'       => $s,
			'app_url' => $app_url,
			'saved'   => $saved,
			'file_ok' => $file_ok,
		);
		?>
		<div class="wrap pa-wrap">
			<h1 class="pa-sr"><?php echo esc_html($pages[$page][0] . ' ‹ ' . __('Push Agent', 'pushagent')); ?></h1>

			<header class="pa-header">
				<a class="pa-brand" href="<?php echo esc_url(self::url()); ?>">
					<img src="<?php echo esc_url(plugins_url('assets/logo.png', self::$file)); ?>" alt="<?php esc_attr_e('Push Agent', 'pushagent'); ?>" width="120" height="40">
				</a>
				<span class="pa-version">v<?php echo esc_html(PushAgent_Plugin::VERSION); ?></span>
				<span class="pa-header-gap"></span>
				<a class="pa-btn pa-btn-ghost" href="<?php echo esc_url($app_url . '#/send'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-megaphone"></span><?php esc_html_e('Send a notification', 'pushagent'); ?></a>
				<a class="pa-btn pa-btn-primary" href="<?php echo esc_url($app_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open Push Agent', 'pushagent'); ?><span class="dashicons dashicons-external"></span></a>
			</header>

			<div class="pa-layout">
				<nav class="pa-nav" aria-label="<?php esc_attr_e('Push Agent', 'pushagent'); ?>">
					<?php foreach ($pages as $slug => $p) : ?>
						<a href="<?php echo esc_url(self::url($slug)); ?>" class="pa-nav-item<?php echo $slug === $page ? ' is-active' : ''; ?>"<?php echo $slug === $page ? ' aria-current="page"' : ''; ?>>
							<span class="dashicons <?php echo esc_attr($p[1]); ?>"></span><?php echo esc_html($p[0]); ?>
						</a>
					<?php endforeach; ?>

					<?php self::nav_plan_box($app_url); ?>
				</nav>

				<main class="pa-main">
					<?php if ($saved) : ?>
						<div class="pa-alert pa-alert-success pa-saved" role="status"><span class="dashicons dashicons-yes-alt"></span><div><?php esc_html_e('Settings saved.', 'pushagent'); ?></div></div>
					<?php endif; ?>
					<?php
					switch ($page) {
						case self::PAGE_SETTINGS:
							self::page_settings($ctx);
							break;
						case self::PAGE_ORDERS:
							self::page_orders($ctx);
							break;
						case self::PAGE_HELP:
							self::page_help($ctx);
							break;
						default:
							self::page_dashboard($ctx);
					}
					?>
				</main>
			</div>
		</div>
		<?php
	}

	private static function page_title($title, $sub, $action = '')
	{
		echo '<div class="pa-page-head"><div><h2 class="pa-title">' . esc_html($title) . '</h2>';
		if ($sub !== '') {
			echo '<p class="pa-sub">' . wp_kses($sub, array('a' => array('href' => array(), 'target' => array(), 'rel' => array()), 'strong' => array(), 'em' => array())) . '</p>';
		}
		echo '</div>' . $action . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $action is built from escaped parts
	}

	private static function refresh_button()
	{
		$url = wp_nonce_url(add_query_arg('pa_refresh', 1), 'pushagent_refresh');

		return '<a class="pa-btn pa-btn-ghost pa-btn-sm" href="' . esc_url($url) . '"><span class="dashicons dashicons-update"></span>' . esc_html__('Check again', 'pushagent') . '</a>';
	}

	/** Small "your plan" box under the side navigation. */
	private static function nav_plan_box($app_url)
	{
		$s = PushAgent_Plugin::settings();

		if ($s['api_key'] === '') {
			return;
		}

		$api = PushAgent_Plugin::api_status();

		if (!in_array($api['state'], array('ok', 'plan'), true) || empty($api['plan'])) {
			return;
		}
		?>
		<div class="pa-nav-plan">
			<div class="pa-nav-plan-label"><?php esc_html_e('Your plan', 'pushagent'); ?></div>
			<div class="pa-nav-plan-name"><?php echo esc_html($api['plan']); ?></div>
			<?php if (!empty($api['account']['limit'])) :
				$used = (int) $api['account']['subscribers'];
				$lim  = (int) $api['account']['limit'];
				?>
				<div class="pa-meter" title="<?php esc_attr_e('Subscribers used on your account', 'pushagent'); ?>"><span style="width:<?php echo esc_attr(min(100, round($used * 100 / max(1, $lim)))); ?>%"></span></div>
				<div class="pa-nav-plan-use"><?php echo esc_html(sprintf(__('%1$s of %2$s subscribers', 'pushagent'), number_format_i18n($used), number_format_i18n($lim))); ?></div>
			<?php endif; ?>
			<?php if ($api['state'] === 'plan' || in_array(strtolower($api['plan']), array('free', 'starter'), true)) : ?>
				<a class="pa-btn pa-btn-primary pa-btn-sm pa-btn-block" href="<?php echo esc_url($app_url . 'upgrade.php?plan=growth&period=monthly'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Upgrade to Growth', 'pushagent'); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ building blocks */

	private static function alert($type, $html, $icon = '')
	{
		$icons = array('success' => 'yes-alt', 'error' => 'warning', 'warning' => 'flag', 'info' => 'info-outline');
		$icon  = $icon !== '' ? $icon : $icons[$type];

		echo '<div class="pa-alert pa-alert-' . esc_attr($type) . '"><span class="dashicons dashicons-' . esc_attr($icon) . '"></span><div>'
			. wp_kses($html, array('a' => array('href' => array(), 'target' => array(), 'rel' => array()), 'strong' => array(), 'code' => array(), 'em' => array(), 'br' => array()))
			. '</div></div>';
	}

	private static function badge($type, $text)
	{
		return '<span class="pa-badge pa-badge-' . esc_attr($type) . '">' . esc_html($text) . '</span>';
	}

	/** What the service-worker check means, as [state, short text, explanation]. */
	private static function sw_state($status, $file_ok, $create_sw)
	{
		$home = (string) parse_url(home_url('/'), PHP_URL_PATH);
		$sw   = '<code>/' . PushAgent_Plugin::SW_FILE . '</code>';
		$root = PushAgent_Plugin::root_dir();

		if ($status === 'ok') {
			return array('success', __('In place', 'pushagent'), sprintf(__('%s is reachable on your site.', 'pushagent'), $sw));
		}
		if ($status === 'unknown') {
			return array($file_ok || is_multisite() ? 'info' : 'warning', __('Not checked', 'pushagent'), __('This site couldn\'t check its own address (normal on localhost and some hosts). Click <strong>Verify integration</strong> in your Push Agent dashboard to confirm.', 'pushagent'));
		}
		if ($status === 'outdated') {
			return array('error', __('Old file', 'pushagent'), sprintf(__('Your site already has an old %s that the plugin could not replace. Delete it from your site\'s root folder, then save the settings again.', 'pushagent'), $sw));
		}

		if (!$create_sw) {
			$msg = sprintf(__('%s was not found on your site. Download it from your Push Agent dashboard (Integration page) and upload it to your site\'s root folder, or switch on “Create pushagent-sw.js automatically”.', 'pushagent'), $sw);
		} elseif ($home !== '' && $home !== '/' && !$file_ok) {
			$msg = sprintf(__('WordPress is in a sub-folder, and %s must be at the top of your domain. Download pushagent-sw.js from your Push Agent dashboard (Integration page) and upload it to your domain\'s root folder.', 'pushagent'), $sw);
		} elseif ($root !== '' && !$file_ok) {
			$msg = sprintf(__('Push Agent could not create %1$s in %2$s (the folder is not writable). Download pushagent-sw.js from your Push Agent dashboard (Integration page) and upload it to that folder.', 'pushagent'), $sw, '<code>' . esc_html($root) . '</code>');
		} else {
			$msg = sprintf(__('%s can\'t be opened on your site. If you use a caching plugin or CDN, clear its cache. Otherwise download pushagent-sw.js from your Push Agent dashboard and upload it to your site\'s root folder.', 'pushagent'), $sw);
		}

		return array('error', __('Missing', 'pushagent'), $msg);
	}

	/** What the API-key check means, as [state, short text, explanation]. */
	private static function api_state($api)
	{
		$settings = esc_url(self::url(self::PAGE_SETTINGS));

		switch ($api['state']) {
			case 'ok':
				$txt = sprintf(__('Connected to <strong>%1$s</strong> on the %2$s plan.', 'pushagent'), esc_html($api['site']), esc_html($api['plan']));
				if (!empty($api['paused'])) {
					return array('error', __('Sending paused', 'pushagent'), $txt . ' ' . __('Sending is paused because your account is over its subscriber limit. Upgrade your plan to send again.', 'pushagent'));
				}
				return array('success', __('Connected', 'pushagent'), $txt);
			case 'plan':
				return array('warning', __('Upgrade needed', 'pushagent'), sprintf(__('Connected to <strong>%1$s</strong> on the %2$s plan. Order updates need the Growth plan or higher.', 'pushagent'), esc_html($api['site']), esc_html($api['plan'])));
			case 'invalid':
				return array('error', __('Key not accepted', 'pushagent'), sprintf(__('Push Agent did not accept your API key. It may have been revoked: create a new one and paste it in <a href="%s">Settings</a>.', 'pushagent'), $settings));
			case 'unreachable':
				return array('warning', __('Unreachable', 'pushagent'), sprintf(__('Could not reach Push Agent (%s).', 'pushagent'), esc_html(isset($api['error']) ? $api['error'] : '')));
			default:
				return array('neutral', __('No API key', 'pushagent'), sprintf(__('Add an API key in <a href="%s">Settings</a> to see your subscribers here and to send WooCommerce order updates.', 'pushagent'), $settings));
		}
	}

	/* ------------------------------------------------------------------ Dashboard */

	private static function page_dashboard($ctx)
	{
		$s       = $ctx['s'];
		$app_url = $ctx['app_url'];
		$wc      = PushAgent_Plugin::wc_active();
		$api     = PushAgent_Plugin::api_status();
		$sw      = $s['token'] !== '' ? PushAgent_Plugin::check_service_worker(true) : '';
		$on      = is_array($s['wc_on']) ? count($s['wc_on']) : 0;

		$sw_state  = $s['token'] !== '' ? self::sw_state($sw, $ctx['file_ok'], $s['create_sw']) : array('neutral', __('Waiting for token', 'pushagent'), '');
		$api_state = self::api_state($api);
		$connected = in_array($api['state'], array('ok', 'plan'), true);

		self::page_title(
			__('Dashboard', 'pushagent'),
			$s['token'] === '' ? __('Welcome to Push Agent! Connect your site in a minute and start collecting subscribers.', 'pushagent') : __('How Push Agent is doing on your site.', 'pushagent'),
			$s['token'] !== '' ? self::refresh_button() : ''
		);

		if ($s['token'] === '') {
			self::welcome($app_url);
		}

		// ---------------------------------------------------------------- stats
		?>
		<div class="pa-stats">
			<?php
			self::stat(
				__('Subscribers', 'pushagent'),
				$connected && $api['subscribers'] !== null ? number_format_i18n($api['subscribers']) : '—',
				$connected ? __('on this website', 'pushagent') : __('add an API key to see this', 'pushagent'),
				'groups'
			);
			self::stat(
				__('Plan', 'pushagent'),
				$connected && $api['plan'] !== '' ? $api['plan'] : '—',
				$connected && !empty($api['account']['limit']) ? sprintf(__('%1$s of %2$s subscribers used', 'pushagent'), number_format_i18n((int) $api['account']['subscribers']), number_format_i18n((int) $api['account']['limit'])) : __('your Push Agent plan', 'pushagent'),
				'awards'
			);
			if ($wc) {
				self::stat(
					__('Order updates', 'pushagent'),
					$connected && !empty($api['tx']) ? number_format_i18n((int) $api['tx']['delivered']) : '—',
					__('delivered in the last 30 days', 'pushagent'),
					'cart'
				);
			} else {
				self::stat(__('Opt-in delay', 'pushagent'), sprintf(_n('%d second', '%d seconds', (int) $s['delay'], 'pushagent'), (int) $s['delay']), __('before visitors are asked', 'pushagent'), 'clock');
			}
			$ready = $s['token'] !== '' && in_array($sw_state[0], array('success', 'info'), true);
			self::stat(
				__('Status', 'pushagent'),
				$s['token'] === '' ? __('Not set up', 'pushagent') : ($ready ? __('Live', 'pushagent') : __('Needs attention', 'pushagent')),
				$ready ? __('collecting subscribers', 'pushagent') : __('see the checks below', 'pushagent'),
				$ready ? 'yes-alt' : 'warning',
				$s['token'] === '' ? 'neutral' : ($ready ? 'success' : 'error')
			);
			?>
		</div>

		<div class="pa-grid">
			<div class="pa-col">
				<?php self::checklist($ctx, $sw_state, $api); ?>
				<?php if ($wc) {
					self::recent_updates($s, $on);
				} ?>
			</div>
			<div class="pa-col">
				<section class="pa-card">
					<header class="pa-card-head"><h3><?php esc_html_e('Health check', 'pushagent'); ?></h3></header>
					<ul class="pa-health">
						<?php
						self::health_row(
							__('Access token', 'pushagent'),
							$s['token'] !== '' ? array('success', __('Added', 'pushagent'), __('The Push Agent script runs on every page of your site.', 'pushagent')) : array('error', __('Missing', 'pushagent'), sprintf(__('Paste it in <a href="%s">Settings</a>.', 'pushagent'), esc_url(self::url(self::PAGE_SETTINGS))))
						);
						self::health_row(__('Service worker', 'pushagent'), $sw_state);
						self::health_row(__('Push Agent account', 'pushagent'), $api_state);
						if ($wc) {
							self::health_row(
								__('WooCommerce', 'pushagent'),
								$on ? array('success', sprintf(_n('%d status on', '%d statuses on', $on, 'pushagent'), $on), $s['wc_optin'] ? __('Customers are asked on the order confirmation page.', 'pushagent') : __('The “Get updates about this order” box is switched off.', 'pushagent'))
									: array('neutral', __('Off', 'pushagent'), sprintf(__('Switch on the statuses you want to notify customers about in <a href="%s">Order updates</a>.', 'pushagent'), esc_url(self::url(self::PAGE_ORDERS))))
							);
						}
						?>
					</ul>
				</section>

				<section class="pa-card">
					<header class="pa-card-head"><h3><?php esc_html_e('Quick actions', 'pushagent'); ?></h3></header>
					<div class="pa-actions">
						<?php
						self::action_tile($app_url . '#/send', 'megaphone', __('Send a notification', 'pushagent'), __('To all or some of your subscribers', 'pushagent'));
						self::action_tile($app_url . '#/campaigns', 'chart-bar', __('Campaign results', 'pushagent'), __('Delivered, clicked, click rate', 'pushagent'));
						self::action_tile($app_url . '#/subscribers', 'groups', __('Subscribers', 'pushagent'), __('Countries, browsers and devices', 'pushagent'));
						self::action_tile($app_url . '#/integration', 'admin-links', __('Verify integration', 'pushagent'), __('Check the setup from Push Agent', 'pushagent'));
						?>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	private static function welcome($app_url)
	{
		?>
		<section class="pa-card pa-welcome">
			<div class="pa-welcome-art"><img src="<?php echo esc_url(plugins_url('assets/icon.png', self::$file)); ?>" alt="" width="88" height="88"></div>
			<div class="pa-welcome-body">
				<h3><?php esc_html_e('Connect your site in three steps', 'pushagent'); ?></h3>
				<ol class="pa-steps">
					<li><?php printf(esc_html__('Sign in (or sign up for free) at %s and add your website.', 'pushagent'), '<a href="' . esc_url($app_url) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('~^https?://|/$~', '', $app_url)) . '</a>'); ?></li>
					<li><?php echo wp_kses(__('Open <em>Integration</em> and copy your <strong>access token</strong>.', 'pushagent'), array('em' => array(), 'strong' => array())); ?></li>
					<li><?php esc_html_e('Paste it in Settings and save. Push Agent creates everything else for you.', 'pushagent'); ?></li>
				</ol>
				<a class="pa-btn pa-btn-primary" href="<?php echo esc_url(self::url(self::PAGE_SETTINGS)); ?>"><?php esc_html_e('Paste your access token', 'pushagent'); ?></a>
				<a class="pa-btn pa-btn-ghost" href="<?php echo esc_url($app_url . '#/signup'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Create a free account', 'pushagent'); ?></a>
			</div>
		</section>
		<?php
	}

	private static function stat($label, $value, $hint, $icon, $tone = '')
	{
		?>
		<div class="pa-stat<?php echo $tone !== '' ? ' pa-stat-' . esc_attr($tone) : ''; ?>">
			<span class="pa-stat-icon dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
			<div class="pa-stat-label"><?php echo esc_html($label); ?></div>
			<div class="pa-stat-value"><?php echo esc_html($value); ?></div>
			<div class="pa-stat-hint"><?php echo esc_html($hint); ?></div>
		</div>
		<?php
	}

	private static function health_row($label, $state)
	{
		list($type, $short, $text) = $state;
		$icons = array('success' => 'yes-alt', 'info' => 'info-outline', 'warning' => 'flag', 'error' => 'warning', 'neutral' => 'minus');
		?>
		<li class="pa-health-row pa-tone-<?php echo esc_attr($type); ?>">
			<span class="dashicons dashicons-<?php echo esc_attr($icons[$type]); ?>"></span>
			<div class="pa-health-text">
				<div class="pa-health-label"><?php echo esc_html($label); ?> <?php echo self::badge($type, $short); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php if ($text !== '') : ?>
					<div class="pa-health-desc"><?php echo wp_kses($text, array('a' => array('href' => array()), 'strong' => array(), 'code' => array())); ?></div>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	private static function action_tile($url, $icon, $title, $text)
	{
		?>
		<a class="pa-action" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
			<span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
			<strong><?php echo esc_html($title); ?></strong>
			<small><?php echo esc_html($text); ?></small>
		</a>
		<?php
	}

	/** Setup checklist with a progress bar ("task list"). */
	private static function checklist($ctx, $sw_state, $api)
	{
		$s       = $ctx['s'];
		$app_url = $ctx['app_url'];
		$wc      = PushAgent_Plugin::wc_active();
		$set     = self::url(self::PAGE_SETTINGS);
		$orders  = self::url(self::PAGE_ORDERS);

		$tasks   = array();
		$tasks[] = array($s['token'] !== '', __('Connect your site', 'pushagent'), __('Paste the access token from your Push Agent dashboard.', 'pushagent'), $set, __('Add token', 'pushagent'));
		$tasks[] = array($s['token'] !== '' && in_array($sw_state[0], array('success', 'info'), true), __('Service worker in place', 'pushagent'), __('The small pushagent-sw.js file browsers need to show notifications.', 'pushagent'), $set, __('Fix', 'pushagent'));
		$tasks[] = array($s['token'] !== '' && $sw_state[0] === 'success', __('Verify the integration', 'pushagent'), __('Open Integration in Push Agent and click Verify integration.', 'pushagent'), $app_url . '#/integration', __('Verify', 'pushagent'), true);
		$tasks[] = array(in_array($api['state'], array('ok', 'plan'), true), __('Add your API key', 'pushagent'), __('Shows your subscribers and plan here, and powers order updates.', 'pushagent'), $set, __('Add key', 'pushagent'));

		if ($wc) {
			$titled = $s['wc_title_all'] !== '';
			if (!$titled && !empty($s['wc_on'])) {
				$titled = true;
				foreach (array_keys($s['wc_on']) as $slug) {
					if (empty($s['wc_title'][$slug])) {
						$titled = false;
					}
				}
			}
			$tasks[] = array($api['state'] === 'ok', __('Growth plan for order updates', 'pushagent'), __('WooCommerce order updates are part of Push Agent Growth.', 'pushagent'), $app_url . 'upgrade.php?plan=growth&period=monthly', __('Upgrade', 'pushagent'), true);
			$tasks[] = array(!empty($s['wc_on']), __('Choose order statuses', 'pushagent'), __('Pick which status changes send your customers a notification.', 'pushagent'), $orders, __('Choose', 'pushagent'));
			$tasks[] = array(!empty($s['wc_on']) && $titled, __('Write your messages', 'pushagent'), __('A default message, or one per status, in your own words.', 'pushagent'), $orders, __('Write', 'pushagent'));
		}

		$done  = 0;
		foreach ($tasks as $t) {
			$done += $t[0] ? 1 : 0;
		}
		$total = count($tasks);
		$pct   = (int) round($done * 100 / $total);
		?>
		<section class="pa-card">
			<header class="pa-card-head">
				<h3><?php esc_html_e('Setup checklist', 'pushagent'); ?></h3>
				<span class="pa-muted"><?php echo esc_html(sprintf(__('%1$d of %2$d done', 'pushagent'), $done, $total)); ?></span>
			</header>
			<div class="pa-progress" role="progressbar" aria-valuenow="<?php echo esc_attr($pct); ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo esc_attr($pct); ?>%"></span></div>
			<?php if ($done === $total) : ?>
				<p class="pa-allset"><span class="dashicons dashicons-awards"></span> <?php esc_html_e('All set! Push Agent is fully set up on this site.', 'pushagent'); ?></p>
			<?php endif; ?>
			<ul class="pa-tasks">
				<?php foreach ($tasks as $t) :
					$external = !empty($t[5]);
					?>
					<li class="pa-task<?php echo $t[0] ? ' is-done' : ''; ?>">
						<span class="pa-check"><span class="dashicons dashicons-<?php echo $t[0] ? 'yes' : 'marker'; ?>"></span></span>
						<div class="pa-task-text"><strong><?php echo esc_html($t[1]); ?></strong><small><?php echo esc_html($t[2]); ?></small></div>
						<?php if (!$t[0]) : ?>
							<a class="pa-btn pa-btn-ghost pa-btn-sm" href="<?php echo esc_url($t[3]); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($t[4]); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function recent_updates($s, $on)
	{
		$log = get_option(PushAgent_Plugin::LOG_OPTION, array());
		$log = is_array($log) ? array_slice($log, 0, 8) : array();
		$res = array(
			'sent'     => array('success', __('Sent', 'pushagent')),
			'nodevice' => array('neutral', __('Not subscribed', 'pushagent')),
			'skipped'  => array('warning', __('No message', 'pushagent')),
			'failed'   => array('error', __('Failed', 'pushagent')),
		);
		?>
		<section class="pa-card">
			<header class="pa-card-head">
				<h3><?php esc_html_e('Recent order updates', 'pushagent'); ?></h3>
				<a class="pa-link" href="<?php echo esc_url(self::url(self::PAGE_ORDERS)); ?>"><?php esc_html_e('Settings', 'pushagent'); ?> &rarr;</a>
			</header>
			<?php if (!$log) : ?>
				<div class="pa-empty">
					<span class="dashicons dashicons-cart"></span>
					<p><?php echo $on ? esc_html__('No order updates yet. They appear here when you change an order to a status that is switched on.', 'pushagent') : esc_html__('Order updates are off. Switch on the statuses you want to notify customers about.', 'pushagent'); ?></p>
				</div>
			<?php else : ?>
				<table class="pa-table">
					<thead><tr><th><?php esc_html_e('Order', 'pushagent'); ?></th><th><?php esc_html_e('Status', 'pushagent'); ?></th><th><?php esc_html_e('Result', 'pushagent'); ?></th><th><?php esc_html_e('When', 'pushagent'); ?></th></tr></thead>
					<tbody>
					<?php foreach ($log as $r) :
						$r = array_merge(array('t' => 0, 'order' => '', 'edit' => '', 'name' => '', 'status' => '', 'result' => 'failed', 'detail' => '', 'devices' => 0), (array) $r);
						$b = isset($res[$r['result']]) ? $res[$r['result']] : $res['failed'];
						?>
						<tr>
							<td>
								<?php if ($r['edit'] !== '') : ?><a href="<?php echo esc_url($r['edit']); ?>">#<?php echo esc_html($r['order']); ?></a><?php else : ?>#<?php echo esc_html($r['order']); ?><?php endif; ?>
								<?php if ($r['name'] !== '') : ?><small class="pa-muted"><?php echo esc_html($r['name']); ?></small><?php endif; ?>
							</td>
							<td><?php echo esc_html($r['status']); ?></td>
							<td><span title="<?php echo esc_attr($r['detail']); ?>"><?php echo self::badge($b[0], $b[1]); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></td>
							<td class="pa-muted"><?php echo esc_html(sprintf(__('%s ago', 'pushagent'), human_time_diff((int) $r['t']))); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------ Settings */

	private static function page_settings($ctx)
	{
		$s       = $ctx['s'];
		$app_url = $ctx['app_url'];
		$o       = PushAgent_Plugin::OPTION;

		self::page_title(__('Settings', 'pushagent'), __('Connect this site to your Push Agent account and choose when visitors are asked.', 'pushagent'), $s['token'] !== '' ? self::refresh_button() : '');

		if ($s['token'] !== '') {
			$st = self::sw_state(PushAgent_Plugin::check_service_worker(true), $ctx['file_ok'], $s['create_sw']);
			if ($st[0] === 'success') {
				self::alert('success', __('Push Agent is set up. The script is on every page and the service worker is in place.', 'pushagent'));
			} else {
				self::alert($st[0], $st[2]);
			}
		}
		?>
		<form method="post" action="options.php" class="pa-form">
			<?php settings_fields(PushAgent_Plugin::PAGE); ?>
			<input type="hidden" name="<?php echo esc_attr($o); ?>[_tab]" value="general">

			<section class="pa-card">
				<header class="pa-card-head"><h3><span class="dashicons dashicons-admin-links"></span><?php esc_html_e('Connection', 'pushagent'); ?></h3></header>

				<div class="pa-field">
					<label for="pa-token"><?php esc_html_e('Access token', 'pushagent'); ?></label>
					<input name="<?php echo esc_attr($o); ?>[token]" id="pa-token" type="text" class="pa-input code" value="<?php echo esc_attr($s['token']); ?>" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX" autocomplete="off" spellcheck="false">
					<p class="pa-help"><?php printf(esc_html__('In %s open Integration and copy the access token.', 'pushagent'), '<a href="' . esc_url($app_url . '#/integration') . '" target="_blank" rel="noopener">' . esc_html__('your Push Agent dashboard', 'pushagent') . '</a>'); ?></p>
				</div>

				<div class="pa-field">
					<label for="pa-key"><?php esc_html_e('API key', 'pushagent'); ?> <?php echo self::badge('neutral', __('Optional', 'pushagent')); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
					<div class="pa-input-row">
						<input name="<?php echo esc_attr($o); ?>[api_key]" id="pa-key" type="password" class="pa-input code" value="<?php echo esc_attr($s['api_key']); ?>" placeholder="pak_…" autocomplete="off" spellcheck="false">
						<button type="button" class="pa-btn pa-btn-ghost pa-btn-sm" onclick="var i=document.getElementById('pa-key');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?<?php echo esc_attr(wp_json_encode(__('Show', 'pushagent'))); ?>:<?php echo esc_attr(wp_json_encode(__('Hide', 'pushagent'))); ?>;"><?php esc_html_e('Show', 'pushagent'); ?></button>
					</div>
					<p class="pa-help"><?php printf(esc_html__('Shows your subscribers and plan on the plugin dashboard and sends WooCommerce order updates (Growth plan). Create one in %s.', 'pushagent'), '<a href="' . esc_url($app_url . '#/developers') . '" target="_blank" rel="noopener">' . esc_html__('API & WooCommerce', 'pushagent') . '</a>'); ?></p>
				</div>
			</section>

			<section class="pa-card">
				<header class="pa-card-head"><h3><span class="dashicons dashicons-format-chat"></span><?php esc_html_e('Asking visitors', 'pushagent'); ?></h3></header>
				<div class="pa-field">
					<label for="pa-delay"><?php esc_html_e('Ask visitors after', 'pushagent'); ?></label>
					<div class="pa-input-row pa-input-row-left">
						<input name="<?php echo esc_attr($o); ?>[delay]" id="pa-delay" type="number" min="0" max="120" step="1" class="pa-input pa-input-sm" value="<?php echo (int) $s['delay']; ?>">
						<span><?php esc_html_e('seconds', 'pushagent'); ?></span>
					</div>
					<p class="pa-help"><?php esc_html_e('How long after a page loads the “Allow notifications” card appears for new visitors. The card\'s text is set in your Push Agent dashboard (Websites → Opt-in message).', 'pushagent'); ?></p>
				</div>
			</section>

			<section class="pa-card">
				<header class="pa-card-head"><h3><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e('Advanced', 'pushagent'); ?></h3></header>
				<div class="pa-field">
					<label class="pa-switch">
						<input name="<?php echo esc_attr($o); ?>[create_sw]" type="checkbox" value="1" <?php checked($s['create_sw'], 1); ?>>
						<span class="pa-switch-ui" aria-hidden="true"></span>
						<span><?php esc_html_e('Create pushagent-sw.js automatically', 'pushagent'); ?></span>
					</label>
					<p class="pa-help"><?php esc_html_e('The plugin puts the small service worker file in your site\'s root folder. Switch off only if you upload pushagent-sw.js yourself.', 'pushagent'); ?></p>
				</div>
			</section>

			<div class="pa-savebar"><?php submit_button(__('Save settings', 'pushagent'), 'primary pa-btn pa-btn-primary', 'submit', false); ?></div>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ Order updates */

	private static function page_orders($ctx)
	{
		$s        = $ctx['s'];
		$o        = PushAgent_Plugin::OPTION;
		$statuses = wc_get_order_statuses();      // includes custom statuses registered with WooCommerce
		$api      = PushAgent_Plugin::api_status();
		$st       = self::api_state($api);

		self::page_title(
			__('WooCommerce order updates', 'pushagent'),
			__('Customers who allow notifications get one when you change their order to a status you switch on. Clicking it opens their order.', 'pushagent'),
			self::refresh_button()
		);

		if ($st[0] === 'success') {
			self::alert('success', $st[2] . ' ' . __('Order updates are sent for the statuses switched on below.', 'pushagent'));
		} elseif ($api['state'] === 'nokey') {
			self::alert('warning', sprintf(__('Add your Push Agent API key in <a href="%s">Settings</a> first. Create it in your Push Agent dashboard under “API & WooCommerce”.', 'pushagent'), esc_url(self::url(self::PAGE_SETTINGS))));
		} else {
			self::alert($st[0] === 'neutral' ? 'info' : $st[0], $st[2]);
		}

		$sample = array(
			'{first_name}'   => 'Sarah',
			'{last_name}'    => 'Khan',
			'{order_number}' => '1045',
			'{status}'       => __('Completed', 'pushagent'),
			'{total}'        => function_exists('wc_price') ? html_entity_decode(wp_strip_all_tags(wc_price(49.90)), ENT_QUOTES, 'UTF-8') : '49.90',
			'{site_name}'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
		);
		?>
		<form method="post" action="options.php" class="pa-form" id="pa-orders-form">
			<?php settings_fields(PushAgent_Plugin::PAGE); ?>
			<input type="hidden" name="<?php echo esc_attr($o); ?>[_tab]" value="woocommerce">

			<section class="pa-card">
				<header class="pa-card-head"><h3><span class="dashicons dashicons-edit"></span><?php esc_html_e('Default message', 'pushagent'); ?></h3></header>
				<div class="pa-split">
					<div>
						<div class="pa-field">
							<label for="pa-t-all"><?php esc_html_e('Title', 'pushagent'); ?></label>
							<input name="<?php echo esc_attr($o); ?>[wc_title_all]" id="pa-t-all" type="text" class="pa-input" maxlength="120" value="<?php echo esc_attr($s['wc_title_all']); ?>" placeholder="<?php esc_attr_e('e.g. Order #{order_number} is now {status}', 'pushagent'); ?>">
						</div>
						<div class="pa-field">
							<label for="pa-b-all"><?php esc_html_e('Message', 'pushagent'); ?></label>
							<textarea name="<?php echo esc_attr($o); ?>[wc_body_all]" id="pa-b-all" class="pa-input" rows="3" maxlength="255" placeholder="<?php esc_attr_e('e.g. Hi {first_name}, tap to see your order.', 'pushagent'); ?>"><?php echo esc_textarea($s['wc_body_all']); ?></textarea>
							<p class="pa-help"><?php esc_html_e('Used for every switched-on status that has no message of its own.', 'pushagent'); ?></p>
						</div>
						<div class="pa-chips" aria-label="<?php esc_attr_e('Placeholders', 'pushagent'); ?>">
							<span class="pa-muted"><?php esc_html_e('Placeholders:', 'pushagent'); ?></span>
							<?php foreach (array_keys($sample) as $ph) : ?>
								<button type="button" class="pa-chip" data-ph="<?php echo esc_attr($ph); ?>"><?php echo esc_html($ph); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
					<div>
						<div class="pa-preview-label"><?php esc_html_e('Preview', 'pushagent'); ?></div>
						<div class="pa-notif">
							<img src="<?php echo esc_url(get_site_icon_url(64) ? get_site_icon_url(64) : plugins_url('assets/icon.png', self::$file)); ?>" alt="" width="40" height="40">
							<div>
								<div class="pa-notif-title" id="pa-pv-title"></div>
								<div class="pa-notif-body" id="pa-pv-body"></div>
								<div class="pa-notif-site"><?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?></div>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="pa-card">
				<header class="pa-card-head">
					<h3><span class="dashicons dashicons-list-view"></span><?php esc_html_e('Order statuses', 'pushagent'); ?></h3>
					<span class="pa-muted"><?php esc_html_e('Custom statuses from other plugins appear here automatically.', 'pushagent'); ?></span>
				</header>
				<div class="pa-status-list">
					<?php foreach ($statuses as $key => $label) :
						$slug = sanitize_key(preg_replace('/^wc-/', '', (string) $key));
						$on   = !empty($s['wc_on'][$slug]);
						?>
						<div class="pa-status<?php echo $on ? ' is-on' : ''; ?>">
							<div class="pa-status-head">
								<label class="pa-switch">
									<input type="checkbox" name="<?php echo esc_attr($o); ?>[wc_on][<?php echo esc_attr($slug); ?>]" value="1" <?php checked($on); ?> aria-label="<?php echo esc_attr(sprintf(__('Notify for %s', 'pushagent'), $label)); ?>">
									<span class="pa-switch-ui" aria-hidden="true"></span>
								</label>
								<div class="pa-status-name"><strong><?php echo esc_html($label); ?></strong> <code><?php echo esc_html($slug); ?></code></div>
								<span class="pa-status-state"><?php echo $on ? esc_html__('Notifying', 'pushagent') : esc_html__('Off', 'pushagent'); ?></span>
							</div>
							<div class="pa-status-msg">
								<input type="text" class="pa-input" maxlength="120" name="<?php echo esc_attr($o); ?>[wc_title][<?php echo esc_attr($slug); ?>]" value="<?php echo esc_attr(isset($s['wc_title'][$slug]) ? $s['wc_title'][$slug] : ''); ?>" placeholder="<?php esc_attr_e('Title: uses the default title', 'pushagent'); ?>" aria-label="<?php esc_attr_e('Title', 'pushagent'); ?>">
								<textarea class="pa-input" rows="2" maxlength="255" name="<?php echo esc_attr($o); ?>[wc_body][<?php echo esc_attr($slug); ?>]" placeholder="<?php esc_attr_e('Message: uses the default message', 'pushagent'); ?>" aria-label="<?php esc_attr_e('Message', 'pushagent'); ?>"><?php echo esc_textarea(isset($s['wc_body'][$slug]) ? $s['wc_body'][$slug] : ''); ?></textarea>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="pa-card">
				<header class="pa-card-head"><h3><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Order confirmation page', 'pushagent'); ?></h3></header>
				<div class="pa-field">
					<label class="pa-switch">
						<input type="checkbox" name="<?php echo esc_attr($o); ?>[wc_optin]" value="1" <?php checked($s['wc_optin'], 1); ?>>
						<span class="pa-switch-ui" aria-hidden="true"></span>
						<span><?php esc_html_e('Show a “Get updates about this order” box after checkout', 'pushagent'); ?></span>
					</label>
					<p class="pa-help"><?php esc_html_e('The best moment to ask: right after the customer has placed their order.', 'pushagent'); ?></p>
				</div>
			</section>

			<div class="pa-savebar"><?php submit_button(__('Save order updates', 'pushagent'), 'primary pa-btn pa-btn-primary', 'submit', false); ?></div>
		</form>

		<script>
		(function () {
			var form = document.getElementById('pa-orders-form');
			if (!form) return;
			var sample = <?php echo wp_json_encode($sample); ?>;
			var t = document.getElementById('pa-t-all'), b = document.getElementById('pa-b-all');
			var pt = document.getElementById('pa-pv-title'), pb = document.getElementById('pa-pv-body');
			var last = null;
			function fill(s) { return String(s).replace(/\{[a-z_]+\}/g, function (m) { return sample.hasOwnProperty(m) ? sample[m] : m; }); }
			function preview() {
				pt.textContent = fill(t.value || t.placeholder.replace(/^e\.g\.\s*/, ''));
				pb.textContent = fill(b.value || b.placeholder.replace(/^e\.g\.\s*/, ''));
			}
			form.addEventListener('focusin', function (e) { if (e.target.matches('input[type=text], textarea')) last = e.target; });
			form.addEventListener('input', preview);
			form.addEventListener('change', function (e) {
				if (e.target.type === 'checkbox' && e.target.closest('.pa-status')) {
					var row = e.target.closest('.pa-status');
					row.classList.toggle('is-on', e.target.checked);
					row.querySelector('.pa-status-state').textContent = e.target.checked ? <?php echo wp_json_encode(__('Notifying', 'pushagent')); ?> : <?php echo wp_json_encode(__('Off', 'pushagent')); ?>;
				}
			});
			form.querySelectorAll('.pa-chip').forEach(function (chip) {
				chip.addEventListener('mousedown', function (e) { e.preventDefault(); });
				chip.addEventListener('click', function () {
					var el = last || t, ph = chip.getAttribute('data-ph');
					var a = last && el.selectionStart != null ? el.selectionStart : el.value.length, z = last && el.selectionEnd != null ? el.selectionEnd : a;
					el.value = el.value.slice(0, a) + ph + el.value.slice(z);
					el.focus(); el.setSelectionRange(a + ph.length, a + ph.length);
					preview();
				});
			});
			preview();
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ Help */

	private static function page_help($ctx)
	{
		$app_url = $ctx['app_url'];

		self::page_title(__('Help', 'pushagent'), __('Answers to the questions we hear most.', 'pushagent'));

		$faq = array(
			array(__('Visitors don\'t see the “Allow notifications” card', 'pushagent'), __('Open your site in a private window and wait for the delay set in Settings. Browsers only show it on https:// sites (and localhost). If you allowed or blocked notifications before, the card does not appear again: reset the permission from the padlock icon in the address bar.', 'pushagent')),
			array(__('Do I need to upload pushagent-sw.js?', 'pushagent'), __('No. The plugin creates it in your site\'s root folder, or serves it through WordPress when the folder is not writable. Only when WordPress lives in a sub-folder (example.com/blog) do you need to upload it to the domain\'s root yourself; the Dashboard tells you when that is the case.', 'pushagent')),
			array(__('How do I send a notification?', 'pushagent'), sprintf(__('From your <a href="%s" target="_blank" rel="noopener">Push Agent dashboard</a>: New notification, write a title and message, choose who receives it, and send it now or schedule it.', 'pushagent'), esc_url($app_url . '#/send'))),
			array(__('How do WooCommerce order updates work?', 'pushagent'), __('After checkout the customer is asked if they want updates about their order. When you change the order to a status you switched on, Push Agent sends your message to the browsers where the customer allowed notifications. Customers are recognised by a scrambled code made from their email; the email itself is never sent to Push Agent.', 'pushagent')),
			array(__('Do order updates count towards my subscriber limit?', 'pushagent'), __('No. They go to people who are already subscribers, and sending them does not use up anything on your plan.', 'pushagent')),
			array(__('I use a caching plugin or Cloudflare', 'pushagent'), __('That\'s fine. Clear the cache after saving the settings. If an optimisation plugin combines or delays JavaScript, exclude the app.pushagent.net script from it.', 'pushagent')),
		);
		?>
		<div class="pa-grid">
			<div class="pa-col pa-col-wide">
				<section class="pa-card">
					<header class="pa-card-head"><h3><span class="dashicons dashicons-editor-help"></span><?php esc_html_e('Frequently asked questions', 'pushagent'); ?></h3></header>
					<div class="pa-faq">
						<?php foreach ($faq as $i => $f) : ?>
							<details<?php echo $i === 0 ? ' open' : ''; ?>>
								<summary><?php echo esc_html($f[0]); ?></summary>
								<p><?php echo wp_kses($f[1], array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))); ?></p>
							</details>
						<?php endforeach; ?>
					</div>
				</section>
			</div>
			<div class="pa-col">
				<section class="pa-card">
					<header class="pa-card-head"><h3><?php esc_html_e('Useful links', 'pushagent'); ?></h3></header>
					<ul class="pa-links">
						<li><a href="<?php echo esc_url($app_url); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-dashboard"></span><?php esc_html_e('Push Agent dashboard', 'pushagent'); ?></a></li>
						<li><a href="<?php echo esc_url($app_url . '#/integration'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-links"></span><?php esc_html_e('Integration & access token', 'pushagent'); ?></a></li>
						<li><a href="<?php echo esc_url($app_url . '#/developers'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-network"></span><?php esc_html_e('API keys', 'pushagent'); ?></a></li>
						<li><a href="<?php echo esc_url($app_url . '#/account'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-id"></span><?php esc_html_e('Your plan & account', 'pushagent'); ?></a></li>
						<li><a href="https://pushagent.net/pricing/" target="_blank" rel="noopener"><span class="dashicons dashicons-tag"></span><?php esc_html_e('Plans & pricing', 'pushagent'); ?></a></li>
						<li><a href="https://pushagent.net" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-site-alt3"></span><?php esc_html_e('pushagent.net', 'pushagent'); ?></a></li>
					</ul>
				</section>
				<section class="pa-card pa-card-navy">
					<h3><?php esc_html_e('System info', 'pushagent'); ?></h3>
					<dl class="pa-sysinfo">
						<dt><?php esc_html_e('Plugin', 'pushagent'); ?></dt><dd><?php echo esc_html(PushAgent_Plugin::VERSION); ?></dd>
						<dt>WordPress</dt><dd><?php echo esc_html(get_bloginfo('version')); ?></dd>
						<dt>PHP</dt><dd><?php echo esc_html(PHP_VERSION); ?></dd>
						<dt>WooCommerce</dt><dd><?php echo defined('WC_VERSION') ? esc_html(WC_VERSION) : esc_html__('not active', 'pushagent'); ?></dd>
						<dt><?php esc_html_e('Service worker', 'pushagent'); ?></dt><dd><?php $p = PushAgent_Plugin::sw_path(); echo esc_html($p !== '' ? $p : __('served by WordPress', 'pushagent')); ?></dd>
					</dl>
				</section>
			</div>
		</div>
		<?php
	}
}
