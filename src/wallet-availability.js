/**
 * Decide which Blink wallet elements WooCommerce should expose.
 *
 * Blink's Apple Pay integration performs shopper browser/device eligibility.
 * This decision only enforces the merchant setting and presence of the element
 * returned by Blink. Browser and device eligibility remains owned by Blink.
 *
 * @param {Object} settings Checkout settings.
 * @param {Object} elements Elements returned with the Blink intent.
 * @return {{showApplePay: boolean, showGooglePay: boolean, showWalletRow: boolean}} Wallet visibility.
 */
export const getWalletAvailability = ( settings = {}, elements = {} ) => {
	const showApplePay = !! settings.apple_pay_enabled && !! elements.apElement;
	const showGooglePay = !! elements.gpElement;
	const showWalletRow = showApplePay || showGooglePay;

	return { showApplePay, showGooglePay, showWalletRow };
};

/**
 * Observe Blink removing its Apple Pay button and remove WooCommerce's wrapper.
 *
 * This reacts only to Blink's eligibility decision; it does not make one.
 *
 * @param {Element}  container WooCommerce-owned Apple Pay wrapper.
 * @param {Function} onRemoved Called after Blink's previously seen button is absent.
 * @return {Function} Observer cleanup callback.
 */
export const observeApplePayButtonRemoval = ( container, onRemoved ) => {
	const WalletMutationObserver = window.MutationObserver;
	if ( ! container || ! WalletMutationObserver ) {
		return () => {};
	}

	const buttonSelector = '#apple-pay-btn';
	let hasSeenButton = !! container.querySelector( buttonSelector );
	const notifyIfRemoved = () => {
		if ( container.querySelector( buttonSelector ) ) {
			hasSeenButton = true;
			return false;
		}

		if ( ! hasSeenButton ) {
			return false;
		}

		onRemoved();
		return true;
	};

	const observer = new WalletMutationObserver( () => {
		if ( notifyIfRemoved() ) {
			observer.disconnect();
		}
	} );
	observer.observe( container, { childList: true, subtree: true } );

	return () => observer.disconnect();
};

/**
 * Load one WooCommerce-owned instance of a wallet script.
 *
 * @param {string} src Script URL.
 * @param {string} key Stable script identifier.
 * @return {Promise<HTMLScriptElement>} The loaded script.
 */
export const loadWalletScriptOnce = ( src, key ) => {
	const selector = `script[data-blink-wallet-script="${ key }"]`;
	let script = document.querySelector( selector );

	if ( script?.dataset.blinkLoaded === 'true' ) {
		return Promise.resolve( script );
	}

	if ( ! script ) {
		script = document.createElement( 'script' );
		script.src = src;
		script.async = true;
		script.dataset.blinkWalletScript = key;
		document.body.appendChild( script );
	}

	return new Promise( ( resolve, reject ) => {
		script.addEventListener(
			'load',
			() => {
				script.dataset.blinkLoaded = 'true';
				resolve( script );
			},
			{ once: true }
		);
		script.addEventListener(
			'error',
			( error ) => {
				script.remove();
				reject( error );
			},
			{ once: true }
		);
	} );
};
