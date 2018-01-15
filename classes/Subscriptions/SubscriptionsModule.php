<?php

namespace Pronamic\WordPress\Pay\Subscriptions;

/**
 * Title: Subscriptions module
 * Description:
 * Copyright: Copyright (c) 2005 - 2018
 * Company: Pronamic
 *
 * @see https://woocommerce.com/2017/04/woocommerce-3-0-release/
 * @see https://woocommerce.wordpress.com/2016/10/27/the-new-crud-classes-in-woocommerce-2-7/
 * @author Remco Tolsma
 * @version 3.7.0
 * @since 3.7.0
 */
class SubscriptionsModule {
	/**
	 * Construct and initialize a subscriptions module object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		// Actions
		add_action( 'wp_loaded', array( $this, 'handle_subscription' ) );

		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 5 );

		// Exclude payment and subscription notes
		add_filter( 'comments_clauses', array( $this, 'exclude_subscription_comment_notes' ), 10, 2 );

		add_action( 'pronamic_pay_new_payment', array( $this, 'maybe_create_subscription' ) );

		// The 'pronamic_pay_update_subscription_payments' hook adds subscription payments and sends renewal notices
		add_action( 'pronamic_pay_update_subscription_payments', array( $this, 'update_subscription_payments' ) );

		// The 'pronamic_pay_subscription_completed' hook is scheduled to update the subscriptions status when subscription ends
		add_action( 'pronamic_pay_subscription_completed', array( $this, 'subscription_completed' ) );
	}

	/**
	 * Handle subscription action
	 */
	public function handle_subscription() {
		if ( ! filter_has_var( INPUT_GET, 'subscription' ) ) {
			return;
		}

		if ( ! filter_has_var( INPUT_GET, 'action' ) ) {
			return;
		}

		if ( ! filter_has_var( INPUT_GET, 'key' ) ) {
			return;
		}

		$subscription_id = filter_input( INPUT_GET, 'subscription', FILTER_SANITIZE_STRING );
		$subscription    = get_pronamic_subscription( $subscription_id );

		$action = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_STRING );

