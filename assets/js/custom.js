jQuery(function($){

    var blink_checkout_form = {
        $form: jQuery( 'form.woocommerce-checkout' ),
        init: function() {
            jQuery( document.body ).on( 'updated_checkout', this.updated_checkout );
        },
        updated_checkout: function() {  
            
            var paymentMode = jQuery('input[name=payment_method]:checked').val();
            if(paymentMode == 'blink')
            {
                jQuery('#place_order').hide();
            }else{
                jQuery('#place_order').show();

            }
            
            var paymentBy = blink_checkout_form.$form.find('input[name=payment_by]').val();
            console.log(paymentBy);
        
            if(paymentBy != ''){
                blink_checkout_form.$form.submit();
                return;
            }  
              
            
        }
    };

    blink_checkout_form.init();

    jQuery(document).ready(function () {
        if($('#blink-card').length)
        {
            var $form = $('#blink-card');
            var auto = {
            autoSetup: true,
            autoSubmit: true,
            };
            try {
            var hf = $form.hostedForm(auto);
            var screen_width = (window && window.screen ? window.screen.width : '0');
            var screen_height = (window && window.screen ? window.screen.height : '0');
            var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
            var language = (window && window.navigator ? (window.navigator.language ? window.navigator
                .language : window.navigator.browserLanguage) : '');
            var java = (window && window.navigator ? navigator.javaEnabled() : false);
            var timezone = (new Date()).getTimezoneOffset();
            
            $form.find('input[name=customer_name]').val(order_params.customer_name);
            $form.find('input[name=customer_email]').val(order_params.customer_email);
            $form.find('input[name=customer_address]').val(order_params.billing_address_1 + ', ' + order_params.billing_address_2);
            $form.find('input[name=customer_postcode]').val(order_params.billing_postcode);
            $form.find('input[name=device_timezone]').val(timezone);
            $form.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
            $form.find('input[name=device_accept_language]').val(language);
            $form.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
                screen_depth);
            } catch(e) {
            //Add your exception handling code here
            }
        }

        if($('#blink-debit').length)
        {
            try{
            var $form = $('#blink-debit');
            
            $form.find('input[name=given_name]').val(order_params.billing_first_name);
            $form.find('input[name=family_name]').val(order_params.billing_last_name);
            $form.find('input[name=email]').val(order_params.billing_email);
            $form.find('input[name=account_holder_name]').val(order_params.customer_name);
            $form.find('input[name=customer_address]').val(order_params.billing_address_1 + ', ' + order_params.billing_address_2);
            $form.find('input[name=customer_postcode]').val(order_params.billing_postcode);
            } catch(e) {
            //Add your exception handling code here
            }
        }
        if($('#blink-open').length)
        {
            try{
            var $form = $('#blink-open');
            $form.find('input[name=user_name]').val(order_params.customer_name);
            $form.find('input[name=user_email]').val(order_params.customer_email);
            $form.find('input[name=customer_address]').val(order_params.billing_address_1 + ', ' + order_params.billing_address_2);
            $form.find('input[name=customer_postcode]').val(order_params.billing_postcode);
            } catch(e) {
            //Add your exception handling code here
            }
        }

        var paymentMode = jQuery('input[name=payment_method]:checked').val();
        if(paymentMode == 'blink')
        {
            jQuery('#place_order').hide();
        }else{
            jQuery('#place_order').show();

        }
        
    });
    
    if (jQuery(".blink-api-section").width() < 500)
    jQuery('.blink-api-section').addClass('responsive-screen');
    else
    jQuery('.blink-api-section').removeClass('responsive-screen');

    jQuery('form.checkout').on('change', 'input[name="payment_method"]', function(){
        var paymentMode = jQuery('input[name=payment_method]:checked').val();
            if(paymentMode == 'blink')
            {
                jQuery('#place_order').hide();
            }else{
                jQuery('#place_order').show();

            }
    });
     
});

// Resize Function 
jQuery(document).ready(blinkfunction);
jQuery(window).on('resize', blinkfunction);

function blinkfunction() {
// do whatever
if (jQuery(".blink-api-section").width() < 500)
    jQuery('.blink-api-section').addClass('responsive-screen');
else
    jQuery('.blink-api-section').removeClass('responsive-screen');
}

var blink_order_review_form = {
    init: function() {  
        
        var paymentMode = jQuery('input[name=payment_method]:checked').val();
        if(paymentMode == 'blink')
        {
            jQuery('#place_order').hide();
        }else{
            jQuery('#place_order').show();

        }
        
        var paymentBy = jQuery('input[name=payment_by]').val();
        console.log(paymentBy);
    
        if(paymentBy != ''){
            jQuery('#order_review').submit();
            return;
        }  
          
        
    }
};


var updatePaymentBy = function(data) {

    var $form = jQuery('#payment_by').closest('form');
    jQuery('#payment_by').val(data);    
    
    if($form[0].id == 'order_review')
    {   
        blink_order_review_form.init();
    }
    else
    {
        jQuery( document.body ).trigger( 'update_checkout' );
    }

}





  











         
     
