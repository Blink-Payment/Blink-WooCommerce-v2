const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { useState, useEffect, useRef } = window.wp.element;
const { useSelect } = window.wp.data;
const { CART_STORE_KEY } = window.wc.wcBlocksData;
const { getSetting } = window.wc.wcSettings;
import { decodeEntities } from '@wordpress/html-entities';

const Icon = (props) => {
  const { settings } = props;
  return settings.icon
    ? <img src={settings.icon} style={{ float: 'right', marginRight: '20px', borderRadius: '0' }} />
    : '';
};

const Label = (props) => {
  const { settings } = props;

  return (
    <span style={{ width: '100%' }}>
      {settings.title || 'Blink'}
      <Icon settings={settings} />
    </span>
  );
};

const Content = (props) => {
  const { settings } = props;
  return decodeEntities(settings.description || '');
};

const PreauthNotice = (props) => {
  const { settings } = props;
  if (!settings.preauthorize_payments) {
    return null;
  }

  return (
    <div className="blink-preauth-notice notice notice-info" style={{
      background: '#f0f6fc',
      border: '1px solid #c3d9ff',
      borderRadius: '4px',
      padding: '15px',
      margin: '15px 0'
    }}>
      <p style={{ margin: '0', fontWeight: '600', color: '#0073aa' }}>
        <strong>Preauthorization Mode:</strong>
      </p>
      <p style={{ margin: '5px 0 0 0' }}>
        Your payment will be preauthorized at checkout and charged when your order is processed. This ensures your payment method is valid and reserves the funds.
      </p>
    </div>
  );
};

const getNormalizedAmount = (amount) => {
  if (amount === null || amount === undefined || amount === '') {
    return null;
  }

  const normalized = parseFloat(amount);
  return Number.isNaN(normalized) ? null : normalized;
};

const isZeroTotal = (amount) => {
  const normalized = getNormalizedAmount(amount);
  return normalized !== null && normalized <= 0;
};

