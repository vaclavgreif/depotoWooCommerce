<?php

/** Sanitized export diagnostics. Never pass raw payloads, responses or exceptions to WC_Logger. */
class Depoto_Diagnostics {
	public static function write( $level, $message, array $context = [] ): void {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				$context['source'] = 'depoto-diagnostics';
				wc_get_logger()->log( $level, $message, $context );
			}
		} catch ( \Throwable $ignored ) {
			// A logging failure must never change whether an order is sent.
		}
	}

	public static function items( array $items ): array {
		$summary = [];
		foreach ( array_values( $items ) as $index => $item ) {
			$line = [
				'line' => $index + 1,
				'type' => in_array( $item['type'] ?? '', [ 'product', 'shipping', 'payment' ], true ) ? $item['type'] : 'other',
				'vat_present' => array_key_exists( 'vat', $item ),
			];
			foreach ( [ 'quantity', 'price', 'vat' ] as $key ) {
				if ( array_key_exists( $key, $item ) ) {
					$line[ $key ] = null === $item[ $key ] || is_numeric( $item[ $key ] ) ? $item[ $key ] : '[non-numeric]';
				}
			}
			$summary[] = $line;
		}
		return $summary;
	}

	public static function failure( $event, \Throwable $error, array $context = [] ): void {
		// Only extract known diagnostic signatures. Server messages can echo personal data or tokens.
		$message = $error->getMessage();
		$context['exception_class'] = get_class( $error );
		$context['error_code'] = (int) $error->getCode();
		$context['error_category'] = 'unclassified (raw message withheld)';
		if ( $error instanceof UnexpectedValueException ) {
			$context['error_category'] = 'invalid export configuration';
		}
		if ( preg_match( '/SQLSTATE\[([A-Z0-9]{5})\]/', $message, $matches ) ) {
			$context['sqlstate'] = $matches[1];
			$context['error_category'] = 'database error';
		}
		if ( strpos( $message, '1048' ) !== false && strpos( $message, 'vat_id' ) !== false ) {
			$context['error_category'] = 'Column vat_id cannot be null (1048)';
		} elseif ( strpos( $message, 'Vybraná možnosť nie je platná' ) !== false || strpos( $message, 'This value is not valid' ) !== false ) {
			$context['error_category'] = 'invalid selection';
		}
		try {
			if ( method_exists( $error, 'getResponse' ) ) {
				$context['http_status'] = (int) $error->getResponse()->getStatusCode();
			}
		} catch ( \Throwable $ignored ) {
			// Preserve the original exception even if response metadata is unavailable.
		}
		self::write( 'error', $event, $context );
	}

	/** Webhook payloads are untrusted; retain only IDs, counters and known internal reasons. */
	public static function webhook_context( array $context ): array {
		$record = isset( $context['record'] ) && is_array( $context['record'] ) ? $context['record'] : [];
		$result = isset( $context['context'] ) && is_array( $context['context'] ) ? $context['context'] : [];
		$safe = [];
		foreach ( [ 'id', 'action_id', 'next_action_id', 'attempts', 'quantity' ] as $key ) {
			$value = $context[ $key ] ?? $record[ $key ] ?? $result[ $key ] ?? null;
			if ( is_numeric( $value ) ) {
				$safe[ $key ] = (int) $value;
			}
		}
		$type = $context['type'] ?? $record['type'] ?? null;
		if ( null !== $type ) {
			$safe['type'] = in_array( $type, [ 'product.availability', 'order.processStatus', 'order.status', 'order.update' ], true ) ? $type : 'unsupported';
		}
		$reason = $result['reason'] ?? null;
		if ( in_array( $reason, [ 'invalid_processor_result', 'depoto_unavailable', 'product_not_found', 'depoto_status_missing', 'order_not_found', 'already_matches', 'no_matching_woo_state' ], true ) ) {
			$safe['reason'] = $reason;
		}
		return $safe;
	}
}
