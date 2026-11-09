/* eslint jest/expect-expect: [ "error", { "assertFunctionNames": [ "expect", "expectBillingFieldsConcealed" ] } ] */
const cardFormFields = require( '../assets/js/card-form-fields' );

// Shapes copied from Blink's card form generators (PaymentController::generateHtml
// and ::generateStripeHtml). Values are placeholders.
const blinkCardMarkup = ( {
	email = 'Email',
	address = 'Street Address',
	postcode = 'Postcode',
	withAddress = true,
	withIds = true,
} = {} ) => `
	<label class="blink-form__label field-label">Card Information</label>
	<div class="mb-4 blink-form__group fieldgroup-container">
		<input type="hostedfield:cardNumber" class="blink-form__input--card-number" />
	</div>
	<label class="blink-form__label field-label" for="customer_name">Name on Card</label>
	<div class="mb-4 blink-form__group fieldgroup-container">
		<input type="text" class="blink-form__input--customer-name" ${
			withIds ? 'id="cc_customer_name"' : ''
		} name="customer_name" value="" />
	</div>
	<label class="blink-form__label field-label" for="customer_email">${ email }</label>
	<div class="mb-4 blink-form__group fieldgroup-container">
		<input type="text" class="blink-form__input--customer-email" ${
			withIds ? 'id="cc_customer_email"' : ''
		} name="customer_email" value="shopper@example.com" />
	</div>
	${
		withAddress
			? `
	<label class="blink-form__label field-label" for="customer_address">${ address }</label>
	<div class="mb-4 blink-form__group fieldgroup-container">
		<div class="blink-form__group-child fieldgroup-child--width-8">
			<input type="text" class="blink-form__input--customer-address" ${
				withIds ? 'id="cc_customer_address"' : ''
			} name="customer_address" value="1 Example Street" />
		</div>
		<div class="blink-form__group-child fieldgroup-child--width-4">
			<input type="text" class="blink-form__input--customer-postcode" ${
				withIds ? 'id="cc_customer_postcode"' : ''
			} name="customer_postcode" value="AB1 2CD" placeholder="${ postcode }" />
		</div>
	</div>`
			: ''
	}
	<input type="hidden" class="blink-form__input--device-params" name="device_timezone" />
`;

const render = ( cardMarkup ) => {
	document.body.innerHTML = `
		<form name="checkout" class="woocommerce-checkout">
			<div class="blink-credit" data-blink-credit="1">${ cardMarkup }</div>
			<div class="blink-open-banking">
				<label class="blink-form__label field-label" for="customer_email">Email</label>
				<div class="mb-4 blink-form__group fieldgroup-container">
					<input type="text" class="blink-form__input--customer-email" name="customer_email" value="ob@example.com" />
				</div>
			</div>
		</form>
	`;

	return document.querySelector( '[data-blink-credit]' );
};

// The scripts pass jQuery collections; this mirrors the parts the helper reads.
const asJQuery = ( element ) => ( {
	jquery: '3.7.1',
	get: ( index ) => ( element ? [ element ][ index ] : undefined ),
} );

const billingFieldNames = [
	'customer_email',
	'customer_address',
	'customer_postcode',
];

const expectBillingFieldsConcealed = ( container, expectedValues ) => {
	billingFieldNames.forEach( ( name ) => {
		expect(
			container.querySelector( `label[for="${ name }"]` )
		).toBeNull();
	} );

	Object.entries( expectedValues ).forEach( ( [ name, value ] ) => {
		const inputs = container.querySelectorAll( `input[name="${ name }"]` );
		expect( inputs ).toHaveLength( 1 );
		expect( inputs[ 0 ].getAttribute( 'type' ) ).toBe( 'hidden' );
		expect( inputs[ 0 ].value ).toBe( value );
		// Hidden with type, not with an inline style a theme could override.
		expect( inputs[ 0 ].style.display ).toBe( '' );
		expect( inputs[ 0 ].closest( '.blink-form__group' ) ).toBeNull();
	} );

	// Only the card and name groups remain: no empty wrappers left behind.
	const groups = Array.from(
		container.querySelectorAll( '.blink-form__group' )
	);
	expect( groups ).toHaveLength( 2 );
	expect(
		groups[ 0 ].querySelector( '.blink-form__input--card-number' )
	).not.toBeNull();
	expect(
		groups[ 1 ].querySelector( 'input[name="customer_name"]' )
	).not.toBeNull();
};

const allValues = {
	customer_email: 'shopper@example.com',
	customer_address: '1 Example Street',
	customer_postcode: 'AB1 2CD',
};

