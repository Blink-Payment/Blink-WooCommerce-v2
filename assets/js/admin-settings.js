/* global jQuery */

( function ( $ ) {
	'use strict';

	$( function () {
		const $settings = $( '.blink-settings' );

		if ( ! $settings.length ) {
			return;
		}

		$settings.on( 'change', '#woocommerce_blink_testmode', function () {
			const $status = $settings.find( '[data-blink-test-mode-status]' );
			const isTestMode = $( this ).is( ':checked' );

			$status
				.toggleClass( 'is-test', isTestMode )
				.toggleClass( 'is-live', ! isTestMode )
				.text(
					isTestMode
						? $status.data( 'test-label' )
						: $status.data( 'live-label' )
				);
		} );

		$settings.on(
			'change',
			'#woocommerce_blink_integration_type',
			function () {
				const $select = $( this );
				const description = $select.attr(
					'data-' + $select.val() + '-description'
				);

				$select
					.closest( 'fieldset' )
					.find( '.description' )
					.text( description );
			}
		);

		$settings.on(
			'change',
			'#woocommerce_blink_apple_pay_enabled',
			function () {
				const $status = $settings.find(
					'[data-blink-apple-pay-status]'
				);
				const isEnabled = $( this ).is( ':checked' );

				$status
					.toggleClass( 'is-enabled', isEnabled )
					.toggleClass( 'is-disabled', ! isEnabled )
					.text(
						isEnabled
							? $status.data( 'enabled-label' )
							: $status.data( 'disabled-label' )
					);
			}
		);
	} );
} )( jQuery );
