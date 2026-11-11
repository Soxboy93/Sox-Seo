<?php
/**
 * Anthropic Messages API client.
 *
 * Uses the WordPress HTTP API (wp_remote_post) rather than the Anthropic PHP
 * SDK so the plugin installs as a plain zip with no Composer step and honours
 * the site's proxy / SSL configuration.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Anthropic {

	const ENDPOINT      = 'https://api.anthropic.com/v1/messages';
	const API_VERSION   = '2026-10-21';
	const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
	const USAGE_OPTION  = 'sox_seo_usage';

	/**
	 * Models that accept the server-side `fallbacks` parameter. If a safety
	 * classifier declines a request, the API re-runs it on a fallback model
	 * inside the same call instead of returning nothing.
	 *
	 * @var string[]
	 */
	private static $fallback_models = array( 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-2' );

	/**
	 * Ask Claude for a JSON object matching a schema (structured outputs).
	 *
	 * @param string       $system     System prompt.
	 * @param string|array $content    User content: a string, or an array of content blocks.
	 * @param array        $schema     JSON schema for the reply.
	 * @param int          $max_tokens Output cap.
	 * @return array|WP_Error Decoded JSON object.
	 */
	public static function json( $system, $content, array $schema, $max_tokens = 16000 ) {
		$body = array(
			'model'         => Sox_SEO_Options::get( 'model' ),
			'max_tokens'    => $max_tokens,
			'system'        => $system,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'output_config' => array(
				'effort' => Sox_SEO_Options::get( 'effort' ),
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
		);

		$message = self::send( $body );
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		$text = '';
		foreach ( $message['content'] as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text = $block['text'];
				break;
			}
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sox_seo_bad_json', __( 'Claude returned a reply that could not be read as JSON.', 'sox-seo' ) );
		}
		return $data;
	}

	/**
	 * Send a Messages API request.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error Decoded message.
	 */
	public static function send( array $body ) {
		$key = Sox_SEO_Options::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'sox_seo_no_key', __( 'No Anthropic API key is set. Add one under Sox SEO → Settings.', 'sox-seo' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$use_fallbacks = in_array( $body['model'], self::$fallback_models, true );
		$result        = self::post( $key, $body, $use_fallbacks );

		// The fallback parameter is a beta. If the API rejects it for this
		// account or model, the request itself is still fine: send it plain.
		if ( $use_fallbacks && is_wp_error( $result ) && 'sox_seo_api_400' === $result->get_error_code()
			&& false !== stripos( $result->get_error_message(), 'fallback' ) ) {
			$result = self::post( $key, $body, false );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::record_usage( $result );

		$stop = isset( $result['stop_reason'] ) ? $result['stop_reason'] : '';
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'sox_seo_refusal', __( 'Claude declined this request. Try a different model or review the content.', 'sox-seo' ) );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'sox_seo_truncated', __( 'Claude ran out of output tokens before finishing. Try again.', 'sox-seo' ) );
		}
		if ( empty( $result['content'] ) || ! is_array( $result['content'] ) ) {
			return new WP_Error( 'sox_seo_empty', __( 'Claude returned an empty reply.', 'sox-seo' ) );
		}
		return $result;
	}

	/**
	 * One HTTP round trip, with a single retry on rate limits and overloads.
	 *
	 * @param string $key           API key.
	 * @param array  $body          Request body.
	 * @param bool   $use_fallbacks Whether to opt into server-side fallbacks.
	 * @return array|WP_Error
	 */
	private static function post( $key, array $body, $use_fallbacks ) {
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $key,
			'anthropic-version' => self::API_VERSION,
		);
		if ( $use_fallbacks ) {
			$headers['anthropic-beta'] = self::FALLBACK_BETA;
			$body['fallbacks']         = 'default';
		}

		$args = array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 180,
		);

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$response = wp_remote_post( self::ENDPOINT, $args );
			if ( is_wp_error( $response ) ) {
				if ( 0 === $attempt ) {
					continue;
				}
				return new WP_Error( 'sox_seo_http', $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && is_array( $data ) ) {
				return $data;
			}

			$retryable = in_array( $code, array( 429, 500, 502, 503, 529 ), true );
			if ( $retryable && 0 === $attempt ) {
				$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( max( 1, min( 10, $wait ? $wait : 2 ) ) );
				continue;
			}

			$detail = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unexpected response.', 'sox-seo' );
			if ( 401 === $code ) {
				$detail = __( 'The Anthropic API key was rejected. Check it under Sox SEO → Settings.', 'sox-seo' );
			}
			/* translators: 1: HTTP status code, 2: error detail */
			return new WP_Error( 'sox_seo_api_' . $code, sprintf( __( 'Anthropic API error %1$d: %2$s', 'sox-seo' ), $code, $detail ) );
		}

		return new WP_Error( 'sox_seo_http', __( 'The Anthropic API could not be reached.', 'sox-seo' ) );
	}

	/**
	 * Keep a per-month token tally so the settings page can show spend drivers.
	 *
	 * @param array $message API response.
	 */
	private static function record_usage( array $message ) {
		if ( empty( $message['usage'] ) ) {
			return;
		}
		$usage = get_option( self::USAGE_OPTION, array() );
		$usage = is_array( $usage ) ? $usage : array();
		$month = gmdate( 'Y-m' );
		if ( ! isset( $usage[ $month ] ) ) {
			$usage[ $month ] = array(
				'requests' => 0,
				'input'    => 0,
				'output'   => 0,
			);
		}
		$usage[ $month ]['requests']++;
		$usage[ $month ]['input']  += isset( $message['usage']['input_tokens'] ) ? (int) $message['usage']['input_tokens'] : 0;
		$usage[ $month ]['output'] += isset( $message['usage']['output_tokens'] ) ? (int) $message['usage']['output_tokens'] : 0;
		$usage                      = array_slice( $usage, -6, null, true );
		update_option( self::USAGE_OPTION, $usage, false );
	}

	/**
	 * Cheap round trip used by the "Test connection" button.
	 *
	 * @return true|WP_Error
	 */
	public static function test() {
		$result = self::json(
			'You are a connectivity check.',
			'Reply with ok set to true.',
			array(
				'type'                 => 'object',
				'properties'           => array( 'ok' => array( 'type' => 'boolean' ) ),
				'required'             => array( 'ok' ),
				'additionalProperties' => false,
			),
			1024
		);
		return is_wp_error( $result ) ? $result : true;
	}
}
