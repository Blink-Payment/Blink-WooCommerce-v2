import * as React from '@wordpress/element';
import { createRoot, flushSync } from '@wordpress/element';
import { CardLogos, getValidCardLogos } from '../src/card-logos';

global.React = React;

describe( 'Blink card logos', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'ignores missing and malformed Blocks data', () => {
		expect( getValidCardLogos() ).toEqual( [] );
		expect( getValidCardLogos( 'visa' ) ).toEqual( [] );
		expect( getValidCardLogos( [ null, {}, { id: 'visa' } ] ) ).toEqual(
			[]
		);
		expect(
			getValidCardLogos( [
				{
					id: 'visa',
					label: 'Visa',
					url: '/assets/img/mastercard.svg',
					width: 36,
					height: 24,
				},
				{
					id: 'unknown',
					label: 'Unknown',
					url: '/assets/img/unknown.svg',
					width: 36,
					height: 24,
				},
			] )
		).toEqual( [] );
	} );

	test( 'allows PHP-generated asset URLs to use a filtered or CDN origin', () => {
		const visa = {
			id: 'visa',
			label: 'Visa',
			url: 'https://cdn.example.com/assets/img/visa.svg',
			width: 36,
			height: 24,
		};

		expect( getValidCardLogos( [ visa ] ) ).toEqual( [ visa ] );
	} );

	test( 'deduplicates logo IDs so React keys remain unique', () => {
		const visa = {
			id: 'visa',
			label: 'Visa',
			url: '/assets/img/visa.svg',
			width: 36,
			height: 24,
		};

		expect( getValidCardLogos( [ visa, visa ] ) ).toEqual( [ visa ] );
	} );

	test( 'renders exactly the card-logo data registered by PHP', () => {
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		flushSync( () =>
			root.render(
				<CardLogos
					logos={ [
						{
							id: 'visa',
							label: 'Visa',
							url: '/assets/img/visa.svg',
							width: 36,
							height: 24,
						},
						{
							id: 'american_express',
							label: 'American Express',
							url: '/assets/img/american-express.svg',
							width: 37,
							height: 24,
						},
					] }
				/>
			)
		);

		const images = container.querySelectorAll( '.blink-card-logo' );
		expect( images ).toHaveLength( 2 );
		expect( images[ 0 ].getAttribute( 'src' ) ).toBe(
			'/assets/img/visa.svg'
		);
		expect( images[ 0 ].getAttribute( 'alt' ) ).toBe( 'Visa' );
		expect( images[ 0 ].getAttribute( 'width' ) ).toBe( '36' );
		expect( images[ 0 ].getAttribute( 'height' ) ).toBe( '24' );
		expect( images[ 1 ].getAttribute( 'alt' ) ).toBe( 'American Express' );
		expect( container.querySelector( '[src*="mastercard"]' ) ).toBeNull();
		root.unmount();
	} );

	test( 'renders no wrapper when no brands are selected', () => {
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		flushSync( () => root.render( <CardLogos logos={ [] } /> ) );
		expect( container.innerHTML ).toBe( '' );
		root.unmount();
	} );
} );
