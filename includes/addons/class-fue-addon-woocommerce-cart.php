<?php

/**
 * Class FUE_Addon_Woocommerce_Cart
 */
class FUE_Addon_Woocommerce_Cart {

	private $fue_wc;

	/**
	 * Register hooks
	 * @param FUE_Addon_Woocommerce $fue_wc
	 */
	public function __construct( $fue_wc ) {
		$this->fue_wc = $fue_wc;

		// cart redirect
		add_action( 'wp', array($this, 'init_guest_cart') );
		add_action( 'template_redirect', array($this, 'handle_redirect_to_cart') );
		add_filter( 'woocommerce_login_redirect', array($this, 'override_login_redirect'), 10, 2 );

		// cart actions
		add_action( 'woocommerce_cart_updated', array($this, 'cart_updated') );
		add_action( 'woocommerce_cart_emptied', array($this, 'cart_emptied') );
		add_action( 'woocommerce_order_status_processing', array($this, 'empty_cart_after_checkout') );
		add_action( 'woocommerce_order_status_completed', array($this, 'empty_cart_after_checkout') );
		add_action( 'woocommerce_checkout_order_processed', array($this, 'empty_cart_after_checkout') );
		add_action( 'woocommerce_checkout_order_processed', array($this, 'record_cart_conversion') );

		// clear cart and emails - scheduled events page
		add_action('admin_post_fue_wc_clear_cart', array($this, 'process_clear_scheduled_cart_emails') );
	}

	/**
	 * Load the guest cart if customer is not logged in and attempting
	 * to view the cart from a link generated by a cart email
	 */
	public function init_guest_cart() {
		if ( is_user_logged_in() || !is_cart() ) {
			return;
		}

		if ( !isset( $_GET['qid'] ) ) {
			return;
		}

		$item = new FUE_Sending_Queue_Item( absint( $_GET['qid'] ) );

		if ( !$item->exists() ) {
			return;
		}

		if ( $item->user_id == 0 ) {
			WC()->cart = null;
			$this->init_wc_cart( $item->user_id, $item->user_email );

			wp_redirect( get_permalink( wc_get_page_id( 'cart' ) ) );
			exit;
		}
	}

	/**
	 * Handle {cart_url} links by making sure the customer is logged in first
	 * before viewing the cart to allow WC to load his persistent cart.
	 */
	public function handle_redirect_to_cart() {
		if ( !is_cart() || is_user_logged_in() ) {
			return;
		}

		if ( !isset( $_GET['qid'] ) ) {
			return;
		}

		$item = new FUE_Sending_Queue_Item( absint( $_GET['qid'] ) );

		if ( !$item->exists() ) {
			return;
		}

		if ( !is_user_logged_in() && $item->user_id > 0 ) {
			// set a cookie to force a redirect to the cart after logging in
			setcookie( 'fue_cart_redirect', true, 0, SITECOOKIEPATH, COOKIE_DOMAIN );
			wc_add_notice( __('Please log in to view your saved cart.', 'follow_up_emails'), 'notice' );
			wp_redirect( get_permalink( wc_get_page_id( 'myaccount' ) ) );
			exit;
		}
	}

	/**
	 * Override the redirect URL to point to the cart if a 'fue_cart_redirect' cookie is present.
	 *
	 * @param string $redirect
	 * @param WP_User $user
	 *
	 * @return string
	 */
	public function override_login_redirect( $redirect, $user ) {
		if ( isset( $_COOKIE['fue_cart_redirect'] ) ) {
			$redirect = wc_get_cart_url();
		}

		return $redirect;
	}

	/**
	 * Runs when the cart is updated. Load the persistent cart and
	 * look for items in the cart that needs to be added to the queue
	 */
	public function cart_updated() {

		// only if user is logged in or an email is stored in the session
		$user   = wp_get_current_user();
		$email  = self::get_session_email();

		if ( 0 == $user->ID && !$email ) {
			return;
		}

		$cart = WC()->cart->get_cart();

		if ( empty( $cart ) ) {
			// cart has been emptied. we need to remove existing email orders for this user
			$this->cart_emptied();
			return;
		}

		self::clone_cart();

		$added_product = null;
		if ( isset( $_REQUEST['wc-ajax'] ) && $_REQUEST['wc-ajax'] == 'add_to_cart' ) {
			$added_product = $_REQUEST['product_id'];
		}

		$this->fue_wc->wc_scheduler->queue_cart_emails( $cart, $user->ID, $email, $added_product );

	}