describe( 'Blink card form billing fields', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'conceals the email field when the account does not ask for an address', () => {
		const container = render( blinkCardMarkup( { withAddress: false } ) );

		cardFormFields.concealBillingFields( container );

		expectBillingFieldsConcealed( container, {
			customer_email: 'shopper@example.com',
		} );
	} );

	test( 'conceals email, address and postcode, including the "Street Address" label', () => {
		const container = render( blinkCardMarkup() );

		cardFormFields.concealBillingFields( container );

		expectBillingFieldsConcealed( container, allValues );
	} );

	test( 'finds the fields whatever language Blink renders the labels in', () => {
		const container = render(
			blinkCardMarkup( {
				email: 'E-bost',
				address: 'Cyfeiriad Stryd',
				postcode: 'Cod Post',
			} )
		);

		cardFormFields.concealBillingFields( container );

		expectBillingFieldsConcealed( container, allValues );
	} );

	test( 'handles the Stripe card form, whose inputs have no ids', () => {
		const container = render( blinkCardMarkup( { withIds: false } ) );

		cardFormFields.concealBillingFields( container );

		expectBillingFieldsConcealed( container, allValues );
	} );

	test( 'leaves the name on card, card fields and labels visible', () => {
		const container = render( blinkCardMarkup() );

		cardFormFields.concealBillingFields( container );

		const nameInput = container.querySelector(
			'input[name="customer_name"]'
		);
		expect( nameInput.getAttribute( 'type' ) ).toBe( 'text' );
		expect(
			container.querySelector( 'label[for="customer_name"]' )
		).not.toBeNull();
		expect(
			container
				.querySelector( '.blink-form__input--card-number' )
				.getAttribute( 'type' )
		).toBe( 'hostedfield:cardNumber' );
		expect(
			container.querySelector( '.blink-form__label:not([for])' )
				.textContent
		).toBe( 'Card Information' );
	} );

	test( 'does not touch fields outside the card form', () => {
		const container = render( blinkCardMarkup() );

		cardFormFields.concealBillingFields( container );

		const openBanking = document.querySelector( '.blink-open-banking' );
		expect(
			openBanking.querySelector( 'label[for="customer_email"]' )
		).not.toBeNull();
		expect(
			openBanking
				.querySelector( 'input[name="customer_email"]' )
				.getAttribute( 'type' )
		).toBe( 'text' );
		expect(
			openBanking.querySelector( '.blink-form__group' )
		).not.toBeNull();
	} );

	test( 'keeps the values fillable and submitted with the form', () => {
		const container = render( blinkCardMarkup() );

		cardFormFields.concealBillingFields( container );
		container.querySelector( 'input[name=customer_email]' ).value =
			'billing@example.com';

		const submitted = new FormData(
			document.querySelector( 'form[name="checkout"]' )
		);
		expect( submitted.getAll( 'customer_email' ) ).toEqual( [
			'billing@example.com',
			'ob@example.com',
		] );
		expect( submitted.get( 'customer_address' ) ).toBe(
			'1 Example Street'
		);
		expect( submitted.get( 'customer_postcode' ) ).toBe( 'AB1 2CD' );
	} );

	test( 'is safe to run again when checkout refreshes', () => {
		const container = render( blinkCardMarkup() );

		cardFormFields.concealBillingFields( container );
		cardFormFields.concealBillingFields( container );

		expectBillingFieldsConcealed( container, allValues );
	} );

	test( 'accepts a jQuery container', () => {
		const container = render( blinkCardMarkup() );

		cardFormFields.concealBillingFields( asJQuery( container ) );

		expectBillingFieldsConcealed( container, allValues );
	} );

	test( 'keeps a wrapper that still holds something visible', () => {
		const container = render( `
			<div class="mb-4 blink-form__group fieldgroup-container">
				<input type="text" name="customer_email" value="shopper@example.com" />
				<input type="text" name="customer_reference" value="" />
			</div>
		` );

		cardFormFields.concealBillingFields( container );

		const email = container.querySelector( 'input[name="customer_email"]' );
		expect( email.getAttribute( 'type' ) ).toBe( 'hidden' );
		expect(
			container.querySelector(
				'.blink-form__group input[name="customer_reference"]'
			)
		).not.toBeNull();
	} );

	test( 'ignores a missing container', () => {
		expect( () =>
			cardFormFields.concealBillingFields( null )
		).not.toThrow();
		expect( () =>
			cardFormFields.concealBillingFields( asJQuery( null ) )
		).not.toThrow();
	} );
} );