		$key = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_STRING );

		// Check if subscription is valid
		if ( ! $subscription ) {
			return;
		}

		// Check if subscription key is valid
		if ( $key !== $subscription->get_key() ) {
			wp_redirect( home_url() );

			exit;
		}

		// Check if we should redirect
		$should_redirect = true;

		switch ( $action ) {
			case 'cancel':
				if ( Pronamic_WP_Pay_Statuses::CANCELLED !== $subscription->get_status() ) {
					$subscription->update_status( Pronamic_WP_Pay_Statuses::CANCELLED );

					$this->update_subscription( $subscription, $should_redirect );
				}

				break;
			case 'renew':
				$first   = $subscription->get_first_payment();
				$gateway = Pronamic_WP_Pay_Plugin::get_gateway( $first->config_id );

				if ( Pronamic_WP_Pay_Statuses::SUCCESS !== $subscription->get_status() ) {
					$payment = $this->start_recurring( $subscription, $gateway, true );

					if ( ! $gateway->has_error() ) {
						// Redirect
						$gateway->redirect( $payment );
					}
				}

				wp_redirect( home_url() );

				exit;
		}
	}

	public function start_recurring( Pronamic_Pay_Subscription $subscription, Pronamic_WP_Pay_Gateway $gateway, $renewal = false ) {
		$recurring = ! $renewal;
		$first     = $subscription->get_first_payment();
		$data      = new Pronamic_WP_Pay_RecurringPaymentData( $subscription->get_id(), $recurring );

		$payment = Pronamic_WP_Pay_Plugin::start( $first->config_id, $gateway, $data, $first->method );

		return $payment;
	}

	public function update_subscription( $subscription = null, $can_redirect = true ) {
		if ( empty( $subscription ) ) {
			return;
		}

		$this->plugin->subscriptions_data_store->update( $subscription );

		if ( defined( 'DOING_CRON' ) && empty( $subscription->status ) ) {
			$can_redirect = false;
		}

		if ( $can_redirect ) {
			wp_redirect( home_url() );

			exit;
		}
	}

	public function plugins_loaded() {
		$this->maybe_schedule_subscription_payments();
	}

	/**
	 * Comments clauses.
	 *
	 * @param array $clauses
	 * @param WP_Comment_Query $query
	 * @return array
	 */
	public function exclude_subscription_comment_notes( $clauses, $query ) {
		$type = $query->query_vars['type'];

		// Ignore subscription notes comments if it's not specifically requested
		if ( 'subscription_note' !== $type ) {
			$clauses['where'] .= " AND comment_type != 'subscription_note'";
		}

		return $clauses;
	}

	/**
	 * Maybe schedule subscription payments.
	 */
	public function maybe_schedule_subscription_payments() {
		if ( wp_next_scheduled( 'pronamic_pay_update_subscription_payments' ) ) {
			return;
		}

		wp_schedule_event( time(), 'hourly', 'pronamic_pay_update_subscription_payments' );
	}

	/**
	 * Maybe create subscription for the specified payment.
	 *
	 * @param Payment $payment
	 */
	public function maybe_create_subscription( $payment ) {
		// Check if there is already subscription attached to the payment.
		$subscription_id = $payment->get_subscription_id();

		if ( ! empty( $subscription_id ) ) {
			// Subscription already created.
			return;
		}

		// Check if there is a subscription object attached to the payment.
		$subscription_data = $payment->subscription;

		if ( empty( $subscription_data ) ) {
			return;
		}

		// New subscription
		$subscription = new Pronamic_WP_Pay_Subscription();

		$subscription->user_id         = $payment->user_id;
		$subscription->title           = sprintf( __( 'Subscription for %s', 'pronamic_ideal' ), $payment->title );
		$subscription->frequency       = $subscription_data->get_frequency();
		$subscription->interval        = $subscription_data->get_interval();
		$subscription->interval_period = $subscription_data->get_interval_period();
		$subscription->currency        = $subscription_data->get_currency();
		$subscription->amount          = $subscription_data->get_amount();
		$subscription->key             = uniqid( 'subscr_' );
		$subscription->source          = $payment->source;
		$subscription->source_id       = $payment->source_id;
		$subscription->description     = $payment->description;
		$subscription->email           = $payment->email;
		$subscription->customer_name   = $payment->customer_name;
		$subscription->first_payment   = $payment->date_gmt;

		// @todo
		// Calculate dates
		// @see https://github.com/pronamic/wp-pronamic-ideal/blob/4.7.0/classes/Pronamic/WP/Pay/Plugin.php#L883-L964

		// Create
		$result = $this->plugin->subscriptions_data_store->create( $subscription );

		if ( $result ) {
			$payment->subscription_id = $subscription->get_id();

			$this->plugin->paymens_data_store->update( $payment );
		}
	}

	/**
	 * Update subscription payments.
	 */
	public function update_subscription_payments() {
		$this->send_subscription_renewal_notices();

		// Don't create payments for sources which schedule payments
		$sources = array(
			'woocommerce',
		);

		$args = array(
			'post_type'   => 'pronamic_pay_subscr',
			'nopaging'    => true,
			'orderby'     => 'post_date',
			'order'       => 'ASC',
			'post_status' => array(
				'subscr_pending',
				'subscr_expired',
				'subscr_failed',
				'subscr_active',
			),
			'meta_query'  => array(
				array(
					'key'     => '_pronamic_subscription_source',
					'value'   => $sources,
					'compare' => 'NOT IN',
				),
				array(
					'key'     => '_pronamic_subscription_next_payment',
					'value'   => current_time( 'mysql', true ),
					'compare' => '<=',
					'type'    => 'DATETIME',
				),
			),
		);

		$query = new WP_Query( $args );

		foreach ( $query->posts as $post ) {
			$subscription = new Pronamic_WP_Pay_Subscription( $post->ID );
			$first        = $subscription->get_first_payment();
			$gateway      = Pronamic_WP_Pay_Plugin::get_gateway( $first->config_id );

			$payment = self::start_recurring( $subscription, $gateway );

			if ( $payment ) {
				self::update_payment( $payment, false );
			}
		}
	}

	/**
	 * Send renewal notices.
	 */
	public function send_subscription_renewal_notices() {
		$args = array(
			'post_type'   => 'pronamic_pay_subscr',
			'nopaging'    => true,
			'orderby'     => 'post_date',
			'order'       => 'ASC',
			'post_status' => array(
				'subscr_pending',
				'subscr_expired',
				'subscr_failed',
				'subscr_active',
			),
			'meta_query'  => array(
				array(
					'key'     => '_pronamic_subscription_renewal_notice',
					'value'   => current_time( 'mysql', true ),
					'compare' => '<=',
					'type'    => 'DATETIME',
				),
			),
		);

		$query = new WP_Query( $args );

		foreach ( $query->posts as $post ) {
			$subscription = new Pronamic_WP_Pay_Subscription( $post->ID );

			do_action( 'pronamic_subscription_renewal_notice_' . $subscription->get_source(), $subscription );

			// Set next renewal date meta
			$next_renewal = $subscription->get_next_payment_date( 1 );

			if ( $next_renewal ) {
				$next_renewal->modify( '-1 week' );

				// If next renewal notice date is before next payment date,
				// prevent duplicate renewal messages by setting the renewal
				// notice date to the date of next payment.
				if ( $next_renewal < $subscription->get_next_payment_date() ) {
					$next_renewal = $subscription->get_next_payment_date();
				}
			}

			// Update or delete next renewal notice date meta.
			$subscription->set_renewal_notice_date( $next_renewal );
		}
	}

	/**
	 * Subscription completed.
	 *
	 * @param string $subscription_id
	 */
	public function subscription_completed( $subscription_id ) {
		$subscription = new Pronamic_WP_Pay_Subscription( $subscription_id );

		if ( ! isset( $subscription->post ) ) {
			return;
		}

		$subscription->update_status( Pronamic_WP_Pay_Statuses::COMPLETED );

		$this->update_subscription( $subscription, false );
	}
}
