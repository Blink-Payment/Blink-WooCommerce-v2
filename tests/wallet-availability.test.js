import {
	getWalletAvailability,
	loadWalletScriptOnce,
	observeApplePayButtonRemoval,
} from '../src/wallet-availability';

describe( 'Blink wallet availability', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'shows both Blink-supplied wallets without a browser rule', () => {
		expect(
			getWalletAvailability(
				{ apple_pay_enabled: true, isSafari: false },
				{
					apElement: '<div id="blinkApplePay"></div>',
					gpElement: '<div id="blinkGooglePay"></div>',
				}
			)
		).toEqual( {
			showApplePay: true,
			showGooglePay: true,
			showWalletRow: true,
		} );
	} );

	test( 'does not expose Apple Pay when Blink does not supply its element', () => {
		expect(
			getWalletAvailability(
				{ apple_pay_enabled: true, isSafari: false },
				{ gpElement: '<div id="blinkGooglePay"></div>' }
			)
		).toEqual( {
			showApplePay: false,
			showGooglePay: true,
			showWalletRow: true,
		} );
	} );

	test( 'does not expose Apple Pay when the merchant disables it', () => {
		expect(
			getWalletAvailability(
				{ apple_pay_enabled: false, isSafari: false },
				{
					apElement: '<div id="blinkApplePay"></div>',
					gpElement: '<div id="blinkGooglePay"></div>',
				}
			)
		).toEqual( {
			showApplePay: false,
			showGooglePay: true,
			showWalletRow: true,
		} );
	} );

	test( 'does not suppress Google Pay on Safari', () => {
		expect(
			getWalletAvailability(
				{ apple_pay_enabled: true, isSafari: true },
				{
					apElement: '<div id="blinkApplePay"></div>',
					gpElement: '<div id="blinkGooglePay"></div>',
				}
			)
		).toEqual( {
			showApplePay: true,
			showGooglePay: true,
			showWalletRow: true,
		} );
	} );

	test( 'shows Apple Pay at full row width when it is the only wallet', () => {
		expect(
			getWalletAvailability(
				{ apple_pay_enabled: true },
				{ apElement: '<div id="blinkApplePay"></div>' }
			)
		).toEqual( {
			showApplePay: true,
			showGooglePay: false,
			showWalletRow: true,
		} );
	} );

	test( 'does not affect non-wallet payment elements', () => {
		const elements = {
			ccElement: '<div>card</div>',
			ddElement: '<div>direct debit</div>',
		};

		expect(
			getWalletAvailability(
				{ apple_pay_enabled: true, isSafari: false },
				elements
			)
		).toEqual( {
			showApplePay: false,
			showGooglePay: false,
			showWalletRow: false,
		} );
		expect( elements ).toEqual( {
			ccElement: '<div>card</div>',
			ddElement: '<div>direct debit</div>',
		} );
	} );

	test( 'waits for async Apple Pay insertion before observing its removal', async () => {
		document.body.innerHTML = `
			<div class="blink-wallet-row" data-blink-wallet-row>
				<div class="blink-wallet" data-blink-wallet="apple"><div id="blinkApplePay"></div></div>
				<div class="blink-wallet" data-blink-wallet="google"><button id="google-pay-btn"></button></div>
			</div>
		`;
		const row = document.querySelector( '[data-blink-wallet-row]' );
		const appleWallet = row.querySelector( '[data-blink-wallet="apple"]' );
		const onRemoved = jest.fn( () => {
			appleWallet.remove();
		} );
		const stopObserving = observeApplePayButtonRemoval(
			appleWallet,
			onRemoved
		);

		expect( onRemoved ).not.toHaveBeenCalled();
		expect( appleWallet.isConnected ).toBe( true );

		const button = document.createElement( 'apple-pay-button' );
		button.id = 'apple-pay-btn';
		appleWallet.appendChild( button );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( onRemoved ).not.toHaveBeenCalled();
		expect( appleWallet.isConnected ).toBe( true );

		button.remove();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( onRemoved ).toHaveBeenCalledTimes( 1 );
		expect( row.querySelector( '[data-blink-wallet="apple"]' ) ).toBeNull();
		expect(
			row.querySelector( '[data-blink-wallet="google"]' )
		).not.toBeNull();
		expect( row.children ).toHaveLength( 1 );
		stopObserving();
	} );

	test( 'observes removal when the Apple Pay button is initially present', async () => {
		document.body.innerHTML = `
			<div data-blink-wallet="apple"><button id="apple-pay-btn"></button></div>
		`;
		const appleWallet = document.querySelector(
			'[data-blink-wallet="apple"]'
		);
		const onRemoved = jest.fn();
		const stopObserving = observeApplePayButtonRemoval(
			appleWallet,
			onRemoved
		);

		appleWallet.querySelector( '#apple-pay-btn' ).remove();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( onRemoved ).toHaveBeenCalledTimes( 1 );
		stopObserving();
	} );

	test( 'cleanup disconnects the Apple Pay removal observer', async () => {
		document.body.innerHTML = `
			<div data-blink-wallet="apple"><div id="blinkApplePay"></div></div>
		`;
		const appleWallet = document.querySelector(
			'[data-blink-wallet="apple"]'
		);
		const onRemoved = jest.fn();
		const stopObserving = observeApplePayButtonRemoval(
			appleWallet,
			onRemoved
		);
		const button = document.createElement( 'apple-pay-button' );
		button.id = 'apple-pay-btn';

		appleWallet.appendChild( button );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		stopObserving();
		button.remove();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( onRemoved ).not.toHaveBeenCalled();
	} );

	test( 'deduplicates WooCommerce-owned Apple Pay scripts across rerenders', async () => {
		const scripts = [
			[ 'https://example.com/apple-pay-api.js', 'blink-apple-pay-api' ],
			[ 'https://example.com/apple-pay-sdk.js', 'apple-pay-sdk' ],
		];

		for ( const [ src, key ] of scripts ) {
			const firstLoad = loadWalletScriptOnce( src, key );
			const secondLoad = loadWalletScriptOnce( src, key );
			const selector = `script[data-blink-wallet-script="${ key }"]`;
			const script = document.querySelector( selector );

			expect( document.querySelectorAll( selector ) ).toHaveLength( 1 );
			script.dispatchEvent( new Event( 'load' ) );
			await Promise.all( [ firstLoad, secondLoad ] );
			await loadWalletScriptOnce( src, key );
			expect( document.querySelectorAll( selector ) ).toHaveLength( 1 );
		}
	} );
} );
