<?php
/**
 * Chat completion providers: OpenAI, Anthropic Claude and Google Gemini.
 *
 * All three are normalised behind `complete()`, which accepts a system prompt
 * and a list of `{role, content}` messages and returns `{text, usage, ...}`.
 * Provider-specific quirks (Anthropic's required `max_tokens`, Gemini's
 * `model` role, OpenAI's newer `max_completion_tokens`) are handled here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_LLM {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	public function __construct( OncologyNexus_Ask_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run a chat completion.
	 *
	 * @param string                            $system   System prompt.
	 * @param array<int,array{role:string,content:string}> $messages Conversation.
	 * @param array<string,mixed>               $args     model, temperature, max_tokens, timeout.
	 * @return array{text:string,usage:array<string,mixed>,provider:string,model:string,finish_reason:string}|WP_Error
	 */
	public function complete( $system, array $messages, array $args = array() ) {
		$config = $this->settings->chat_config();

		$args = wp_parse_args(
			$args,
			array(
				'model'       => $config['model'],
				'temperature' => $config['temperature'],
				'max_tokens'  => $config['max_tokens'],
				'timeout'     => 60,
			)
		);

		$provider = $config['provider'];
		$key      = $this->settings->api_key( $provider );
		if ( '' === $key ) {
			return new WP_Error(
				'onx_ask_missing_key',
				sprintf(
					/* translators: %s: provider. */
					__( 'No API key is configured for the %s chat provider.', 'onx-ask' ),
					$provider
				)
			);
		}

		$messages = $this->sanitize_messages( $messages );

		switch ( $provider ) {
			case 'anthropic':
				$result = $this->anthropic_complete( $system, $messages, $args, $key );
				break;
			case 'gemini':
				$result = $this->gemini_complete( $system, $messages, $args, $key );
				break;
			case 'openai':
			default:
				$result = $this->openai_complete( $system, $messages, $args, $key );
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['provider'] = $provider;
		$result['model']    = $args['model'];

		return $result;
	}

	/**
	 * Convenience wrapper for single-turn calls (query rewrite, tests).
	 *
	 * @param string              $system System.
	 * @param string              $user   User.
	 * @param array<string,mixed> $args   Args.
	 * @return string|WP_Error
	 */
	public function complete_simple( $system, $user, array $args = array() ) {
		$result = $this->complete( $system, array( array( 'role' => 'user', 'content' => $user ) ), $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['text'];
	}

	/**
	 * @param string              $system   System.
	 * @param array<int,array{role:string,content:string}> $messages Messages.
	 * @param array<string,mixed> $args     Args.
	 * @param string              $key      Key.
	 * @return array<string,mixed>|WP_Error
	 */
	private function openai_complete( $system, array $messages, array $args, $key ) {
		$base = $this->normalize_base( 'openai', $this->settings->get( 'openai_base_url', 'https://api.openai.com/v1' ) );
		$url  = $base . '/chat/completions';

		$payload = array_merge(
			array(
				'model'    => $args['model'],
				'messages' => array_merge(
					array( array( 'role' => 'system', 'content' => (string) $system ) ),
					$messages
				),
			),
			array(
				'max_tokens'  => (int) $args['max_tokens'],
				'temperature' => (float) $args['temperature'],
			)
		);

		$data = OncologyNexus_Ask_HTTP::post_json(
			$url,
			$payload,
			array( 'Authorization' => 'Bearer ' . $key ),
			(int) $args['timeout'],
			1
		);

		// Compatibility with reasoning models that renamed max_tokens and drop
		// temperature. Retry once with the newer field, then once without temp.
		if ( is_wp_error( $data ) ) {
			$message = $data->get_error_message();
			if ( false !== stripos( $message, 'max_tokens' ) ) {
				unset( $payload['max_tokens'] );
				$payload['max_completion_tokens'] = (int) $args['max_tokens'];
				$data = OncologyNexus_Ask_HTTP::post_json( $url, $payload, array( 'Authorization' => 'Bearer ' . $key ), (int) $args['timeout'], 0 );
			}
			if ( is_wp_error( $data ) && false !== stripos( $data->get_error_message(), 'temperature' ) ) {
				unset( $payload['temperature'] );
				$data = OncologyNexus_Ask_HTTP::post_json( $url, $payload, array( 'Authorization' => 'Bearer ' . $key ), (int) $args['timeout'], 0 );
			}
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$content = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		if ( is_array( $content ) ) {
			$pieces = array();
			foreach ( $content as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) ) {
					$pieces[] = (string) $part['text'];
				}
			}
			$content = implode( "\n", $pieces );
		}

		$content = trim( (string) $content );
		if ( '' === $content ) {
			return new WP_Error( 'onx_ask_empty_completion', __( 'The model returned an empty answer.', 'onx-ask' ) );
		}

		return array(
			'text'          => $content,
			'usage'         => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array(),
			'finish_reason' => isset( $data['choices'][0]['finish_reason'] ) ? (string) $data['choices'][0]['finish_reason'] : '',
		);
	}

	/**
	 * @param string              $system   System.
	 * @param array<int,array{role:string,content:string}> $messages Messages.
	 * @param array<string,mixed> $args     Args.
	 * @param string              $key      Key.
	 * @return array<string,mixed>|WP_Error
	 */
	private function anthropic_complete( $system, array $messages, array $args, $key ) {
		$base     = $this->normalize_base( 'anthropic', $this->settings->get( 'anthropic_base_url', 'https://api.anthropic.com/v1' ) );
		$url      = $base . '/messages';
		$messages = $this->alternate_messages( $messages );

		if ( empty( $messages ) ) {
			return new WP_Error( 'onx_ask_empty_prompt', __( 'There is no conversation to send.', 'onx-ask' ) );
		}

		$payload = array(
			'model'       => $args['model'],
			'system'      => (string) $system,
			'messages'    => $messages,
			'max_tokens'  => (int) $args['max_tokens'],
			'temperature' => (float) $args['temperature'],
		);

		$data = OncologyNexus_Ask_HTTP::post_json(
			$url,
			$payload,
			array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			),
			(int) $args['timeout'],
			1
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
					$text .= (string) $block['text'];
				}
			}
		}
		$text = trim( $text );

		if ( '' === $text ) {
			return new WP_Error( 'onx_ask_empty_completion', __( 'Claude returned an empty answer.', 'onx-ask' ) );
		}

		return array(
			'text'          => $text,
			'usage'         => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array(),
			'finish_reason' => isset( $data['stop_reason'] ) ? (string) $data['stop_reason'] : '',
		);
	}

	/**
	 * @param string              $system   System.
	 * @param array<int,array{role:string,content:string}> $messages Messages.
	 * @param array<string,mixed> $args     Args.
	 * @param string              $key      Key.
	 * @return array<string,mixed>|WP_Error
	 */
	private function gemini_complete( $system, array $messages, array $args, $key ) {
		$base  = $this->normalize_base( 'gemini', $this->settings->get( 'gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta' ) );
		$model = preg_replace( '#^models/#', '', (string) $args['model'] );
		$url   = add_query_arg( 'key', $key, $base . '/models/' . rawurlencode( $model ) . ':generateContent' );

		$contents = array();
		foreach ( $this->alternate_messages( $messages ) as $message ) {
			$contents[] = array(
				'role'  => 'assistant' === $message['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => (string) $message['content'] ) ),
			);
		}

		if ( empty( $contents ) ) {
			return new WP_Error( 'onx_ask_empty_prompt', __( 'There is no conversation to send.', 'onx-ask' ) );
		}

		$payload = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => (string) $system ) ),
			),
			'contents'          => $contents,
			'generationConfig'  => array(
				'temperature'     => (float) $args['temperature'],
				'maxOutputTokens' => (int) $args['max_tokens'],
			),
			'safetySettings'    => array(
				array(
					'category'  => 'HARM_CATEGORY_HARASSMENT',
					'threshold' => 'BLOCK_ONLY_HIGH',
				),
				array(
					'category'  => 'HARM_CATEGORY_HATE_SPEECH',
					'threshold' => 'BLOCK_ONLY_HIGH',
				),
				array(
					'category'  => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
					'threshold' => 'BLOCK_ONLY_HIGH',
				),
				array(
					'category'  => 'HARM_CATEGORY_DANGEROUS_CONTENT',
					'threshold' => 'BLOCK_ONLY_HIGH',
				),
			),
		);

		$data = OncologyNexus_Ask_HTTP::post_json( $url, $payload, array(), (int) $args['timeout'], 1 );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['candidates'][0]['content']['parts'] ) ) {
			$reason = '';
			if ( isset( $data['promptFeedback']['blockReason'] ) ) {
				$reason = (string) $data['promptFeedback']['blockReason'];
			} elseif ( isset( $data['candidates'][0]['finishReason'] ) ) {
				$reason = (string) $data['candidates'][0]['finishReason'];
			}
			return new WP_Error(
				'onx_ask_blocked',
				$reason
					? sprintf(
						/* translators: %s: Gemini block reason. */
						__( 'Gemini did not return an answer (%s). Try rephrasing the question.', 'onx-ask' ),
						$reason
					)
					: __( 'Gemini returned an empty answer.', 'onx-ask' )
			);
		}

		$text = '';
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}
		$text = trim( $text );

		if ( '' === $text ) {
			return new WP_Error( 'onx_ask_empty_completion', __( 'Gemini returned an empty answer.', 'onx-ask' ) );
		}

		return array(
			'text'          => $text,
			'usage'         => isset( $data['usageMetadata'] ) && is_array( $data['usageMetadata'] ) ? $data['usageMetadata'] : array(),
			'finish_reason' => isset( $data['candidates'][0]['finishReason'] ) ? (string) $data['candidates'][0]['finishReason'] : '',
		);
	}

	/**
	 * Normalise and bound client-supplied history.
	 *
	 * @param array<int,mixed> $messages Messages.
	 * @return array<int,array{role:string,content:string}>
	 */
	public function sanitize_messages( array $messages ) {
		$out = array();
		foreach ( array_slice( $messages, -10 ) as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}
			$role = 'assistant' === $message['role'] ? 'assistant' : 'user';
			$text = trim( wp_strip_all_tags( (string) $message['content'] ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'role'    => $role,
				'content' => mb_substr( $text, 0, 3000 ),
			);
		}
		return $out;
	}

	/**
	 * Merge consecutive roles and make sure the conversation starts with a user
	 * turn (required by Anthropic, preferred by Gemini).
	 *
	 * @param array<int,array{role:string,content:string}> $messages Messages.
	 * @return array<int,array{role:string,content:string}>
	 */
	private function alternate_messages( array $messages ) {
		$out = array();
		foreach ( $messages as $message ) {
			$role = 'assistant' === $message['role'] ? 'assistant' : 'user';
			$last = count( $out ) - 1;
			if ( $last >= 0 && $out[ $last ]['role'] === $role ) {
				$out[ $last ]['content'] .= "\n\n" . $message['content'];
				continue;
			}
			$out[] = array(
				'role'    => $role,
				'content' => $message['content'],
			);
		}

		while ( ! empty( $out ) && 'user' !== $out[0]['role'] ) {
			array_shift( $out );
		}

		return $out;
	}

	/**
	 * @param string $provider Provider.
	 * @param string $base     Base URL.
	 * @return string
	 */
	private function normalize_base( $provider, $base ) {
		$base = untrailingslashit( trim( (string) $base ) );
		if ( '' === $base ) {
			$defaults = array(
				'openai'    => 'https://api.openai.com/v1',
				'anthropic' => 'https://api.anthropic.com/v1',
				'gemini'    => 'https://generativelanguage.googleapis.com/v1beta',
			);
			$base = isset( $defaults[ $provider ] ) ? $defaults[ $provider ] : '';
		}

		if ( 'openai' === $provider && ! preg_match( '#/v\d+(beta)?$#', $base ) ) {
			$base .= '/v1';
		}
		if ( 'anthropic' === $provider && ! preg_match( '#/v\d+$#', $base ) ) {
			$base .= '/v1';
		}
		if ( 'gemini' === $provider && ! preg_match( '#/v\d+(beta)?$#', $base ) ) {
			$base .= '/v1beta';
		}

		return $base;
	}

	/**
	 * Validate a provider key via its models endpoint.
	 *
	 * @param string $provider openai|anthropic|gemini.
	 * @return array{ok:bool,message:string}
	 */
	public function test_connection( $provider ) {
		$provider = in_array( $provider, array( 'openai', 'anthropic', 'gemini' ), true ) ? $provider : 'openai';
		$key      = $this->settings->api_key( $provider );

		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: provider. */
					__( 'No API key configured for %s.', 'onx-ask' ),
					$provider
				),
			);
		}

		if ( 'openai' === $provider ) {
			$base = $this->normalize_base( 'openai', $this->settings->get( 'openai_base_url', 'https://api.openai.com/v1' ) );
			$data = OncologyNexus_Ask_HTTP::get_json( $base . '/models', array( 'Authorization' => 'Bearer ' . $key ), 20 );
		} elseif ( 'anthropic' === $provider ) {
			$base = $this->normalize_base( 'anthropic', $this->settings->get( 'anthropic_base_url', 'https://api.anthropic.com/v1' ) );
			$data = OncologyNexus_Ask_HTTP::get_json(
				$base . '/models',
				array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				),
				20
			);
		} else {
			$base = $this->normalize_base( 'gemini', $this->settings->get( 'gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta' ) );
			$data = OncologyNexus_Ask_HTTP::get_json( add_query_arg( 'key', $key, $base . '/models' ), array(), 20 );
		}

		if ( is_wp_error( $data ) ) {
			return array( 'ok' => false, 'message' => $data->get_error_message() );
		}

		return array( 'ok' => true, 'message' => __( 'Provider key is valid.', 'onx-ask' ) );
	}

	/**
	 * Suggested model names for the admin datalists.
	 *
	 * @param string $provider openai|anthropic|gemini|embedding-openai|embedding-gemini.
	 * @return string[]
	 */
	public static function model_suggestions( $provider ) {
		$models = array(
			'openai'           => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'o4-mini' ),
			'anthropic'        => array( 'claude-sonnet-4-5', 'claude-haiku-4-5', 'claude-opus-4-1', 'claude-3-5-haiku-latest' ),
			'gemini'           => array( 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash' ),
			'embedding-openai' => array( 'text-embedding-3-small', 'text-embedding-3-large' ),
			'embedding-gemini' => array( 'gemini-embedding-001', 'text-embedding-004' ),
		);

		return isset( $models[ $provider ] ) ? $models[ $provider ] : array();
	}
}