	/**
	 * When the cart is emptied, clear all queued unsent cart emails
	 */
	public function cart_emptied() {
		$user_id = get_current_user_id();

		// Do not delete unsent cart emails if we're logging out, as the cart will be filled once we log back in.
		if ( doing_action( 'wp_logout' ) ) {
			return;
		}

		$email  = self::get_session_email();

		// Do not empty cart emails if we don't have either a logged in or a guest user
		if ( empty( $user_id ) && empty( $email ) ) {
			return;
		}

		do_action('fue_cart_emptied');

		$this->fue_wc->wc_scheduler->delete_unsent_cart_emails( $user_id, $email );

		self::clone_cart();

		update_user_meta( $user_id, '_fue_cart_last_update', current_time( 'timestamp' ) );
		update_user_meta( $user_id, '_wcfue_cart_emails', array() );

		return;
	}

	/**
	 * If the order is from a registered customer, clear all cart emails
	 * that have been created for the customer.
	 *
	 * @param int $order_id
	 */
	public function empty_cart_after_checkout( $order_id ) {
		$user_id = get_post_meta( $order_id, '_customer_user', true );
		$user_email = get_post_meta( $order_id, '_billing_email', true );

		if ( $user_id > 0 ) {
			$this->fue_wc->wc_scheduler->delete_unsent_cart_emails( $user_id );
			update_user_meta( $user_id, '_wcfue_cart_emails', array() );
		}

		$this->fue_wc->wc_scheduler->delete_unsent_cart_emails( 0, $user_email );
	}

