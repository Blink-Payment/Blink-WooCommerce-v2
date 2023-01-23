var successCallback = function(data) {

	var checkout_form = jQuery( 'form.woocommerce-checkout' );

	// add a token to our hidden input field
	// console.log(data) to find the token
	//checkout_form.find('#misha_token').val(data.token);

	// deactivate the tokenRequest function event
	checkout_form.off( 'checkout_place_order', tokenRequest );

	// submit the form now
	checkout_form.submit();

};

var errorCallback = function(data) {
    console.log(data);
};

var tokenRequest = function(data) {

    var checkout_form = jQuery( 'form.woocommerce-checkout' );

     var payToken = checkout_form.find('input[name=paymentToken]').val();

    if(payToken){
        console.log('token added');
        successCallback();
    }    

	// here will be a payment gateway function that process all the card data from your form,
	// maybe it will need your Publishable API key which is misha_params.publishableKey
	// and fires successCallback() on success and errorCallback on failure
	return false;
		
};

jQuery(function($){

    

	var checkout_form = jQuery( 'form.woocommerce-checkout' );
    //console.log(checkout_form);
	//checkout_form.on( 'checkout_place_order', tokenRequest );

    // jQuery(document).on('change','.woocommerce-checkout-payment',function(){
    //     alert('started');
    // // Your code can be here
    // });

    // jQuery$(document.body).on('update_checkout',  function () {
    //     alert('update_checkout');
    //    });

                    

    jQuery(document.body).on('updated_checkout', function(){

        enableHostedForm();

        jQuery("a[href='#credit_card']").on('shown.bs.tab', function(e) {
            jQuery(document.body).trigger("update_checkout");
        });
        
        // or even this one if we want the earlier event
        jQuery("a[href='#direct_debit']").on('show.bs.tab', function(e) {
            jQuery('#place_order').attr('type', 'button');
            jQuery('#place_order').attr('onClick', 'directDebit(this);');

        });
        
    });

    
    
    checkout_form.on( 'checkout_place_order', tokenRequest );


});

jQuery('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
    var target = $(e.target).attr("href") // activated tab
    alert(target);
  });

 function directDebit(e)
 {
    var checkout_form = jQuery( 'form.woocommerce-checkout' );
	checkout_form.off( 'checkout_place_order', tokenRequest );

	// submit the form now
	checkout_form.submit();
 }

 function enableHostedForm()
 {
    var $form = jQuery('form[name="checkout"]');
    var auto = {
        autoSetup: true,
        autoSubmit: true,
    };
    try {
    var hf = $form.hostedForm(auto);
    } catch(e) {
    //Add your exception handling code here
    }    
    var screen_width = (window && window.screen ? window.screen.width : '0');
    var screen_height = (window && window.screen ? window.screen.height : '0');
    var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
    var language = (window && window.navigator ? (window.navigator.language ? window.navigator
        .language : window.navigator.browserLanguage) : '');
    var java = (window && window.navigator ? navigator.javaEnabled() : false);
    var timezone = (new Date()).getTimezoneOffset();
    
    $form.find('input[name=device_timezone]').val(timezone);
    $form.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
    $form.find('input[name=device_accept_language]').val(language);
    $form.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
        screen_depth);
 }
    