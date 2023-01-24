var successCallback = function(data) {

	var checkout_form = jQuery( 'form.woocommerce-checkout' );

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

    var paymentBy = checkout_form.find('input[name=payment_by]').val();

    if(paymentBy == 'credit_card'){
        var payToken = checkout_form.find('input[name=paymentToken]').val();

        if(payToken){
            console.log('token added');
            successCallback();
        } 
    }else{
            successCallback();
    }   

	// here will be a payment gateway function that process all the card data from your form,
	// maybe it will need your Publishable API key which is misha_params.publishableKey
	// and fires successCallback() on success and errorCallback on failure
	return false;
		
};

jQuery(function($){

                    
    checkoutPlaceOrder();

    jQuery(document.body).on('updated_checkout', function(){

        enableHostedForm();

        jQuery("a[href='#credit_card']").on('shown.bs.tab', function(e) {
            jQuery('#payment_by').val('credit_card');
            location.reload(true);

        });
        
        // or even this one if we want the earlier event
        jQuery("a[href='#direct_debit']").on('show.bs.tab', function(e) {
            jQuery('#payment_by').val('direct_debit');
            jQuery('#place_order').attr('onClick', 'directDebit(this);');
            //jQuery( document.body ).trigger( 'init_checkout' );


        });
        
    });

});

var directDebit = function (e)
 {
    var checkout_form = jQuery( 'form.woocommerce-checkout' );
	checkout_form.off();
	// submit the form now
	checkout_form.submit();
 };

var enableHostedForm = function(data) {

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

};

var checkoutPlaceOrder = function(data) {

	var checkout_form = jQuery( 'form.woocommerce-checkout' );
    checkout_form.on( 'checkout_place_order', tokenRequest );

};
    