	/**
	 * Load the cart for the current user and store a duplicate
	 * in the followup_customer_carts table
	 */
	public static function clone_cart() {
		$wpdb = Follow_Up_Emails::instance()->wpdb;

		$user_id = get_current_user_id();

		$user_email = WC()->session->get('wc_guest_email', '');
		$name       = WC()->session->get( 'wc_guest_name', array('', '') );
		$wc_cart    = WC()->cart;

		if ( !$user_id && !$user_email ) {
			return;
		}

		if ( $user_email ) {
			$cart_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}followup_customer_carts WHERE user_email = %s", $user_email));
		} else {
			$cart_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}followup_customer_carts WHERE user_id = %d", $user_id));
		}

		$cart_data = array(
			'user_id'   => $user_id,
			'first_name'    => $name[0],
			'last_name'     => $name[1],
			'user_email'    => $user_email,
			'cart_items'    => serialize( $wc_cart->get_cart() ),
			'cart_total'    => $wc_cart->cart_contents_total,
			'date_updated'  => current_time( 'mysql' )
		);

		if ( !$cart_id ) {
			$wpdb->insert( $wpdb->prefix .'followup_customer_carts', $cart_data );
		} else {
			$wpdb->update( $wpdb->prefix .'followup_customer_carts', $cart_data, array( 'id' => $cart_id ) );
		}
	}

	/**
	 * Look for recent emails and record this order as a conversion
	 *
	 * @param int $order_id
	 */
	public function record_cart_conversion( $order_id ) {
		$conversion_days = get_option('fue_wc_conversion_days', 14);

		$emails = fue_get_emails( 'any', FUE_Email::STATUS_ACTIVE, array(
			'fields'        => 'ids'
		) );

		if ( empty( $emails ) ) {
			return;
		}

		if ( get_post_meta( $order_id, '_subscription_renewal', true ) ) {
			return;
		}

		$order = WC_FUE_Compatibility::wc_get_order( $order_id );
		$user  = WC_FUE_Compatibility::get_order_user( $order );

		if ( !$user ) {
			return;
		}

		$to     = current_time( 'mysql' );
		$from   = date( 'Y-m-d 00:00:00', strtotime("-{$conversion_days} days") );

		$sent_emails = Follow_Up_Emails::instance()->scheduler->get_items( array(
			'is_sent'   => 1,
			'email_id'  => $emails,
			'user_id'   => $user->ID,
			'date_sent' => array( 'from' => $from, 'to' => $to ),
			'limit'     => 1
		) );

		if ( !empty( $sent_emails ) ) {
			$sent_email = current( $sent_emails );
			update_post_meta( $order_id, '_fue_conversion', $sent_email->email_id );

			do_action( 'fue_cart_conversion', $order, $sent_email );
		}
	}

	/**
	 * Remove the selected user's cart items and delete all unsent cart emails
	 */
	public function process_clear_scheduled_cart_emails() {
		$wpdb = Follow_Up_Emails::instance()->wpdb;

		if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wc_clear_cart' ) ) {
			wp_die( __( 'Are you sure you want to do this?', 'follow_up_emails' ) );
		}

		set_time_limit(0);

		$user_id    = '';
		$email      = '';

		if ( !empty( $_REQUEST['user_id'] ) ) {
			$user_id = absint( $_REQUEST['user_id'] );
		}

		if ( !empty( $_REQUEST['email'] ) ) {
			$email = sanitize_email( $_REQUEST['email'] );
		}

		$this->fue_wc->wc_scheduler->delete_unsent_cart_emails( $user_id, $email );

		if ( $user_id ) {
			update_user_meta( $user_id, '_wcfue_cart_emails', array() );
			delete_user_meta( $user_id, '_woocommerce_persistent_cart' );

			$session_value = $wpdb->get_var($wpdb->prepare(
				"SELECT session_value
				FROM {$wpdb->prefix}woocommerce_sessions
				WHERE session_key = %d",
				$user_id
			));

			if ( $session_value ) {
				$session = maybe_unserialize( $session_value );

				if ( is_array( $session ) && isset( $session['cart'] ) ) {
					$session['cart'] = array();

					$wpdb->update(
						$wpdb->prefix .'woocommerce_sessions',
						array( 'session_value' => serialize( $session ) ),
						array( 'session_key' => $user_id )
					);
				}
			}
		}

		$message = __( 'Cart emails have been cleared for this user', 'follow_up_emails' );

		$query_args = array(
			'message'   => $message,
			'user_id'   => $user_id,
			'email'     => $email,
			'tab'       => 'reportuser_view'
		);

		$redirect_to = add_query_arg(
			$query_args,
			admin_url( 'admin.php?page=followup-emails-reports' )
		);

		// Redirect to avoid performning actions on a page refresh
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Return the contents of a customer's cart
	 *
	 * @param int $user_id
	 * @param string $email
	 * @return array|false
	 */
	public static function get_cart( $user_id = 0, $email = '' ) {
		$wpdb = Follow_Up_Emails::instance()->wpdb;

		if ( empty( $user_id ) && empty( $email ) ) {
			return false;
		}

		if ( $user_id ) {
			$cart_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}followup_customer_carts
				WHERE user_id = %d",
				$user_id
			), ARRAY_A );
		} else {
			$cart_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}followup_customer_carts
				WHERE  user_email = %s",
				$email
			), ARRAY_A );
		}

		if ( !$cart_row ) {
			$cart_row = false;
		} else {
			$cart_row['cart_items'] = maybe_unserialize( $cart_row['cart_items'] );
		}

		return $cart_row;
	}

	/**
	 * Initialize the WC_Cart class so it can be used in the admin panel
	 * @param int       $user_id
	 * @param string    $email
	 */
	public static function init_wc_cart( $user_id = 0, $email = '' ) {
		if ( isset( WC()->cart ) && is_a( WC()->cart, 'WC_Cart' ) ) {
			return;
		}

		FUE_Addon_Woocommerce::init_wc_session();

		if (! function_exists( 'wc_cart_totals_order_total_html' ) ) {
			include_once( WC()->plugin_path() .'/includes/wc-cart-functions.php' );
		}

		$cart_row = self::get_cart( $user_id, $email );

		WC()->cart = new WC_Cart();

		if ( $cart_row ) {
			WC()->session->cart = $cart_row['cart_items'];
		}

		WC()->customer = new WC_Customer();
		if ( version_compare( WC_VERSION, '3.2.0', '<' ) ) {
			WC()->cart->init();
		} else {
			WC()->cart->get_cart_from_session();
		}
	}

	/**
	 * Return the current user's session email if it exists
	 * @return string
	 */
	public static function get_session_email() {
		if ( !WC()->session ) {
			FUE_Addon_Woocommerce::init_wc_session();
		}

		return WC()->session->get( 'wc_guest_email', '' );
	}

	/**
	 * Return the status of the user's cart (Active or Abandoned)
	 *
	 * @param mixed $user User ID or email address
	 * @return string
	 */
	public static function get_cart_status( $user ) {
		$status     = __('Active', 'follow_up_emails');
		$email      = '';
		$user_id    = 0;

		if ( is_email( $user ) ) {
			$email = $user;
		} else {
			$user_id = $user;
		}

		$cart = self::get_cart( $user_id, $email );

		if ( !$cart ) {
			return $status;
		}

		$abandon_value  = get_option( 'fue_wc_abandoned_cart_value' );
		$abandon_unit   = get_option( 'fue_wc_abandoned_cart_unit' );

		$time = 0;
		switch ($abandon_unit ) {
			case 'minutes':
				$time = $abandon_value * 60;
				break;

			case 'hours':
				$time = $abandon_value * 3600;
				break;

			case 'days':
				$time = $abandon_unit * 86400;
				break;
		}

		$now        = current_time( 'timestamp' );
		$time_diff  = $now - strtotime($cart['date_updated']);

		if ( $time_diff > $time ) {
			$status = __('Abandoned', 'follow_up_emails');
		}

		return apply_filters( 'fue_wc_cart_status', $status, $user_id, $email );
	}

	/**
	 * Set the cart session for a user. This prevents sending duplicate cart emails to the same customer.
	 *
	 * @param int   $user_id
	 * @param array $cart_session
	 */
	public static function set_user_cart_session( $user_id = 0, $cart_session ) {
		if ( $user_id ) {
			update_user_meta( $user_id, '_wcfue_cart_emails', $cart_session );
		} else {
			WC()->session->set( '_wcfue_cart_emails', $cart_session );
		}
	}

	/**
	 * Get the stored cart session for a user
	 *
	 * The cart session is an array of [email_id]_[product_id] values
	 * that is used to keep track of the FUE_Emails that have already
	 * been queued/sent to avoid duplicate cart emails
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_user_cart_session( $user_id ) {
	    if ( $user_id ) {
		    $cart_session = get_user_meta( $user_id, '_wcfue_cart_emails', true );
	    } else {
		    $cart_session = WC()->session->get( '_wcfue_cart_emails', array() );
	    }

		if (! $cart_session ) {
			$cart_session = array();
		}

		return $cart_session;
	}

	/**
	 * Get the cart total for the given user
	 *
	 * @param int $user_id
	 * @param string $email
	 * @return string
	 */
	public static function get_cart_total( $user_id = 0, $user_email = '' ) {
		global $wpdb;

		if ( ! empty( $user_id ) ) {
			$cart_total = $wpdb->get_var($wpdb->prepare("SELECT cart_total FROM {$wpdb->prefix}followup_customer_carts WHERE user_id = %d", $user_id));
		} else {
			$cart_total = $wpdb->get_var($wpdb->prepare("SELECT cart_total FROM {$wpdb->prefix}followup_customer_carts WHERE user_email = %s", $user_email));
		}

		return apply_filters( 'woocommerce_cart_contents_total', wc_price( $cart_total ) );
	}

	/**
	 * Load the user's persistent cart and render it in an HTML table
	 * @param int $user_id
	 * @param string $user_email
	 * @return string
	 */
	public static function get_cart_table( $user_id = 0, $user_email = '' ) {
		global $wpdb;

		if ( ! empty( $user_id ) ) {
			$cart_items = $wpdb->get_var($wpdb->prepare("SELECT cart_items FROM {$wpdb->prefix}followup_customer_carts WHERE user_id = %d", $user_id));
		} else if ( ! empty( $user_email ) ) {
			$cart_items = $wpdb->get_var($wpdb->prepare("SELECT cart_items FROM {$wpdb->prefix}followup_customer_carts WHERE user_email = %s", $user_email));
		} else {
			return '';
		}

		$cart_items = maybe_unserialize( $cart_items );

		if ( empty( $cart_items ) ) {
			return '';
		}

		if ( WC()->cart ) {
			$original_cart_contents = WC()->cart->get_cart_contents();

			// Set cart contents for plugins that use cart filters and depend on WC()->cart->cart_contents.
			WC()->cart->set_cart_contents( $cart_items );
		}

		ob_start();
		fue_get_template( 'cart-contents.php', array('cart' => $cart_items, 'user_id' => $user_id), 'follow-up-emails/email-variables/', FUE_TEMPLATES_DIR .'/email-variables/' );
		$result = ob_get_clean();

		if ( WC()->cart ) {
			// Set back the original cart contents.
			WC()->cart->set_cart_contents( $original_cart_contents );
		}

		return $result;
	}

}
