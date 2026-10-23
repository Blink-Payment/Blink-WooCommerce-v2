const CARD_LOGO_METADATA = {
	visa: {
		label: 'Visa',
		filename: 'visa.svg',
		width: 36,
		height: 24,
	},
	mastercard: {
		label: 'Mastercard',
		filename: 'mastercard.svg',
		width: 36,
		height: 24,
	},
	american_express: {
		label: 'American Express',
		filename: 'american-express.svg',
		width: 37,
		height: 24,
	},
};

export const getValidCardLogos = ( logos ) => {
	if ( ! Array.isArray( logos ) ) {
		return [];
	}

	// PHP's fixed card catalogue is the URL trust boundary. Here we only verify
	// the expected data shape and asset suffix, allowing plugins_url() filters or
	// CDN rewriting to provide a different origin.
	const seenIds = new Set();

	return logos.filter( ( logo ) => {
		if ( ! logo || typeof logo.id !== 'string' || seenIds.has( logo.id ) ) {
			return false;
		}

		if (
			! Object.prototype.hasOwnProperty.call(
				CARD_LOGO_METADATA,
				logo.id
			)
		) {
			return false;
		}

		const metadata = CARD_LOGO_METADATA[ logo.id ];
		const isValid =
			metadata &&
			logo.label === metadata.label &&
			typeof logo.url === 'string' &&
			logo.url.endsWith( `/assets/img/${ metadata.filename }` ) &&
			logo.width === metadata.width &&
			logo.height === metadata.height;

		if ( isValid ) {
			seenIds.add( logo.id );
		}

		return isValid;
	} );
};

export const CardLogos = ( { logos } ) => {
	const validLogos = getValidCardLogos( logos );

	if ( validLogos.length === 0 ) {
		return null;
	}

	return (
		<span className="blink-card-logos">
			{ validLogos.map( ( logo ) => (
				<img
					key={ logo.id }
					className="blink-card-logo"
					src={ logo.url }
					alt={ logo.label }
					width={ logo.width }
					height={ logo.height }
				/>
			) ) }
		</span>
	);
};