const BlinkPayment = (props) => {
  const { settings, eventRegistration, emitResponse, billing } = props;
  const selectedMethods = Array.isArray(settings.selected_methods) ? settings.selected_methods : [];
  const [selectedTab, setSelectedTab] = useState(
    selectedMethods.length > 0
      ? selectedMethods[0]
      : ''
  );
  const formRef = useRef(null);
  const ddFormRef = useRef(null);
  const obFormRef = useRef(null);
  const googleFormRef = useRef(null);
  const appleFormRef = useRef(null);
  const selectedTabRef = useRef(selectedTab);
  const hostedFormElementRef = useRef(null);
  const hostedFormIntentRef = useRef('');
  const intentFailureAmountRef = useRef('');
  const intentLoadingAmountRef = useRef('');
  const [elements, setElements] = useState(settings.elements || {});
  const [cartAmount, setCartAmount] = useState(settings.cartAmount || '');
  const [intentId, setIntentId] = useState(settings.intentId || '');
  const [intentExpiryDate, setIntentExpiryDate] = useState(settings.intentExpiryDate || '');
  const [isIntentLoading, setIsIntentLoading] = useState(false);
  const [intentError, setIntentError] = useState('');
  const { onCheckoutValidation, onPaymentSetup, onCheckoutFail } = eventRegistration;
  const { billingAddress } = billing;
  const billingName = `${billingAddress.first_name} ${billingAddress.last_name}`;
  const billingFullAddress = `${billingAddress.address_1}, ${billingAddress.address_2}`;
  const screen_width = (window && window.screen ? window.screen.width : '0');
  const screen_height = (window && window.screen ? window.screen.height : '0');
  const screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
  const language = (window && window.navigator ? (window.navigator.language ? window.navigator.language : window.navigator.browserLanguage) : '');
  const java = (window && window.navigator ? navigator.javaEnabled() : false);
  const timezone = (new Date()).getTimezoneOffset();
  const cartData = useSelect((select) => select(CART_STORE_KEY).getCartData(), []);
  const cartTotal = cartData?.totals?.total_price;
  const currencyMinorUnit = cartData?.totals?.currency_minor_unit;
  const formattedTotal = (cartTotal !== undefined && cartTotal !== null && currencyMinorUnit !== undefined)
    ? (cartTotal / Math.pow(10, currencyMinorUnit)).toFixed(currencyMinorUnit)
    : null;
  const paymentRequired = !isZeroTotal(cartAmount) && !!intentId && !!intentExpiryDate;

  if (settings.isHosted) {
    return null;
  }

  useEffect(() => {
    if (formattedTotal === null) {
      return;
    }

    if (isZeroTotal(formattedTotal)) {
      resetBlinkPaymentState(formattedTotal);
      return;
    }

    if (intentError && intentFailureAmountRef.current !== formattedTotal) {
      setIntentError('');
      intentFailureAmountRef.current = '';
    }

    const hasRenderableElements = Object.values(elements || {}).some(Boolean);
    if (intentError && intentFailureAmountRef.current === formattedTotal) {
      return;
    }
    if (isIntentLoading && intentLoadingAmountRef.current === formattedTotal) {
      return;
    }
    if (cartAmount === formattedTotal && intentId && intentExpiryDate && hasRenderableElements) {
      return;
    }

    let isCurrentRequest = true;
    setIsIntentLoading(true);
    intentLoadingAmountRef.current = formattedTotal;

    (async () => {
      document.querySelectorAll('#gpay-button-online-api-id').forEach(el => el.remove());
      destroyHostedForm();

      try {
        const intentRes = await fetch('/wp-json/blink/v1/set-intent', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ cartAmount: formattedTotal }),
        });
        const intentData = await intentRes.json();

        if (!isCurrentRequest) {
          return;
        }

        if (intentData.payment_required === false || !intentData.intent?.element || !intentData.intent?.id || !intentData.intent?.expiry_date) {
          intentLoadingAmountRef.current = '';
          setIsIntentLoading(false);
          resetBlinkPaymentState(intentData.amount || formattedTotal);
          setIntentError('Unable to prepare Blink payment fields. Please try again.');
          intentFailureAmountRef.current = formattedTotal;
          return;
        }

        destroyHostedForm();
        setElements(intentData.intent.element);
        setCartAmount(intentData.amount || formattedTotal);
        setIntentId(intentData.intent.id);
        setIntentExpiryDate(intentData.intent.expiry_date);
        setIntentError('');
        setIsIntentLoading(false);
        intentFailureAmountRef.current = '';
        intentLoadingAmountRef.current = '';
      } catch (e) {
        if (!isCurrentRequest) {
          return;
        }

        resetBlinkPaymentState(formattedTotal);
        setIntentError('Unable to prepare Blink payment fields. Please try again.');
        intentFailureAmountRef.current = formattedTotal;
        intentLoadingAmountRef.current = '';
      }
    })();

    return () => {
      isCurrentRequest = false;
    };
  }, [formattedTotal, cartAmount, intentId, intentExpiryDate, elements, intentError, isIntentLoading]);

  if (settings.isHosted) {
    return null;
  }

  useEffect(() => {
    selectedTabRef.current = selectedTab;
  }, [selectedTab]);

  useEffect(() => {
    const unsubscribe = onCheckoutFail(() => {
      return {
        type: emitResponse.responseTypes.ERROR,
        message: 'Payment failed. Please check your details or try a different method. Contact support if the issue persists.',
        messageContext: emitResponse.noticeContexts.PAYMENTS,
      };
    });
    return unsubscribe;
  }, [onCheckoutFail]);

  const getCurrentForm = () => {
    if (selectedTabRef.current === 'direct-debit') {
      return ddFormRef.current;
    }
    if (selectedTabRef.current === 'open-banking') {
      return obFormRef.current;
    }
    if (selectedTabRef.current === 'google-pay') {
      return googleFormRef.current;
    }
    if (selectedTabRef.current === 'apple-pay') {
      return appleFormRef.current;
    }
    return getCreditContainer();
  };

  const getCreditContainer = () => formRef.current;

  const getHostedFormTarget = () => {
    const creditContainer = getCreditContainer();

    if (!creditContainer) {
      return null;
    }

    return (
      creditContainer.closest('form.wc-block-checkout__form') ||
      creditContainer.closest('form.woocommerce-checkout') ||
      creditContainer.closest('form.checkout') ||
      creditContainer.closest('form[name="checkout"]') ||
      document.querySelector('form.wc-block-checkout__form') ||
      document.querySelector('form.woocommerce-checkout') ||
      document.querySelector('form.checkout') ||
      document.querySelector('form[name="checkout"]') ||
      document.querySelector('form')
    );
  };

  const createPaymentError = (message) => ({
    type: emitResponse.responseTypes.ERROR,
    message,
    errorMessage: message,
    messageContext: emitResponse.noticeContexts.PAYMENTS,
  });

  const destroyHostedForm = (targetForm) => {
    const formElement = targetForm || hostedFormElementRef.current || getHostedFormTarget();
    const hostedFormElement = formElement && formElement.tagName === 'FORM'
      ? formElement
      : getHostedFormTarget();

    if (!hostedFormElement) {
      hostedFormElementRef.current = null;
      hostedFormIntentRef.current = '';
      return;
    }

    if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.hostedForm) {
      hostedFormElementRef.current = null;
      hostedFormIntentRef.current = '';
      return;
    }

    const hostedFormTarget = window.jQuery(hostedFormElement);

    try {
      const hostedForm = hostedFormTarget.hostedForm('instance');
      if (hostedForm && typeof hostedForm.destroy === 'function') {
        hostedForm.destroy();
      }
    } catch (error) { }

    hostedFormTarget.find('input[name=paymentToken], input[name=paymenttoken]').remove();
    hostedFormTarget.removeData('hostedform');
    hostedFormTarget.removeData('hostedForm');

    if (!targetForm || hostedFormElement === hostedFormElementRef.current) {
      hostedFormElementRef.current = null;
      hostedFormIntentRef.current = '';
    }
  };

  const isStaleHostedFormError = (error) => {
    const message = error?.message || String(error || '');

    return (
      message.includes('postMessage') ||
      message.includes('null') ||
      message.includes('iframe') ||
      message.includes('destroy') ||
      message.includes('stale')
    );
  };

  const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

  const resetBlinkPaymentState = (amount) => {
    document.querySelectorAll('#gpay-button-online-api-id').forEach(el => el.remove());
    destroyHostedForm();

    if (Object.values(elements || {}).some(Boolean)) {
      setElements({});
    }
    if (cartAmount !== amount) {
      setCartAmount(amount);
    }
    if (intentId) {
      setIntentId('');
    }
    if (intentExpiryDate) {
      setIntentExpiryDate('');
    }
    if (isIntentLoading) {
      setIsIntentLoading(false);
    }
    if (intentError) {
      setIntentError('');
    }
    intentFailureAmountRef.current = '';
    intentLoadingAmountRef.current = '';
  };

  const setFormValues = () => {
    if (!window.jQuery) {
      return;
    }

    const formElement = getCurrentForm();
    if (!formElement) {
      return;
    }

    const currentForm = window.jQuery(formElement);
    if (selectedTabRef.current === 'credit-card') {
      currentForm.find('input[name=customer_email]').hide();
      currentForm.find('label.blink-form__label:contains("Email")').hide();
      currentForm.find('input[name=customer_postcode]').hide();
      currentForm.find('input[name=customer_address]').hide();
      currentForm.find('label.blink-form__label:contains("Address")').hide();
      if (paymentRequired && elements?.ccElement) {
        initializeHostedForm();
      }
    }
    if (selectedTabRef.current === 'direct-debit') {
      currentForm.find('input[name=given_name]').val(billingAddress.first_name);
      currentForm.find('input[name=email]').val(billingAddress.email);
      currentForm.find('input[name=family_name]').val(billingAddress.last_name);
      currentForm.find('input[name=account_holder_name]').val(billingName);
    }
    currentForm.find('input[name=customer_name]').val(billingName);
    currentForm.find('input[name=customer_email]').val(billingAddress.email);
    currentForm.find('input[name=customer_address]').val(billingFullAddress);
    currentForm.find('input[name=customer_postcode]').val(billingAddress.postcode);
    currentForm.find('input[name=device_timezone]').val(timezone);
    currentForm.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
    currentForm.find('input[name=device_accept_language]').val(language);
    currentForm.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' + screen_depth);
    const remoteAddress = window.blink_params?.remoteAddress || '';
    currentForm.find('input[name=remote_address]').val(remoteAddress);
    currentForm.find('input[name=device_ip_address]').val(remoteAddress);
  };

  useEffect(() => {
    setFormValues();
  }, [selectedTab, billingAddress, elements, paymentRequired]);

  const initializeHostedForm = () => {
    const creditContainer = getCreditContainer();
    const hostedFormTarget = getHostedFormTarget();

    if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.hostedForm || !creditContainer || !hostedFormTarget || !paymentRequired || !intentId || !elements?.ccElement) {
      return null;
    }

    // The Blink SDK requires a FORM, but formRef is intentionally a div so
    // the block checkout does not render a nested blink-credit form.
    const formIntentKey = `${intentId}:${intentExpiryDate}`;

    if (hostedFormElementRef.current && hostedFormElementRef.current !== hostedFormTarget) {
      destroyHostedForm(hostedFormElementRef.current);
    }

    const currentForm = window.jQuery(hostedFormTarget);

    if (hostedFormIntentRef.current && hostedFormIntentRef.current !== formIntentKey) {
      destroyHostedForm(hostedFormTarget);
    }

    try {
      let hostedForm = currentForm.hostedForm('instance');
      if (!hostedForm) {
        currentForm.hostedForm({
          autoSetup: true,
          autoSubmit: false
        });
        hostedForm = currentForm.hostedForm('instance');
      } else if (typeof hostedForm.autoSetup === 'function') {
        hostedForm.autoSetup();
      }

      hostedFormElementRef.current = hostedFormTarget;
      hostedFormIntentRef.current = formIntentKey;
      return hostedForm || null;
    } catch (error) {
      return null;
    }
  };

  const handleSubmitCC = async () => {
    if (isZeroTotal(cartAmount)) {
      return true;
    }

    if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.hostedForm || !getCreditContainer() || selectedTabRef.current !== 'credit-card') {
      return createPaymentError('There was an issue preparing the card fields. Please try again.');
    }

    if (!intentId || !intentExpiryDate || !elements?.ccElement) {
      return createPaymentError('There was an issue preparing the payment intent. Please try again.');
    }

    try {
      const hostedForm = initializeHostedForm();
      if (!hostedForm || typeof hostedForm.getPaymentDetails !== 'function') {
        return createPaymentError('There was an issue preparing the card fields. Please try again.');
      }

      const hostedFormElement = getHostedFormTarget();
      window.jQuery(getCreditContainer()).find('input[name=paymentToken], input[name=paymenttoken]').remove();
      if (hostedFormElement) {
        window.jQuery(hostedFormElement).find('input[name=paymentToken], input[name=paymenttoken]').remove();
      }

      let paymentDetails;

      try {
        paymentDetails = await hostedForm.getPaymentDetails();
      } catch (error) {
        console.error('Error retrieving payment details:', error);

        if (!isStaleHostedFormError(error)) {
          throw error;
        }

        destroyHostedForm(hostedFormElement);
        await wait(300);

        const refreshedHostedForm = initializeHostedForm();
        await wait(800);

        if (!refreshedHostedForm || typeof refreshedHostedForm.getPaymentDetails !== 'function') {
          throw error;
        }

        paymentDetails = await refreshedHostedForm.getPaymentDetails();
      }
      if (!paymentDetails || !paymentDetails.success) {
        return createPaymentError(paymentDetails?.errors?.cardNumber || paymentDetails?.message || 'An error occurred while processing payment details.');
      }

      if (!paymentDetails.paymentToken) {
        return createPaymentError('Invalid Payment Token!');
      }

      const formForToken = (hostedFormElement ? window.jQuery(hostedFormElement).hostedForm('instance') : null) || hostedForm;
      formForToken.addPaymentToken(paymentDetails.paymentToken);
      const currentFormData = getCurrentFormData();
      if (!currentFormData.paymentToken && !currentFormData.paymenttoken) {
        return createPaymentError('There was an issue processing the payment. Please try again.');
      }
      return true;
    } catch (error) {
      return createPaymentError('An error occurred while processing payment details.');
    }
  };

  const getFormDataArray = () => {
    const formDataArray = [];
    const selectedForm = getCurrentForm();
    const creditContainer = getCreditContainer();
    const hostedFormTarget = getHostedFormTarget();

    const addInput = (input) => {
      if (!input.name || input.disabled) {
        return;
      }

      if (formDataArray.includes(input)) {
        return;
      }

      formDataArray.push(input);
    };

    if (selectedForm) {
      selectedForm.querySelectorAll('input, select, textarea').forEach(addInput);
    }

    if (selectedTabRef.current === 'credit-card') {
      creditContainer?.querySelectorAll('input, select, textarea').forEach(addInput);
      hostedFormTarget?.querySelectorAll(
        'input[name="paymentToken"], input[name="paymenttoken"], input[name="payment_by"], input[name="intent_id"], input[name="intent_expiry_date"], input[name="customer_name"], input[name="customer_email"], input[name="customer_address"], input[name="customer_postcode"], input[name="device_timezone"], input[name="device_capabilities"], input[name="device_accept_language"], input[name="device_screen_resolution"], input[name="remote_address"], input[name="device_ip_address"]'
      ).forEach(addInput);
    }

    return formDataArray.map((input) => ({
      name: input.name,
      value: input.value,
    }));
  };

  const getCurrentFormData = () => {
    const formDataArray = getFormDataArray();
    const currentFormData = {};
    formDataArray.forEach(field => {
      currentFormData[field.name] = field.value;
    });
    return currentFormData;
  };

  useEffect(() => {
    const unsubscribe = onCheckoutValidation(async () => {
      if (settings.isHosted) {
        return true;
      }
      if (isZeroTotal(cartAmount)) {
        return true;
      }
      if (intentError) {
        return createPaymentError(intentError);
      }
      if (!paymentRequired) {
        return createPaymentError('There was an issue preparing the Blink payment fields. Please try again.');
      }

      if (selectedTabRef.current === 'credit-card') {
        return await handleSubmitCC();
      }

      const currentFormData = getCurrentFormData();
      const allFieldsFilled = Object.entries(currentFormData)
        .filter(([fieldName]) => fieldName !== 'paymentToken' && fieldName !== 'paymenttoken')
        .every(([, value]) => value !== undefined && value !== '');
      if (!allFieldsFilled) {
        return createPaymentError('Please fill out all required fields.');
      }

      return true;
    });
    return unsubscribe;
  }, [onCheckoutValidation, cartAmount, paymentRequired, elements, intentId, intentExpiryDate, intentError, isIntentLoading]);

  useEffect(() => {
    const unsubscribe = eventRegistration.onPaymentSetup(async () => {
      const currentFormData = getCurrentFormData();
      if (settings.isHosted) {
        return {
          type: emitResponse.responseTypes.SUCCESS,
          meta: {
            paymentMethodData: {
              customer_address: currentFormData.customer_address || billingFullAddress,
              customer_postcode: currentFormData.customer_postcode || billingAddress.postcode,
            },
          },
        };
      }
      if (isZeroTotal(cartAmount)) {
        return {
          type: emitResponse.responseTypes.SUCCESS,
          meta: {
            paymentMethodData: {},
          },
        };
      }
      if (intentError) {
        return createPaymentError(intentError);
      }
      if (!paymentRequired) {
        return createPaymentError('There was an issue preparing the Blink payment fields. Please try again.');
      }

      const paymentData = {
        ...currentFormData,
        customer_address: currentFormData.customer_address || billingFullAddress,
        customer_postcode: currentFormData.customer_postcode || billingAddress.postcode,
      };
      if (selectedTabRef.current === 'credit-card' || selectedTabRef.current === 'google-pay' || selectedTabRef.current === 'apple-pay') {
        if (!currentFormData.paymentToken && !currentFormData.paymenttoken) {
          return createPaymentError('Invalid Payment Token!');
        }
      }
      return {
        type: emitResponse.responseTypes.SUCCESS,
        meta: {
          paymentMethodData: paymentData,
        },
      };
    });
    return () => {
      unsubscribe();
    };
  }, [onPaymentSetup, selectedTab, cartAmount, paymentRequired, billingAddress, elements, intentError, isIntentLoading]);

  useEffect(() => {
    if (!paymentRequired || !window.jQuery) {
      return;
    }

    const removeScriptBySrc = (src) => {
      document.querySelectorAll(`script[src="${src}"]`).forEach(script => {
        if (script.parentNode) {
          script.parentNode.removeChild(script);
        }
      });
    };

    if (settings.isSafari && settings.apple_pay_enabled) {
      removeScriptBySrc(settings.hostUrl + '/assets/js/apple-pay-api.js');
      const loadApplePayApi = new Promise((resolve, reject) => {
        const script1 = document.createElement('script');
        script1.src = settings.hostUrl + '/assets/js/apple-pay-api.js';
        script1.async = true;
        script1.onload = resolve;
        script1.onerror = reject;
        document.body.appendChild(script1);
      });
      const loadApplePayJs = new Promise((resolve, reject) => {
        const script2 = document.createElement('script');
        script2.src = 'https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js';
        script2.async = true;
        script2.onload = resolve;
        script2.onerror = reject;
        document.body.appendChild(script2);
      });
      if (!appleFormRef.current) {
        return;
      }
      window.jQuery(appleFormRef.current).off('submit.blinkBlocks').on('submit.blinkBlocks', function (event) {
        event.preventDefault();
        selectedTabRef.current = 'apple-pay';
        setSelectedTab('apple-pay');
        window.jQuery('.wc-block-components-checkout-place-order-button').click();
      });
    } else {
      document.querySelectorAll('#gpay-button-online-api-id').forEach(el => el.remove());
      removeScriptBySrc(settings.hostUrl + '/assets/js/google-pay-api.js');
      const googleScriptElement = document.querySelector('#blinkGooglePay script[src="https://pay.google.com/gp/p/js/pay.js"]');
      const loadGooglePayApi = new Promise((resolve, reject) => {
        const script1 = document.createElement('script');
        script1.src = settings.hostUrl + '/assets/js/google-pay-api.js';
        script1.async = true;
        script1.onload = resolve;
        script1.onerror = reject;
        document.body.appendChild(script1);
      });
      const loadPayJs = new Promise((resolve, reject) => {
        const script2 = document.createElement('script');
        script2.src = 'https://pay.google.com/gp/p/js/pay.js';
        script2.async = true;
        script2.onload = resolve;
        script2.onerror = reject;
        document.body.appendChild(script2);
      });
      Promise.all([loadGooglePayApi, loadPayJs])
        .then(() => {
          if (typeof window.onGooglePayLoaded === 'function') {
            if (googleScriptElement) {
              const onloadValue = googleScriptElement.getAttribute('onload');
              setTimeout(() => {
                try {
                  if (onloadValue) {
                    eval(onloadValue);
                  }
                } catch (err) { }
              }, 1000);
            }
          }
        })
        .catch(() => { });
      if (!googleFormRef.current) {
        return;
      }
      window.jQuery(googleFormRef.current).off('submit.blinkBlocks').on('submit.blinkBlocks', function (event) {
        event.preventDefault();
        selectedTabRef.current = 'google-pay';
        setSelectedTab('google-pay');
        window.jQuery('.wc-block-components-checkout-place-order-button').click();
      });
    }
  }, [elements, paymentRequired]);

  if (isIntentLoading && cartAmount !== formattedTotal) {
    return (
      <div className="blink-gutenberg payment_method_blink">
        <div className="blink-intent-loading" style={{ padding: '12px 0' }}>
          Preparing Blink payment fields...
        </div>
      </div>
    );
  }

  if (!paymentRequired) {
    return (
      <div className="blink-gutenberg payment_method_blink">
        {intentError ? (
          <div className="blink-intent-error" role="alert" style={{ padding: '12px 0' }}>
            <p style={{ margin: 0 }}>{intentError}</p>
            <button
              type="button"
              className="button"
              style={{ marginTop: '10px' }}
              onClick={() => {
                intentFailureAmountRef.current = '';
                setIntentError('');
              }}
            >
              Retry
            </button>
          </div>
        ) : isIntentLoading ? (
          <div className="blink-intent-loading" style={{ padding: '12px 0' }}>
            Preparing Blink payment fields...
          </div>
        ) : null}
      </div>
    );
  }

  return (
    <div className="blink-gutenberg payment_method_blink">
      <PreauthNotice settings={settings} />
      <div className="form-container">
        <>
          {settings.isSafari && settings.apple_pay_enabled ? (
            <form ref={appleFormRef}>
              <div dangerouslySetInnerHTML={{ __html: elements?.apElement }} />
              <input type="hidden" name="payment_by" id="payment_by" value="apple-pay" />
              <input type="hidden" name="intent_id" id="intent_id" value={intentId} />
              <input type="hidden" name="intent_expiry_date" id="intent_expiry_date" value={intentExpiryDate} />
            </form>
          ) : (
            <form ref={googleFormRef}>
              <div dangerouslySetInnerHTML={{ __html: elements?.gpElement }} />
              <input type="hidden" name="payment_by" id="payment_by" value="google-pay" />
              <input type="hidden" name="intent_id" id="intent_id" value={intentId} />
              <input type="hidden" name="intent_expiry_date" id="intent_expiry_date" value={intentExpiryDate} />
            </form>
          )}
        </>
        <div className='form-group mb-4'>
          <div className='form-group mb-4'>
            <div className="select-batch" style={{ width: "100%" }}>
              <div className={`switches-container ${containerClass}`} id="selectBatch">
                {selectedMethods.includes('credit-card') && (
                  <>
                    <input
                      type="radio"
                      id="credit-card"
                      name="switchPayment"
                      value="credit-card"
                      defaultChecked={selectedMethods[0] === 'credit-card'}
                      onClick={() => setSelectedTab('credit-card')}
                    />
                    <label htmlFor="credit-card">Card</label>
                  </>
                )}
                {selectedMethods.includes('direct-debit') && (
                  <>
                    <input
                      type="radio"
                      id="direct-debit"
                      name="switchPayment"
                      value="direct-debit"
                      defaultChecked={selectedMethods[0] === 'direct-debit'}
                      onClick={() => setSelectedTab('direct-debit')}
                    />
                    <label htmlFor="direct-debit">Direct Debit</label>
                  </>
                )}
                {selectedMethods.includes('open-banking') && (
                  <>
                    <input
                      type="radio"
                      id="open-banking"
                      name="switchPayment"
                      value="open-banking"
                      defaultChecked={selectedMethods[0] === 'open-banking'}
                      onClick={() => setSelectedTab('open-banking')}
                    />
                    <label htmlFor="open-banking">Open Banking</label>
                  </>
                )}
                <div className={`switch-wrapper ${containerClass}`}>
                  <div className="switch">
                    {selectedMethods.includes('credit-card') && <div>Card</div>}
                    {selectedMethods.includes('direct-debit') && <div>Direct Debit</div>}
                    {selectedMethods.includes('open-banking') && <div>Open Banking</div>}
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div id={selectedTab}>
            {selectedTab === 'credit-card' && (
              <>
                <div ref={formRef} className="blink-credit" data-blink-credit="1">
                  <div dangerouslySetInnerHTML={{ __html: elements?.ccElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="credit-card" />
                  <input type="hidden" name="intent_id" id="intent_id" value={intentId} />
                  <input type="hidden" name="intent_expiry_date" id="intent_expiry_date" value={intentExpiryDate} />
                </div>
              </>
            )}
            {selectedTab === 'direct-debit' && (
              <>
                <form ref={ddFormRef}>
                  <div dangerouslySetInnerHTML={{ __html: elements?.ddElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="direct-debit" />
                  <input type="hidden" name="intent_id" id="intent_id" value={intentId} />
                  <input type="hidden" name="intent_expiry_date" id="intent_expiry_date" value={intentExpiryDate} />
                </form>
              </>
            )}
            {selectedTab === 'open-banking' && (
              <>
                <form ref={obFormRef}>
                  <div dangerouslySetInnerHTML={{ __html: elements?.obElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="open-banking" />
                  <input type="hidden" name="intent_id" id="intent_id" value={intentId} />
                  <input type="hidden" name="intent_expiry_date" id="intent_expiry_date" value={intentExpiryDate} />
                </form>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

const settings = getSetting('blink_data', {});
const label = decodeEntities(settings?.title || 'Blink');
const enabled = settings?.makePayment || false;
const selectedMethods = Array.isArray(settings.selected_methods) ? settings.selected_methods : [];
const methodCount = selectedMethods.length;
const containerClass = methodCount === 1 ? 'one' : methodCount === 2 ? 'two' : '';
const canMakeBlinkPayment = (paymentMethodData = {}) => {
  const cartTotals = paymentMethodData.cartTotals || paymentMethodData.cart?.cartTotals || paymentMethodData.cartData?.totals || {};
  const total = cartTotals.total_price;
  const hasConfiguredMethods = selectedMethods.length > 0;
  const hasPayableAmount = total !== undefined && total !== null && parseFloat(total) > 0;

  return enabled && hasConfiguredMethods && hasPayableAmount;
};

registerPaymentMethod({
  name: 'blink',
  label: <Label settings={settings} />,
  content: <BlinkPayment settings={settings} />,
  edit: <Content settings={settings} />,
  canMakePayment: canMakeBlinkPayment,
  ariaLabel: label,
  supports: {
    features: [
      'products',
      'refunds',
    ],
  },
});
