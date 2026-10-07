const walletSubmit = require( '../assets/js/wallet-submit' );

const walletFormMarkup = () => `
	<form name="checkout" class="woocommerce-checkout">
		<div class="blink-wallet" data-blink-wallet="apple">
			<button type="button" id="appleWalletButton">Apple Pay</button>
			<input name="paymentToken" id="applePayToken" value="">
			<input name="resource" value="applepay">
			<input name="payment_intent" value="pi_apple">
			<input name="transaction_unique" value="apple-transaction">
			<input name="type" value="1">
		</div>
		<div class="blink-wallet" data-blink-wallet="google">
			<button type="button" id="googleWalletButton">Google Pay</button>
			<input name="paymentToken" id="googlePayToken" value="">
			<input name="resource" value="googlepay">
			<input name="payment_intent" value="pi_google">
			<input name="transaction_unique" value="google-transaction">
			<input name="type" value="1">
		</div>
		<input type="hidden" name="payment_by" value="credit-card">
		<input type="hidden" name="intent_id" value="intent-current">
		<input type="hidden" name="intent_expiry_date" value="expiry-current">
		<input type="radio" name="payment_method" value="blink" checked>
		<input type="radio" name="payment_method" value="other-gateway">
	</form>
`;

const getForm = () => document.querySelector( 'form[name="checkout"]' );

const submitAndCapture = ( form ) => {
	let submittedData;
	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		submittedData = new FormData( form );
	} );
	form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );

	return submittedData;
};

const getWalletControls = ( form, wallet ) => Array.from(
	form.querySelectorAll( `[data-blink-wallet="${ wallet }"] input, [data-blink-wallet="${ wallet }"] select, [data-blink-wallet="${ wallet }"] textarea` )
);

const expectWalletDisabled = ( form, wallet, disabled ) => {
	getWalletControls( form, wallet ).forEach( ( control ) => {
		expect( control.disabled ).toBe( disabled );
	} );
};

const expectWalletExcluded = ( form, wallet ) => {
	expectWalletDisabled( form, wallet, true );
	getWalletControls( form, wallet ).forEach( ( control ) => {
		expect( control.hasAttribute( 'name' ) ).toBe( false );
	} );
};

