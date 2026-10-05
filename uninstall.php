<?php
/**
 * Push Agent - runs when the plugin is deleted from the Plugins screen.
 * Removes the settings, and pushagent-sw.js only if this plugin created it.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

require_once __DIR__ . '/pushagent.php';

$pushagent_file = PushAgent_Plugin::sw_path();

if ($pushagent_file !== '' && is_file($pushagent_file)) {
	$pushagent_body = (string) @file_get_contents($pushagent_file);

	if (strpos($pushagent_body, 'Push Agent WordPress plugin') !== false && strpos($pushagent_body, PushAgent_Plugin::SW_MARKER) !== false) {
		@unlink($pushagent_file);
	}
}

delete_option(PushAgent_Plugin::OPTION);
delete_option('pushagent-accesstoken');
delete_option(PushAgent_Plugin::LOG_OPTION);
delete_transient('pushagent_ping');
delete_transient('pushagent_sw');
