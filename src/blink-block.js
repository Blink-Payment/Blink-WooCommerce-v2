const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { useState, useEffect, useRef } = window.wp.element;
const { getSetting } = window.wc.wcSettings;
import { decodeEntities } from '@wordpress/html-entities';

const Content = (props) => {
  const { settings } = props;
  return decodeEntities(settings.description || '');
};


const BlinkPayment = (props) => {
  const { settings, eventRegistration, emitResponse, billing } = props;
  const [selectedTab, setSelectedTab] = useState('credit-card'); // Default to Credit Card tab
  const [formFields, setFormFields] = useState([]);
  const [formData, setFormData] = useState({});
  const formRef = useRef(null); 
  const ddFormRef = useRef(null); 
  const obFormRef = useRef(null); 
  const googleFormRef = useRef(null);
  const appleFormRef = useRef(null);
  const [paymentToken, setPaymentToken] = useState(null);
  const { onCheckoutValidation, onPaymentSetup, onCheckoutFail  } = eventRegistration;
  const { billingAddress } = billing;
  const billingName = `${billingAddress.first_name} ${billingAddress.last_name}`;
  const billingFullAddress = `${billingAddress.address_1}, ${billingAddress.address_2}`;
  const screen_width = (window && window.screen ? window.screen.width : '0');
  const screen_height = (window && window.screen ? window.screen.height : '0');
  const screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
  const language = (window && window.navigator ? (window.navigator.language ? window.navigator.language : window.navigator.browserLanguage) : '');
  const java = (window && window.navigator ? navigator.javaEnabled() : false);
  const timezone = (new Date()).getTimezoneOffset();


	useEffect( () => {
		const unsubscribe = onCheckoutFail( () => {
      return {
        type: emitResponse.responseTypes.ERROR,
        message: 'Payment failed. Please check your details or try a different method. Contact support if the issue persists.',
        messageContext: emitResponse.noticeContexts.PAYMENTS,
      };
    } );
		return unsubscribe;
	}, [ onCheckoutFail ] );

  const getCurrentForm = () => {
    
    if (selectedTab === 'direct-debit') {
      return ddFormRef.current;
    }
    if (selectedTab === 'open-banking') {
      return obFormRef.current;
    }
    if (selectedTab === 'google-pay') {
      return googleFormRef.current;
    }
    if (selectedTab === 'apple-pay') {
      return appleFormRef.current;
    }

    return formRef.current;

  }

  useEffect(() => {  
    const currentForm = window.jQuery(getCurrentForm());

    if (selectedTab === 'credit-card') {
      currentForm.find('input[name=customer_email]').hide();
      currentForm.find('label.blink-form__label:contains("Email")').hide();
      currentForm.find('input[name=customer_postcode]').hide();
      currentForm.find('input[name=customer_address]').hide();
      currentForm.find('label.blink-form__label:contains("Address")').hide();
      initializeHostedForm();
    }
    
    if(selectedTab === 'direct-debit'){
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
    currentForm.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
        screen_depth);
    currentForm.find('input[name=remote_address]').val(blink_params.remoteAddress);

  }, [selectedTab, billingAddress]);

  // Function to initialize the hosted form using jQuery
  const initializeHostedForm = () => {
    if (window.jQuery && formRef.current ) {
      const hostedForm = window.jQuery(formRef.current).hostedForm('instance');
      if(!hostedForm){
        window.jQuery(formRef.current).hostedForm({
          autoSetup: true,
          autoSubmit: false
        });
      }
    }
  };

const handleSubmitCC = async () => {
  if (window.jQuery && formRef.current && selectedTab === 'credit-card') {
    try {
      const hostedForm = window.jQuery(formRef.current).hostedForm('instance');
      const paymentDetails = await hostedForm.getPaymentDetails();
      
      if(paymentDetails){
        if (paymentDetails.success) {
            setPaymentToken(paymentDetails.paymentToken);
          return true;
        }

        return {
          type: emitResponse.responseTypes.ERROR,
          errorMessage: paymentDetails.errors.cardNumber || paymentDetails.message,
          messageContext: emitResponse.noticeContexts.PAYMENTS,
        };

      }

    } catch (error) {
      console.error('Error getting payment details:', error);
    }
  } else {
    console.error('FormRef is null, jQuery is not available, or hostedFormInstance is missing');
  }
};

	useEffect( () => {
		const unsubscribe = onCheckoutValidation( async () => {
      const currentForm = getCurrentForm();
      const formDataArray = window.jQuery(currentForm).serializeArray();
      const currentFormFields = formDataArray.map(field => ({ name: field.name }));
      setFormFields(currentFormFields);

      const currentFormData = {};
      formDataArray.forEach(field => {
        currentFormData[field.name] = field.value;
      });
      setFormData(currentFormData);
      
      const allFieldsFilled = currentFormFields.every(field => currentFormData[field.name] !== undefined && currentFormData[field.name] !== '');
        if (!allFieldsFilled) {
          return {
            type: emitResponse.responseTypes.ERROR,
            errorMessage: 'Please fill out all required fields.',
            messageContext: emitResponse.noticeContexts.PAYMENTS,
          };
        }

      if (selectedTab === 'credit-card') {
        return await handleSubmitCC();
      }
    });
		return unsubscribe;
	}, [ onCheckoutValidation, selectedTab, formData, formFields ] );

  useEffect(() => {

    const unsubscribe = eventRegistration.onPaymentSetup(async () => {
      
      const paymentData = {
        ...formData,
        ...(selectedTab === 'credit-card' && { paymentToken: paymentToken }),
        customer_address: formData.customer_address || billingFullAddress,
        customer_postcode: formData.customer_postcode || billingAddress.postcode,
      
      };
        
      if (selectedTab === 'credit-card') {
        if (!paymentToken) {  
          return {
            type: emitResponse.responseTypes.ERROR,
            message: 'Payment Token mismatched!!',
            messageContext: emitResponse.noticeContexts.PAYMENTS,
          };
        }
      } 

      return {
        type: emitResponse.responseTypes.SUCCESS,
        meta: {
          paymentMethodData: paymentData,
        },
      };
    });
    return unsubscribe;
  }, [onPaymentSetup, selectedTab, formData, paymentToken]);

  useEffect(() => {

    if(settings.isSafari && settings.apple_pay_enabled) {

      const loadApplePayApi = new Promise((resolve, reject) => {
        const script1 = document.createElement('script');
        script1.src = 'https://secure.blinkpayment.co.uk/assets/js/apple-pay-api.js';
        script1.async = true;
        script1.onload = resolve; // Resolve when google-pay-api.js loads
        script1.onerror = reject;
        document.body.appendChild(script1);
      });
  
      const loadApplePayJs = new Promise((resolve, reject) => {
        const script2 = document.createElement('script');
        script2.src = 'https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js';
        script2.async = true;
        script2.onload = resolve; // Resolve when pay.js loads
        script2.onerror = reject;
        document.body.appendChild(script2);
      });

      window.jQuery(appleFormRef.current).submit(function(event) {
        event.preventDefault();
        setSelectedTab('apple-pay');
        window.jQuery('.wc-block-components-checkout-place-order-button').click();
      });

    } else { 
      const googleScriptElement = document.querySelector('#blinkGooglePay script[src="https://pay.google.com/gp/p/js/pay.js"]');
      const googleElement = document.querySelector('#gpay-button-online-api-id');
  
      const loadGooglePayApi = new Promise((resolve, reject) => {
        const script1 = document.createElement('script');
        script1.src = 'https://secure.blinkpayment.co.uk/assets/js/google-pay-api.js';
        script1.async = true;
        script1.onload = resolve; // Resolve when google-pay-api.js loads
        script1.onerror = reject;
        document.body.appendChild(script1);
      });
  
      const loadPayJs = new Promise((resolve, reject) => {
        const script2 = document.createElement('script');
        script2.src = 'https://pay.google.com/gp/p/js/pay.js';
        script2.async = true;
        script2.onload = resolve; // Resolve when pay.js loads
        script2.onerror = reject;
        document.body.appendChild(script2);
      });

      // Run `onGooglePayLoaded` after both scripts load successfully
      Promise.all([loadGooglePayApi, loadPayJs])
      .then(() => {
        // Manually trigger the `onGooglePayLoaded` function if defined
        if (typeof window.onGooglePayLoaded === 'function') {
          if (googleScriptElement) {
            if (googleElement) {
              googleElement.remove(); // Remove the Google Pay button if it exists
            }
      
            // Get the onload attribute from the script tag and execute it
            const onloadValue = googleScriptElement.getAttribute('onload');
      
            setTimeout(() => {
              try {
                if (onloadValue) {
                  eval(onloadValue); // Run the onload JavaScript if it exists
                }
              } catch (err) {
                console.error('Error executing onload function:', err);
              }
            }, 1000);
          }
        } else {
          console.error('onGooglePayLoaded is not defined.');
        }
      })
      .catch((err) => {
        console.error('Error loading Google Pay scripts:', err);
      });

      window.jQuery(googleFormRef.current).submit(function(event) {
        event.preventDefault();
        setSelectedTab('google-pay');
        window.jQuery('.wc-block-components-checkout-place-order-button').click();
      });

    }

    // Cleanup scripts when component unmounts
    return () => {
      if(settings.isSafari && settings.apple_pay_enabled) {

        document.querySelectorAll('script[src="https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js"], script[src="https://secure.blinkpayment.co.uk/assets/js/apple-pay-api.js"]').forEach(script => {
          document.body.removeChild(script);
        });

      } else {

        document.querySelectorAll('script[src="https://pay.google.com/gp/p/js/pay.js"], script[src="https://secure.blinkpayment.co.uk/assets/js/google-pay-api.js"]').forEach(script => {
          document.body.removeChild(script);
        });

      }
    };
  }, []);


  return (
    <div className="blink-gutenberg payment_method_blink">

      <div className="form-container">
      <>
          {settings.isSafari && settings.apple_pay_enabled ? (
              <form ref={appleFormRef}>
                  <div dangerouslySetInnerHTML={{ __html: settings.elements?.apElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="apple-pay" />
              </form>
          ) : (
              <form ref={googleFormRef}>
                  <div dangerouslySetInnerHTML={{ __html: settings.elements?.gpElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="google-pay" />
              </form>
          )}
      </>


        <div className='form-group mb-4'>
            <div className='form-group mb-4'>
              <div className="select-batch" style={{ width: "100%" }}>
                <div className="switches-container" id="selectBatch">
                  
                  <input type="radio" id="credit-card" name="switchPayment" value="credit-card" defaultChecked onClick={() => setSelectedTab('credit-card')} />
                  
                  <input type="radio" id="direct-debit" name="switchPayment" value="direct-debit" onClick={() => setSelectedTab('direct-debit')}/>
                  
                  <input type="radio" id="open-banking" name="switchPayment" value="open-banking" onClick={() => setSelectedTab('open-banking')}/>
                  
                  <label htmlFor="credit-card">Card</label>
                  
                  <label htmlFor="direct-debit">Direct Debit</label>
                  
                  <label htmlFor="open-banking">Open Banking</label>
                  
                  <div className="switch-wrapper">
                    <div className="switch">
                      <div>Card</div>
                      <div>Direct Debit</div>
                      <div>Open Banking</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div id={selectedTab}>
              {selectedTab === 'credit-card' && (
                <>
                <form ref={formRef} name="blink-credit" id="blink-credit-form" method="POST" className="wc-block-checkout__form blink-credit">
                  <div dangerouslySetInnerHTML={{ __html: settings.elements?.ccElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="credit-card" />
                </form>
                </>
              )}

              {selectedTab === 'direct-debit' && (
                <>
                <form ref={ddFormRef}>
                  <div dangerouslySetInnerHTML={{ __html: settings.elements?.ddElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="direct-debit" />
                </form> 
                </>
              )}

              {selectedTab === 'open-banking' && (
                <>
                <form ref={obFormRef}>
                  <div dangerouslySetInnerHTML={{ __html: settings.elements?.obElement }} />
                  <input type="hidden" name="payment_by" id="payment_by" value="open-banking" />
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

registerPaymentMethod({
  name: 'blink',
  label: 'Blink',
  content: <BlinkPayment settings={settings} />,
  edit: <Content settings={settings} />,
  canMakePayment: () => enabled,
  ariaLabel: label,
  supports: {
    features: [
      'products',
      'refunds',
    ],
  },
});