describe( 'Classic wallet submission normalization', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML = walletFormMarkup();
		delete window.onApplePayButtonClicked;
	} );

	afterEach( () => {
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
		document.body.innerHTML = '';
		delete window.onApplePayButtonClicked;
	} );

	test( 'generated wallet order contains duplicate provider controls', () => {
		const form = getForm();
		const wallets = form.querySelectorAll( '[data-blink-wallet]' );

		expect( wallets[ 0 ].dataset.blinkWallet ).toBe( 'apple' );
		expect( wallets[ 1 ].dataset.blinkWallet ).toBe( 'google' );
		for ( const name of [
			'paymentToken',
			'resource',
			'payment_intent',
			'transaction_unique',
			'type',
		] ) {
			expect( form.querySelectorAll( `[name="${ name }"]` ) ).toHaveLength( 2 );
		}
	} );

	test( 'plain card state excludes both wallet submission wrappers', () => {
		const form = getForm();
		walletSubmit.install( form );

		expectWalletExcluded( form, 'apple' );
		expectWalletExcluded( form, 'google' );
		expect( form.querySelector( '#appleWalletButton' ).disabled ).toBe( false );
		expect( form.querySelector( '#googleWalletButton' ).disabled ).toBe( false );
	} );

	test( 'plain card token is inserted into one dedicated collectable control', () => {
		const form = getForm();
		const paymentToken = 'card-payment-token';
		walletSubmit.install( form );

		const cardToken = walletSubmit.prepareCardPaymentToken( form );
		form.elements.paymentToken.value = paymentToken;
		const data = new FormData( form );

		expect( form.elements.paymentToken ).toBe( cardToken );
		expect( data.getAll( 'paymentToken' ) ).toEqual( [ paymentToken ] );
		expect( data.getAll( 'resource' ) ).toEqual( [] );
		expect( cardToken.dataset.blinkCardPaymentToken ).toBe( 'true' );
	} );

	test( 'Apple submission excludes the later empty Google token', () => {
		const form = getForm();
		const paymentData = JSON.stringify( {
			data: 'encrypted-data',
			signature: 'signature-value',
			header: {
				publicKeyHash: 'public-key-hash',
				ephemeralPublicKey: 'ephemeral-public-key',
				transactionId: 'transaction-id',
			},
			version: 'EC_v1',
		} );
		form.querySelector( '#applePayToken' ).value = paymentData;
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );

		const data = submitAndCapture( form );

		expect( data.getAll( 'paymentToken' ) ).toEqual( [ paymentData ] );
		expect( data.getAll( 'resource' ) ).toEqual( [ 'applepay' ] );
		expect( data.getAll( 'payment_intent' ) ).toEqual( [ 'pi_apple' ] );
	} );

	test( 'Apple activation persistently disables Google controls', () => {
		const form = getForm();
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );

		expectWalletDisabled( form, 'apple', false );
		expectWalletExcluded( form, 'google' );
	} );

	test( 'Google activation persistently disables Apple controls', () => {
		const form = getForm();
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'google' );

		expectWalletDisabled( form, 'google', false );
		expectWalletExcluded( form, 'apple' );
	} );

	test( 'inactive wallet remains disabled after submit handlers and timers finish', () => {
		const form = getForm();
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );

		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
		jest.runOnlyPendingTimers();

		expectWalletExcluded( form, 'google' );
	} );

	test( 'delayed CheckoutWC-style serialization excludes inactive wallet fields', async () => {
		const form = getForm();
		form.querySelector( '#applePayToken' ).value = 'apple-token';
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );

		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
		await Promise.resolve();
		jest.runOnlyPendingTimers();
		await Promise.resolve();
		const data = new FormData( form );

		expect( data.getAll( 'paymentToken' ) ).toEqual( [ 'apple-token' ] );
		expect( data.getAll( 'resource' ) ).toEqual( [ 'applepay' ] );
		expect( data.getAll( 'payment_intent' ) ).toEqual( [ 'pi_apple' ] );
		expect( data.getAll( 'transaction_unique' ) ).toEqual( [ 'apple-transaction' ] );
		expect( data.get( 'payment_by' ) ).toBe( 'apple-pay' );
	} );

	test( 'switching from Apple to Google updates persistent control state', () => {
		const form = getForm();
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );
		walletSubmit.setActiveWallet( form, 'google' );

		expectWalletExcluded( form, 'apple' );
		expectWalletDisabled( form, 'google', false );
		expect( form.querySelector( '[name="payment_by"]' ).value ).toBe( 'google-pay' );
	} );

	test( 'switching from Google to Apple updates persistent control state', () => {
		const form = getForm();
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'google' );
		walletSubmit.setActiveWallet( form, 'apple' );

		expectWalletExcluded( form, 'google' );
		expectWalletDisabled( form, 'apple', false );
		expect( form.querySelector( '[name="payment_by"]' ).value ).toBe( 'apple-pay' );
	} );

	test.each( [
		[ 'apple', 'apple-wallet-token', 'applepay' ],
		[ 'google', 'google-wallet-token', 'googlepay' ],
	] )( 'switching from card to %s excludes the dedicated card token', ( wallet, walletToken, resource ) => {
		const form = getForm();
		walletSubmit.install( form );
		const cardToken = walletSubmit.prepareCardPaymentToken( form );
		cardToken.value = 'stale-card-token';

		walletSubmit.setActiveWallet( form, wallet );
		form.querySelector( wallet === 'apple' ? '#applePayToken' : '#googlePayToken' ).value = walletToken;
		const data = new FormData( form );

		expect( cardToken.disabled ).toBe( true );
		expect( cardToken.hasAttribute( 'name' ) ).toBe( false );
		expect( data.getAll( 'paymentToken' ) ).toEqual( [ walletToken ] );
		expect( data.getAll( 'resource' ) ).toEqual( [ resource ] );
	} );

	test.each( [ 'apple', 'google' ] )(
		'switching from %s to card creates one unpolluted card token control',
		( wallet ) => {
			const form = getForm();
			const card = document.createElement( 'input' );
			card.type = 'radio';
			card.name = 'switchPayment';
			card.value = 'credit-card';
			form.appendChild( card );
			walletSubmit.install( form );
			walletSubmit.setActiveWallet( form, wallet );

			card.checked = true;
			card.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			const cardToken = walletSubmit.prepareCardPaymentToken( form );
			form.elements.paymentToken.value = 'new-card-token';
			const data = new FormData( form );

			expect( form.elements.paymentToken ).toBe( cardToken );
			expect( data.getAll( 'paymentToken' ) ).toEqual( [ 'new-card-token' ] );
			expect( data.getAll( 'resource' ) ).toEqual( [] );
			expectWalletExcluded( form, 'apple' );
			expectWalletExcluded( form, 'google' );
		}
	);

	test( 'Apple submission preserves WooCommerce routing and intent fields', () => {
		const form = getForm();
		form.querySelector( '#applePayToken' ).value = 'apple-token';
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );

		const data = submitAndCapture( form );

		expect( data.get( 'payment_by' ) ).toBe( 'apple-pay' );
		expect( data.get( 'intent_id' ) ).toBe( 'intent-current' );
		expect( data.get( 'intent_expiry_date' ) ).toBe( 'expiry-current' );
	} );

	test( 'restores a captured WooCommerce intent if its controls are replaced', () => {
		const form = getForm();
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );
		form.querySelector( '[name="intent_id"]' ).remove();
		form.querySelector( '[name="intent_expiry_date"]' ).remove();
		form.querySelector( '#applePayToken' ).value = 'apple-token';

		const data = submitAndCapture( form );

		expect( data.get( 'intent_id' ) ).toBe( 'intent-current' );
		expect( data.get( 'intent_expiry_date' ) ).toBe( 'expiry-current' );
	} );

	test( 'Google submission excludes inactive Apple controls', () => {
		const form = getForm();
		form.querySelector( '#applePayToken' ).value = 'stale-apple-token';
		form.querySelector( '#googlePayToken' ).value = 'google-token';
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'google' );

		const data = submitAndCapture( form );

		expect( data.getAll( 'paymentToken' ) ).toEqual( [ 'google-token' ] );
		expect( data.getAll( 'resource' ) ).toEqual( [ 'googlepay' ] );
		expect( data.getAll( 'payment_intent' ) ).toEqual( [ 'pi_google' ] );
		expect( data.getAll( 'transaction_unique' ) ).toEqual( [ 'google-transaction' ] );
		expect( data.get( 'payment_by' ) ).toBe( 'google-pay' );
	} );

	test( 'immediately wraps an Apple callback that already exists', () => {
		const original = jest.fn();
		window.onApplePayButtonClicked = original;
		const form = getForm();

		walletSubmit.observeApplePayButton( () => form );
		window.onApplePayButtonClicked( 'merchant' );

		expect( original ).toHaveBeenCalledWith( 'merchant' );
		expect( form.querySelector( '[name="payment_by"]' ).value ).toBe( 'apple-pay' );
	} );

	test( 'repeated setup does not double-wrap the Apple callback', () => {
		const original = jest.fn();
		window.onApplePayButtonClicked = original;
		const form = getForm();

		walletSubmit.observeApplePayButton( () => form );
		const firstWrapper = window.onApplePayButtonClicked;
		walletSubmit.observeApplePayButton( () => form );

		expect( window.onApplePayButtonClicked ).toBe( firstWrapper );
		window.onApplePayButtonClicked();
		expect( original ).toHaveBeenCalledTimes( 1 );
	} );

	test.each( [ 'Safari sheet', 'Windows QR' ] )(
		'%s authorization uses the same normalized checkout submission',
		() => {
			const form = getForm();
			window.onApplePayButtonClicked = jest.fn();
			walletSubmit.observeApplePayButton( () => form );
			window.onApplePayButtonClicked();
			form.querySelector( '#applePayToken' ).value = 'apple-token';

			const data = submitAndCapture( form );

			expect( data.getAll( 'paymentToken' ) ).toEqual( [ 'apple-token' ] );
			expect( data.get( 'payment_by' ) ).toBe( 'apple-pay' );
		}
	);

	test( 'activating the alternative wallet after cancellation restores its controls', () => {
		const form = getForm();
		window.onApplePayButtonClicked = jest.fn();
		walletSubmit.observeApplePayButton( () => form );
		window.onApplePayButtonClicked();

		expect( form.querySelector( '#googlePayToken' ).disabled ).toBe( true );

		form.querySelector( '#googlePayToken' ).value = 'google-token';
		walletSubmit.setActiveWallet( form, 'google' );
		const data = submitAndCapture( form );

		expect( data.getAll( 'paymentToken' ) ).toEqual( [ 'google-token' ] );
		expect( form.querySelector( '#googlePayToken' ).disabled ).toBe( false );
		expect( form.querySelector( '#applePayToken' ).disabled ).toBe( true );
	} );

	test( 'changing away from Blink resets managed wallet controls', () => {
		const form = getForm();
		const otherGateway = form.querySelector( '[name="payment_method"][value="other-gateway"]' );
		form.querySelector( '#applePayToken' ).value = 'stale-apple-token';
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );

		otherGateway.checked = true;
		otherGateway.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expectWalletExcluded( form, 'apple' );
		expectWalletExcluded( form, 'google' );
		expect( walletSubmit.detectActiveWallet( form ) ).toBe( '' );
	} );

	test( 'switching to a non-wallet Blink method resets managed wallet controls', () => {
		const form = getForm();
		const card = document.createElement( 'input' );
		card.type = 'radio';
		card.name = 'switchPayment';
		card.value = 'credit-card';
		form.appendChild( card );
		form.querySelector( '#googlePayToken' ).value = 'stale-google-token';
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'google' );

		card.checked = true;
		card.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expectWalletExcluded( form, 'apple' );
		expectWalletExcluded( form, 'google' );
		expect( walletSubmit.detectActiveWallet( form ) ).toBe( '' );
	} );

	test.each( [ 'direct-debit', 'open-banking' ] )(
		'%s state excludes both wallet submission wrappers',
		( paymentMethod ) => {
			const form = getForm();
			const method = document.createElement( 'input' );
			method.type = 'radio';
			method.name = 'switchPayment';
			method.value = paymentMethod;
			form.appendChild( method );
			walletSubmit.install( form );
			walletSubmit.setActiveWallet( form, 'apple' );

			method.checked = true;
			method.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			expectWalletExcluded( form, 'apple' );
			expectWalletExcluded( form, 'google' );
		}
	);

	test( 'reconciles temporary fields with WooCommerce fields after a refresh', () => {
		const form = getForm();
		form.querySelector( '[name="payment_by"]' ).remove();
		walletSubmit.setActiveWallet( form, 'apple' );
		expect( form.querySelectorAll( '[name="payment_by"]' ) ).toHaveLength( 1 );

		const refreshedField = document.createElement( 'input' );
		refreshedField.type = 'hidden';
		refreshedField.name = 'payment_by';
		refreshedField.value = 'credit-card';
		form.prepend( refreshedField );
		walletSubmit.install( form );

		expect( form.querySelectorAll( '[name="payment_by"]' ) ).toHaveLength( 1 );
		expect( form.querySelector( '[name="payment_by"]' ) ).toBe( refreshedField );
	} );

	test( 'reinstalling after wallet markup replacement does not duplicate controls', () => {
		const form = getForm();
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );
		form.querySelector( '[data-blink-wallet="google"]' ).innerHTML = `
			<input name="paymentToken" id="googlePayTokenReplacement" value="">
			<input name="resource" value="googlepay">
		`;

		walletSubmit.install( form );

		expect( form.querySelectorAll( '[name="payment_by"]' ) ).toHaveLength( 1 );
		expect( form.querySelectorAll( '[name="intent_id"]' ) ).toHaveLength( 1 );
		expect( form.querySelectorAll( '[name="intent_expiry_date"]' ) ).toHaveLength( 1 );
		expectWalletDisabled( form, 'google', true );
	} );

	test( 'order-pay uses the same persistent wallet submission state', async () => {
		const form = getForm();
		form.id = 'order_review';
		form.className = '';
		form.querySelector( '#applePayToken' ).value = 'order-pay-apple-token';
		walletSubmit.install( form );
		walletSubmit.setActiveWallet( form, 'apple' );

		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
		await Promise.resolve();
		jest.runOnlyPendingTimers();
		const data = new FormData( form );

		expect( data.getAll( 'paymentToken' ) ).toEqual( [ 'order-pay-apple-token' ] );
		expect( data.getAll( 'resource' ) ).toEqual( [ 'applepay' ] );
	} );

	test.each( [ 'Classic checkout', 'Order Pay' ] )(
		'%s waits for async Apple Pay insertion before removing its layout',
		async () => {
			document.body.innerHTML = `
				<form>
					<div data-blink-wallet-row>
						<div data-blink-wallet="apple"><div id="blinkApplePay"></div></div>
					</div>
				</form>
			`;
			const walletRow = document.querySelector(
				'[data-blink-wallet-row]'
			);
			const appleWallet = walletRow.querySelector(
				'[data-blink-wallet="apple"]'
			);
			const onRemoved = jest.fn( () => {
				appleWallet.remove();
				if ( ! walletRow.querySelector( '[data-blink-wallet]' ) ) {
					walletRow.remove();
				}
			} );
			const stopObserving = walletSubmit.observeApplePayButtonRemoval(
				appleWallet,
				onRemoved
			);

			expect( onRemoved ).not.toHaveBeenCalled();
			expect( walletRow.isConnected ).toBe( true );

			const button = document.createElement( 'apple-pay-button' );
			button.id = 'apple-pay-btn';
			appleWallet.appendChild( button );
			await Promise.resolve();

			expect( onRemoved ).not.toHaveBeenCalled();
			expect( walletRow.isConnected ).toBe( true );

			button.remove();
			await Promise.resolve();

			expect( onRemoved ).toHaveBeenCalledTimes( 1 );
			expect( walletRow.isConnected ).toBe( false );
			stopObserving();
		}
	);
} );
