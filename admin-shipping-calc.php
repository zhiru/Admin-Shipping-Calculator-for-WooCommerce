<?php

defined( 'ABSPATH' ) OR die( 'This script cannot be accessed directly.' );

/*
Plugin Name: Admin Shipping Calculator for WooCommerce
Description: Plugin for WooCommerce which calculates shipping cost on wp-admin order screen
Version: 1.0.0
Author: Patrick Bußmann
Author URI: https://bussmann-it.de/
*/

/**
 * Load JavaScript for our administration
 */
add_action('admin_enqueue_scripts', function() {
	global $pagenow;

	if(is_admin()
		&& in_array($pagenow, ['post.php', 'post-new.php'])
		&& ($_GET['post_type'] ?: 'shop_order') === 'shop_order')
	{
		wp_enqueue_script('shipping-calc_js', plugins_url('js/admin-shipping-calc.js', __FILE__));
		wp_localize_script( 'shipping-calc_js', 'shipping_calc', array(
				'url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('admin_shipping_calculate')
			)
		);
	}
});

/**
 * REST API
 */
add_action('wp_ajax_admin_shipping_calculate', function () {
	if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'admin_shipping_calculate')) {
		wp_send_json_error();
	}

	$products = isset($_POST['products']) ? (array) $_POST['products'] : array();
	$orderItems = array_map(function($productId) {
		return new WC_Order_Item_Product((int) $productId);
	}, $products);

	$cart = WC()->cart;
	$cart->set_cart_contents(array());
	foreach($orderItems as $orderItem)
	{
		$cart->add_to_cart($orderItem->get_product_id(), $orderItem->get_quantity());
	}

	$package = [
		'destination' => [
			'country' => isset($_POST['country']) ? sanitize_text_field($_POST['country']) : '',
			'state' => isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '',
			'postcode' => isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : ''
		],
		'contents' => array_map(function($orderItem) {
			return [
				'quantity' => (int) $orderItem->get_quantity(),
				'data' => $orderItem->get_product(),
				'line_total' => $orderItem->get_total(),
				'line_tax' => $orderItem->get_total_tax(),
				'line_subtotal' => $orderItem->get_subtotal(),
				'line_subtotal_tax' => $orderItem->get_subtotal_tax()
			];
		}, $orderItems),
		'contents_cost' => array_sum(array_map(function (WC_Order_Item_Product $orderItem) {
			return $orderItem->get_total();
		}, $orderItems))
	];

	$shippingZone = WC_Shipping_Zones::get_zone_matching_package($package);
	/** @var WC_Shipping_Method[] $shippingMethods */
	$shippingMethods = $shippingZone->get_shipping_methods(true);

	$prices = array();
	foreach($shippingMethods as $shippingMethod)
	{
		/** @var WC_Shipping_Rate[] $rates */
		$rates = $shippingMethod->get_rates_for_package($package);
		foreach($rates as $rate)
		{
			$prices[] = [
				'id' => wp_kses($rate->get_id(), array()),
				'method' => wp_kses($rate->get_method_id(), array()),
				'total' => (float) $rate->get_cost(),
				'tax' => (float) (is_array($rate->get_shipping_tax()) ? array_sum($rate->get_shipping_tax())
					: $rate->get_shipping_tax())
			];
		}
	}

	wp_send_json_success([
		'shipping' => $prices
	]);
});
