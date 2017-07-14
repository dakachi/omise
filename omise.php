<?php
/**
 * Plugin Name: Membership Omise Gateway
 * Plugin URI:  https://github.com/dakachi/omise
 * Version:     1.0
 * Author:      Dakachi
 *
 * @package Membership2
 */

require_once dirname(__FILE__) . '/vendor/autoload.php';

define('OMISE_PUBLIC_KEY', 'pkey_test_58lpgvlp7uulsxiyf72');
define('OMISE_SECRET_KEY', 'skey_test_58lpgvlprrfxthnku0v');

function omise_load_lib() {
    require_once dirname(__FILE__) . '/omise/class-ms-gateway-omise.php';
    require_once dirname(__FILE__) . '/omise/class-ms-gateway-omise-api.php';

    require_once dirname(__FILE__) . '/omise/view/class-ms-gateway-omise-view-button.php';
    require_once dirname(__FILE__) . '/omise/view/class-ms-gateway-omise-view-card.php';
    require_once dirname(__FILE__) . '/omise/view/class-ms-gateway-omise-view-settings.php';
}
add_action('after_setup_theme', 'omise_load_lib');

function omise_settings($view, $gateway_id) {
	if($gateway_id == 'omise') {
		$view = MS_Factory::create( 'MS_Gateway_Omise_View_Settings' );
	}
	return $view;
}
add_filter( 'ms_gateway_view_settings_edit', 'omise_settings', 10, 2 );

function omise_register_gateway($gateways) {
	$gateways['omise'] = 'MS_Gateway_Omise';
	return $gateways;
}
add_filter( 'ms_model_gateway_register', 'omise_register_gateway' );