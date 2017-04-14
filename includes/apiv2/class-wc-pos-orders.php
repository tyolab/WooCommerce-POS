<?php

/**
 * POS Orders Class
 * duck punches the WC REST API
 *
 * @class    WC_POS_API_Orders
 * @package  WooCommerce POS
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 * @link     http://www.woopos.com.au
 */

class WC_POS_APIv2_Orders extends WC_POS_APIv2_Abstract {

  /**
   * Constructor
   */
  public function __construct() {
    add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $this, 'pre_insert_shop_order_object' ), 10, 3 );
    add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'prepare_shop_order_object' ), 10, 3 );
    add_action( 'woocommerce_rest_set_order_item', array( $this, 'rest_set_order_item' ), 10, 2 );

    $this->register_additional_fields();
    $this->unregister_emails();
  }


  /**
   * Additional fields for POS
   */
  public function register_additional_fields() {

    // add cashier field
    register_rest_field( 'shop_order',
      'cashier',
      array(
        'get_callback'    => array( $this , 'get_cashier' ),
        'update_callback' => array( $this , 'update_cashier' ),
        'schema'          => null,
      )
    );

    // add payment_details field
    register_rest_field( 'shop_order',
      'payment_details',
      array(
        'get_callback'    => array( $this , 'get_payment_details' ),
        'update_callback' => array( $this , 'update_payment_details' ),
        'schema'          => null,
      )
    );

  }


  /**
   * Retrieve cashier info for the API response
   *
   * @param $response
   * @param $order
   * @return mixed|void
   */
  public function get_cashier( $response ) {
    $id = $response['id'];

    if ( !$cashier_id = get_post_meta( $id, '_pos_user', true ) ) {
      return;
    }

    $first_name = get_post_meta( $id, '_pos_user_first_name', true );
    $last_name = get_post_meta( $id, '_pos_user_last_name', true );
    if ( !$first_name && !$last_name && $user_info = get_userdata( $cashier_id ) ) {
      $first_name = $user_info->first_name;
      $last_name = $user_info->last_name;
    }

    $cashier = array(
      'id'         => $cashier_id,
      'first_name' => $first_name,
      'last_name'  => $last_name
    );

    return apply_filters( 'woocommerce_pos_order_response_cashier', $cashier, $response );
  }


  /**
   * Store cashier data
   *
   * @param $cashier From the POS request body
   * @param $order
   */
  public function update_cashier( $cashier, $order ) {
    $id = $order->get_id();
    $current_user = wp_get_current_user();
    update_post_meta( $id, '_pos', 1 );
    update_post_meta( $id, '_pos_user', $current_user->ID );
    update_post_meta( $id, '_pos_user_name', $current_user->user_firstname . ' ' . $current_user->user_lastname );
  }


  /**
   * Retrieve payment info for the API response
   *
   * @param $response
   * @param $order
   * @return mixed|void
   */
  public function get_payment_details( $response ) {
    $id = $response['id'];
    $payment = array();

    $payment['result']   = get_post_meta( $id, '_pos_payment_result', true );
    $payment['message']  = get_post_meta( $id, '_pos_payment_message', true );
    $payment['redirect'] = get_post_meta( $id, '_pos_payment_redirect', true );

    $payment['method_id'] = isset( $response['payment_method'] ) ? $response['payment_method']  : '';
    $payment['method_title'] = isset( $response['payment_method_title'] ) ? $response['payment_method_title'] : '';

    if($response['date_completed'] && $response['date_paid']) {
      $payment['paid'] = true;
    }

    if( isset( $payment['method_id'] ) && $payment['method_id'] == 'pos_cash' ){
      $payment = WC_POS_Gateways_Cash::payment_details( $payment, $id );
    }

    return apply_filters( 'woocommerce_pos_order_response_payment_details', $payment, $response );
  }


  /**
   * Process payment and store result
   *
   * @param $payment_details From the POS request body
   * @param $order
   */
  public function update_payment_details( $payment_details, $order ) {

    // payment method
    $payment_method = $order->get_payment_method();

    // some gateways check if a user is signed in, so let's switch to customer
    $logged_in_user = get_current_user_id();
    $customer_id = $order->get_customer_id();
    wp_set_current_user( $customer_id );

    // load the gateways & process payment
    add_filter('option_woocommerce_'. $payment_method .'_settings', array($this, 'force_enable_gateway'));
    $settings = WC_POS_Admin_Settings_Checkout::get_instance();
    $gateways = $settings->load_enabled_gateways();
    $response = $gateways[ $payment_method ]->process_payment( $order->get_id() );

    if(isset($response['result']) && $response['result'] == 'success'){

      $this->payment_success($payment_method, $order, $response);
      
      if( ! isset($response['redirect']) || ! $response['redirect'] ) {
        $order->set_date_paid( current_time( 'timestamp' ) );
        $order->set_date_completed( current_time( 'timestamp' ) );
        $message = __('POS Transaction completed.', 'woocommerce-pos');
        $order->update_status( wc_pos_get_option( 'checkout', 'order_status' ), $message );
      }

    } else {
      $this->payment_failure($payment_method, $order, $response);
    }

    // switch back to logged in user
    wp_set_current_user( $logged_in_user );

    // clear any payment gateway messages
    wc_clear_notices();

  }

  /**
   * @param $order
   * @param $request
   * @param $creating
   */
  public function pre_insert_shop_order_object( $order, $request, $creating ) {

    /**
     * Transpose legacy api data to WC API v2
     */
    if( isset($request['note']) ) {
      $order->set_customer_note($request['note']);
    }

    if( isset($request['payment_details']) ) {
      $payment_details = $request['payment_details'];
      $order->set_payment_method( isset($payment_details['method_id']) ? $payment_details['method_id'] : '' );
      $order->set_payment_method_title( isset($payment_details['method_title']) ? $payment_details['method_title'] : '' );
    }

    // additional fields are required as part of request
    $request['cashier'] = '';

    // calculate taxes (trust the POS)
    add_filter( 'wc_tax_enabled', '__return_false' );
    $order->update_taxes();
    $order->calculate_totals();

    return $order;
  }


  /**
   * @param $response
   * @param $order
   * @param $request
   * @return
   */
  public function prepare_shop_order_object( $response, $order, $request ) {
    $data = $response->get_data();

    /**
     * Legacy API Compatibiility
     * duplicate props for receipt templates
     */
    if($data['customer_note']) {
      $data['note'] = $data['customer_note'];
    }

    if($data['discount_total']) {
      $data['total_discount'] = $data['discount_total'];
    }

    if($data['shipping_total']) {
      $data['total_shipping'] = $data['shipping_total'];
    }

    if($data['number']) {
      $data['order_number'] = $data['number'];
    }

    if($data['date_modified']) {
      $data['updated_at'] = $data['date_modified'];
    }

    $response->set_data($data);
    return $response;
  }


  /**
   * @param $item
   * @param $posted
   */
  public function rest_set_order_item( $item, $posted ) {
    $tax_status = isset($posted['taxable']) && $posted['taxable'] ? 'taxable' : 'none';

    if( $tax_status == 'taxable' ) {
      $total = array();
      $subtotal = array();

      if(isset($posted['tax']) && is_array($posted['tax'])): foreach($posted['tax'] as $rate_id => $tax):
        if(is_array($tax)) {
          $total[$rate_id] = isset($tax['total']) ? $tax['total'] : 0;
          $subtotal[$rate_id] = isset($tax['subtotal']) ? $tax['subtotal'] : 0;
        }
      endforeach; endif;

      if( get_class($item) == 'WC_Order_Item_Product' ) {
        $item->set_taxes( array( 'total' => $total, 'subtotal' => $subtotal ) );
      } else {
        $item->set_taxes( array( 'total' => $total ) );
      }
    }
  }


  /**
   * Some gateways will check if enabled
   * @param $data
   * @return mixed
   */
  public function force_enable_gateway($data){
    if(isset($data['enabled'])){
      $data['enabled'] = 'yes';
    }
    return $data;
  }


  /**
   * @param $gateway_id
   * @param $order
   * @param $response
   */
  private function payment_success($gateway_id, $order, $response){

    // capture any instructions
    ob_start();
    do_action( 'woocommerce_thankyou_' . $gateway_id, $order->get_id() );
    $response['messages'] = ob_get_contents();
    ob_end_clean();

    // redirect
    if( isset($response['redirect']) ){
      $response['messages'] = $this->payment_redirect($gateway_id, $order, $response);
    }

    update_post_meta( $order->get_id(), '_pos_payment_result', 'success' );
    update_post_meta( $order->get_id(), '_pos_payment_message', $response['messages'] );
  }


  /**
   * @param $gateway_id
   * @param $order
   * @param $response
   */
  private function payment_failure($gateway_id, $order, $response){
    $message = isset($response['messages']) ? $response['messages'] : wc_get_notices( 'error' );

    // if messages empty give generic response
    if(empty($message)){
      $message = __( 'There was an error processing the payment', 'woocommerce-pos');
    }

    update_post_meta( $order->get_id(), '_pos_payment_result', 'failure' );
    update_post_meta( $order->get_id(), '_pos_payment_message', $message );
  }

  /**
   * @param $gateway_id
   * @param $order
   * @param $response
   * @return string
   */
  private function payment_redirect($gateway_id, $order, $response){
    $message = $response['messages'];

    // compare url fragments
    $success_url = wc_get_endpoint_url( 'order-received', $order->get_id(), get_permalink( wc_get_page_id( 'checkout' ) ) );
    $success = wp_parse_args( parse_url( $success_url ), array( 'host' => '', 'path' => '' ));
    $redirect = wp_parse_args( parse_url( $response['redirect'] ), array( 'host' => '', 'path' => '' ));

    $offsite = $success['host'] !== $redirect['host'];
    $reload = !$offsite && $success['path'] !== $redirect['path'] && $response['messages'] == '';

    if($offsite || $reload){
      update_post_meta( $order->get_id(), '_pos_payment_redirect', $response['redirect'] );
      $message = __('You are now being redirected offsite to complete the payment. ', 'woocommerce-pos');
      $message .= sprintf( __('<a href="%s">Click here</a> if you are not redirected automatically. ', 'woocommerce-pos'), $response['redirect'] );
    }

    return $message;
  }


  /**
   * Adds support for custom address fields
   * @param $address
   * @param $order
   * @param string $type
   * @return array
   */
  private function filter_address( $address, $order, $type = 'billing' ){
    $fields = apply_filters('woocommerce_admin_'.$type.'_fields', false);
    if( $fields ){
      $address = array();
      foreach($fields as $key => $value){
        $address[$key] = $order->{$type.'_'.$key};
      }
    }
    return $address;
  }


  /**
   * Get customer details
   * - mirrors woocommerce/includes/class-wc-ajax.php->get_customer_details()
   * @param $user_id
   * @param $type_to_load
   * @return mixed|void
   */
  private function get_customer_details( $user_id, $type_to_load ){
    $customer_data = array(
      $type_to_load . '_first_name' => get_user_meta( $user_id, $type_to_load . '_first_name', true ),
      $type_to_load . '_last_name'  => get_user_meta( $user_id, $type_to_load . '_last_name', true ),
      $type_to_load . '_company'    => get_user_meta( $user_id, $type_to_load . '_company', true ),
      $type_to_load . '_address_1'  => get_user_meta( $user_id, $type_to_load . '_address_1', true ),
      $type_to_load . '_address_2'  => get_user_meta( $user_id, $type_to_load . '_address_2', true ),
      $type_to_load . '_city'       => get_user_meta( $user_id, $type_to_load . '_city', true ),
      $type_to_load . '_postcode'   => get_user_meta( $user_id, $type_to_load . '_postcode', true ),
      $type_to_load . '_country'    => get_user_meta( $user_id, $type_to_load . '_country', true ),
      $type_to_load . '_state'      => get_user_meta( $user_id, $type_to_load . '_state', true ),
      $type_to_load . '_email'      => get_user_meta( $user_id, $type_to_load . '_email', true ),
      $type_to_load . '_phone'      => get_user_meta( $user_id, $type_to_load . '_phone', true ),
    );
    $customer_data = apply_filters( 'woocommerce_found_customer_details', $customer_data, $user_id, $type_to_load );

    // remove billing_ or shipping_ prefix for WC REST API
    $data = array();
    foreach( $customer_data as $key => $value ): if($value):
      $key = str_replace( $type_to_load.'_', '', $key );
      $data[$key] = $value;
    endif; endforeach;
    return $data;
  }


  /**
   * Returns array of all order ids
   * optionally return ids updated_at_min
   * @param $date_modified
   * @return array
   */
  public function get_ids($date_modified){
    $args = array(
      'post_type'     => array('shop_order'),
      'post_status'   => array('any'),
      'posts_per_page'=>  -1,
      'fields'        => 'ids'
    );

    if($date_modified){
      $args['date_query'][] = array(
        'column'    => 'post_modified_gmt',
        'after'     => $date_modified,
        'inclusive' => false
      );
    }

    $query = new WP_Query( $args );
    return array_map( 'intval', $query->posts );
  }


  /**
   * Allow users to unregister WC emails
   */
  public function unregister_emails(  ) {
    $wc_emails = WC()->mailer();

    if( get_class($wc_emails) !== 'WC_Emails' ) {
      return;
    }

    if( ! wc_pos_get_option( 'checkout', 'customer_emails' ) ){
      $this->remove_customer_emails($wc_emails);
    }

    if( ! wc_pos_get_option( 'checkout', 'admin_emails' ) ){
      $this->remove_admin_emails($wc_emails);
    }
  }


  /**
   * Unhook customer emails
   *
   * @param WC_Emails $wc_emails
   * @internal param $emails
   */
  public function remove_customer_emails( WC_Emails $wc_emails ){

    remove_action(
      'woocommerce_order_status_pending_to_processing_notification',
      array(
        $wc_emails->emails['WC_Email_Customer_Processing_Order'],
        'trigger'
      )
    );
    remove_action(
      'woocommerce_order_status_pending_to_on-hold_notification',
      array(
        $wc_emails->emails['WC_Email_Customer_Processing_Order'],
        'trigger'
      )
    );
    remove_action(
      'woocommerce_order_status_completed_notification',
      array(
        $wc_emails->emails['WC_Email_Customer_Completed_Order'],
        'trigger'
      )
    );

  }

  /**
   * Unhook admin emails
   *
   * @param WC_Emails $wc_emails
   * @return array
   */
  private function remove_admin_emails( WC_Emails $wc_emails ){
    // send 'woocommerce_low_stock_notification'
    // send 'woocommerce_no_stock_notification'
    // send 'woocommerce_product_on_backorder_notification'

    remove_action(
      'woocommerce_order_status_pending_to_processing_notification',
      array(
        $wc_emails->emails['WC_Email_New_Order'],
        'trigger'
      )
    );
    remove_action(
      'woocommerce_order_status_pending_to_completed_notification',
      array(
        $wc_emails->emails['WC_Email_New_Order'],
        'trigger'
      )
    );
    remove_action(
      'woocommerce_order_status_pending_to_on-hold_notification',
      array(
        $wc_emails->emails['WC_Email_New_Order'],
        'trigger'
      )
    );
    remove_action(
      'woocommerce_order_status_failed_to_processing_notification',
      array(
        $wc_emails->emails['WC_Email_New_Order'],
        'trigger'
      )
    );
    remove_action(
      'woocommerce_order_status_failed_to_completed_notification',
      array(
        $wc_emails->emails['WC_Email_New_Order'],
        'trigger'
      )
    );
    remove_action(
      'woocommerce_order_status_failed_to_on-hold_notification',
      array(
        $wc_emails->emails['WC_Email_New_Order'],
        'trigger'
      )
    );

  }

}