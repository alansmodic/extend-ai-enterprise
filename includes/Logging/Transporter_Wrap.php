<?php
/**
 * Wraps the AI Client SDK's HTTP transporter so we get a real event for every
 * provider request. WP AI's logging experiment doesn't fire one — it writes
 * straight to its repository — so we install our own decorator on top.
 *
 * Emits action `extend_ai_request_completed` with a payload that mirrors WP AI's
 * canonical log shape (`provider, model, duration_ms, tokens_input, tokens_output,
 * status, error_message, user_id, context`). Token counts are best-effort: we
 * parse the response body for common provider shapes (OpenAI / Anthropic / Google).
 * Unknown providers report 0 tokens and the consumer can fall back to byte-cost.
 *
 * Hook order: WP AI's Logging_Integration wraps the transporter on `wp_loaded` /
 * `admin_init` at priority 1. We register at priority 20 so our decorator wraps
 * *theirs*. Call chain becomes:
 *
 *   Ours::send() → WP_AI_Logging::send() → real provider transporter
 *
 * Both decorators see every request; only ours emits the action.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Logging;

final class Transporter_Wrap {

	public const FAILURE_TRANSIENT = 'extend_ai_transporter_wrap_failure';

	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'wrap' ), 20 );
		add_action( 'admin_init', array( $this, 'wrap' ), 20 );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	public function wrap(): void {
		static $wrapped = false;
		if ( $wrapped ) {
			return;
		}

		$reason = null;

		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			$reason = 'AiClient class missing';
		} elseif ( ! interface_exists( '\\WordPress\\AiClient\\Providers\\Http\\Contracts\\HttpTransporterInterface' ) ) {
			$reason = 'HttpTransporterInterface missing';
		} else {
			try {
				$registry = \WordPress\AiClient\AiClient::defaultRegistry();
				$inner    = $registry->getHttpTransporter();
				$registry->setHttpTransporter( self::decorator( $inner ) );
				$wrapped = true;
			} catch ( \Throwable $e ) {
				$reason = '' !== $e->getMessage() ? $e->getMessage() : 'unknown exception';
			}
		}

		if ( $reason !== null ) {
			self::record_failure( $reason );
			return;
		}

		// Wrap succeeded — clear any prior failure flag.
		delete_transient( self::FAILURE_TRANSIENT );
	}

	private static function record_failure( string $reason ): void {
		set_transient( self::FAILURE_TRANSIENT, $reason, DAY_IN_SECONDS );

		/**
		 * Fires when the transporter wrap fails. Cost tracking and rate
		 * enforcement at the transport layer will not function until this is
		 * resolved. Subscribers should page on this.
		 *
		 * @param string $reason Short reason string.
		 */
		do_action( 'extend_ai_transporter_wrap_failed', $reason );
	}

	public function maybe_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$reason = get_transient( self::FAILURE_TRANSIENT );
		if ( ! is_string( $reason ) || $reason === '' ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>%s</code></p></div>',
			esc_html__( 'Extend AI — Enterprise:', 'extend-ai-enterprise' ),
			esc_html__( 'Could not wrap the AI Client transporter. Cost tracking and transport-layer enforcement are inactive. Reason:', 'extend-ai-enterprise' ),
			esc_html( $reason )
		);
	}

	/**
	 * Build the decorator as an anonymous class so the `implements` is only
	 * resolved when the SDK interface is confirmed available.
	 */
	private static function decorator( object $inner ): object {
		return new class( $inner ) implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {

			public function __construct( private object $inner ) {}

			public function send(
				\WordPress\AiClient\Providers\Http\DTO\Request $request,
				?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null
			): \WordPress\AiClient\Providers\Http\DTO\Response {

				$start  = microtime( true );
				$status = 'success';
				$err    = null;
				$resp   = null;

				try {
					$resp = $this->inner->send( $request, $options );
					return $resp;
				} catch ( \Throwable $e ) {
					$status = 'error';
					$err    = $e->getMessage();
					throw $e;
				} finally {
					$payload = self::shape(
						$request,
						$resp,
						(int) ( ( microtime( true ) - $start ) * 1000 ),
						$status,
						$err
					);
					/**
					 * Fires after every AI provider HTTP request completes.
					 *
					 * @param array{
					 *     provider:string, model:string, duration_ms:int,
					 *     tokens_input:int, tokens_output:int, status:string,
					 *     error_message:?string, user_id:int, context:array<string,mixed>
					 * } $payload
					 */
					do_action( 'extend_ai_request_completed', $payload );
				}
			}

			/**
			 * @return array{
			 *   provider:string, model:string, duration_ms:int,
			 *   tokens_input:int, tokens_output:int, status:string,
			 *   error_message:?string, user_id:int, context:array<string,mixed>
			 * }
			 */
			private static function shape( object $request, ?object $response, int $duration_ms, string $status, ?string $err ): array {
				$uri      = method_exists( $request, 'getUri' ) ? (string) $request->getUri() : '';
				$provider = self::provider_from_host( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
				$body     = method_exists( $request, 'getBody' ) ? (string) $request->getBody() : '';
				$decoded  = json_decode( $body, true );
				$model    = is_array( $decoded ) ? (string) ( $decoded['model'] ?? '' ) : '';

				$tokens_in  = 0;
				$tokens_out = 0;
				if ( $response && method_exists( $response, 'getBody' ) ) {
					[ $tokens_in, $tokens_out ] = self::tokens_from_response( (string) $response->getBody() );
				}

				return array(
					'provider'      => $provider,
					'model'         => $model,
					'duration_ms'   => $duration_ms,
					'tokens_input'  => $tokens_in,
					'tokens_output' => $tokens_out,
					'status'        => $status,
					'error_message' => $err,
					'user_id'       => get_current_user_id(),
					'context'       => array( 'uri' => $uri ),
				);
			}

			private static function provider_from_host( string $host ): string {
				return match ( true ) {
					str_contains( $host, 'openai.com' )    => 'openai',
					str_contains( $host, 'anthropic.com' ) => 'anthropic',
					str_contains( $host, 'googleapis.com' ) => 'google',
					str_contains( $host, 'azure.com' )     => 'azure',
					default                                => $host,
				};
			}

			/** @return array{0:int,1:int} */
			private static function tokens_from_response( string $body ): array {
				$json = json_decode( $body, true );
				if ( ! is_array( $json ) ) {
					return array( 0, 0 );
				}
				$usage = $json['usage'] ?? null;
				if ( ! is_array( $usage ) ) {
					return array( 0, 0 );
				}
				// OpenAI: prompt_tokens / completion_tokens. Anthropic: input_tokens / output_tokens.
				$in  = (int) ( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0 );
				$out = (int) ( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0 );
				return array( $in, $out );
			}
		};
	}
}
