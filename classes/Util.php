<?php

namespace Pronamic\WordPress\Pay;

use DateTime;
use SimpleXMLElement;
use WP_Error;

/**
 * Title: WordPress utility class
 * Description:
 * Copyright: Copyright (c) 2005 - 2018
 * Company: Pronamic
 *
 * @author Remco Tolsma
 * @version 1.0
 */
class Util {
	/**
	 * Remote get body
	 *
	 * @param string $url
	 * @param int $required_response_code
	 * @param array $args
	 *
	 * @return string|WP_Error
	 */
	public static function remote_get_body( $url, $required_response_code = 200, array $args = array() ) {
		$result = wp_remote_request( $url, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response_code = wp_remote_retrieve_response_code( $result );

		if ( $response_code === $required_response_code ) {
			return wp_remote_retrieve_body( $result );
		}

		return new WP_Error(
			'wrong_response_code',
			sprintf(
				__( 'The response code (<code>%1$s<code>) was incorrect, required response code <code>%2$s</code>.', 'pronamic_ideal' ),
				$response_code,
				$required_response_code
			)
		);
	}

	//////////////////////////////////////////////////

	/**
	 * SimpleXML load string
	 *
	 * @param string $string
	 *
	 * @return SimpleXMLElement|WP_Error
	 */
	public static function simplexml_load_string( $string ) {
		$result = false;

		// Suppress all XML errors
		$use_errors = libxml_use_internal_errors( true );

		// Load
		$xml = simplexml_load_string( $string );

		if ( false !== $xml ) {
			$result = $xml;
		} else {
			$error = new WP_Error( 'simplexml_load_error', __( 'Could not load the XML string.', 'pronamic_ideal' ) );

			foreach ( libxml_get_errors() as $e ) {
				$error->add( 'libxml_error', $e->message, $e );
			}

			libxml_clear_errors();

			$result = $error;
		}

		// Set back to previous value
		libxml_use_internal_errors( $use_errors );

		return $result;
	}

	//////////////////////////////////////////////////

	/**
	 * Amount to cents
	 *
	 * @param float $price
	 *
	 * @return int
	 */
	public static function amount_to_cents( $price ) {
		return round( $price * 100 );
	}

	/**
	 * Cents to amount
	 *
	 * @param int $cents
	 *
	 * @return float
	 */
	public static function cents_to_amount( $cents ) {
		return $cents / 100;
	}

	//////////////////////////////////////////////////

	/**
	 * Convert boolean to an numceric boolean
	 *
	 * @see https://github.com/eet-nu/buckaroo-ideal/blob/master/lib/buckaroo-ideal/request.rb#L136
	 *
	 * @param boolean $boolean
	 *
	 * @return int
	 */
	public static function boolean_to_numeric( $boolean ) {
		return $boolean ? 1 : 0;
	}

	//////////////////////////////////////////////////

	/**
	 * Convert boolean to an string boolean
	 *
	 * @see https://github.com/eet-nu/buckaroo-ideal/blob/master/lib/buckaroo-ideal/request.rb#L136
	 *
	 * @param boolean $boolean
	 *
	 * @return int
	 */
	public static function boolean_to_string( $boolean ) {
		return $boolean ? 'true' : 'false';
	}

	//////////////////////////////////////////////////

	public static function format_date( $format, DateTime $date = null ) {
		$result = null;

		if ( null !== $date ) {
			$result = $date->format( $format );
		}

		return $result;
	}

	/**
	 * Format price.
	 *
	 * @see https://github.com/woothemes/woocommerce/blob/v2.2.3/includes/wc-formatting-functions.php#L306-L347
	 * @see https://github.com/woothemes/woocommerce/blob/v2.2.3/includes/wc-core-functions.php#L299-L376
	 */
	public static function format_price( $amount, $currency = null ) {
		$float = filter_var( $amount, FILTER_VALIDATE_FLOAT );

		if ( false === $float ) {
			return;
		}

		$currency = ( null === $currency ) ? 'EUR' : $currency;

		$currency_symbol = $currency;

		switch ( $currency ) {
			case 'EUR' :
				$currency_symbol = '€';
				break;
			case 'USD' :
				$currency_symbol = '$';
				break;
		}

		// @see https://en.wikipedia.org/wiki/Non-breaking_space#Keyboard_entry_methods
		$non_breaking_space = ' ';

		return '' . $currency_symbol . $non_breaking_space . number_format_i18n( $float, 2 );
	}

	/**
	 * Format interval.
	 */
	public static function format_interval( $interval, $period ) {
		switch ( $period ) {
			case 'D' :
			case 'day' :
			case 'days' :
				return sprintf( _n( 'Every %s day', 'Every %s days', $interval, 'pronamic_ideal' ), $interval );
			case 'W' :
			case 'week' :
			case 'weeks' :
				return sprintf( _n( 'Every %s week', 'Every %s weeks', $interval, 'pronamic_ideal' ), $interval );
			case 'M' :
			case 'month' :
			case 'months' :
				return sprintf( _n( 'Every %s month', 'Every %s months', $interval, 'pronamic_ideal' ), $interval );
			case 'Y' :
			case 'year' :
			case 'years' :
				return sprintf( _n( 'Every %s year', 'Every %s years', $interval, 'pronamic_ideal' ), $interval );
		}
	}

	/**
	 * Convert single interval period character to full name.
	 *
	 * @param $interval_period string Short interval period (D, W, M or Y)
	 *
	 * @return string
	 */
	public static function to_interval_name( $interval_period ) {
		switch ( $interval_period ) {
			case 'D' :
				return 'days';
			case 'W' :
				return 'weeks';
			case 'M' :
				return 'months';
			case 'Y' :
				return 'years';
		}

		return $interval_period;
	}

	/**
	 * Format frequency.
	 */
	public static function format_frequency( $frequency ) {
		if ( '' === $frequency ) {
			return _x( 'Unlimited', 'Recurring payment', 'pronamic_ideal' );
		}

		return sprintf( _n( '%s time', '%s times', $frequency, 'pronamic_ideal' ), $frequency );
	}

	//////////////////////////////////////////////////

	/**
	 * Build URL with the specified parameters
	 *
	 * @param string $url
	 * @param array $parameters
	 *
	 * @return string
	 */
	public static function build_url( $url, array $parameters ) {
		return $url . '?' . _http_build_query( $parameters, null, '&' );
	}

	/**
	 * Get hidden inputs HTML for data.
	 *
	 * @param $data array
	 *
	 * @return string
	 */
	public static function html_hidden_fields( $data ) {
		$html = '';

		foreach ( $data as $name => $value ) {
			$html .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) );
		}

		return $html;
	}

	/**
	 * Array to HTML attributes
	 *
	 * @param array $pieces
	 */
	public static function array_to_html_attributes( array $attributes ) {
		$html = '';

		foreach ( $attributes as $key => $value ) {
			$html .= sprintf( '%s="%s"', $key, esc_attr( $value ) );
		}

		$html = trim( $html );

		return $html;
	}

	/**
	 * Select options grouped
	 *
	 * @param array  $groups
	 * @param string $selected_value
	 *
	 * @return string
	 */
	public static function select_options_grouped( $groups, $selected_value = null ) {
		$html = '';

		if ( is_array( $groups ) ) {
			foreach ( $groups as $group ) {
				$optgroup = isset( $group['name'] ) && ! empty( $group['name'] );

				if ( $optgroup ) {
					$html .= '<optgroup label="' . $group['name'] . '">';
				}

				foreach ( $group['options'] as $value => $label ) {
					$html .= '<option value="' . $value . '" ' . selected( $selected_value, $value, false ) . '>' . $label . '</option>';
				}

				if ( $optgroup ) {
					$html .= '</optgroup>';
				}
			}
		}

		return $html;
	}
}
