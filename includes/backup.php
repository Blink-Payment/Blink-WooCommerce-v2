 public function payment_fields() {
		if (is_array($this->paymentMethods) && empty($this->paymentMethods)) {
			echo '<p> Unable to process any payment at this moment! </p>';
		} else {
			if ($this->description) {
				echo '<p>' . esc_html($this->description) . '</p>';
			}
		}
		// $request = $_REQUEST;

		//echo 'gourab '.get_option('payment_type');?>

		
			<?php
			  
			$payment_type = get_option('payment_type');
			$payment_type = $payment_type ? $payment_type : 'credit-card';
			//print_r($this->paymentMethods);
			//print_r($this->paymentIntent); 
			if (is_array($this->paymentMethods) && !empty($this->paymentMethods)) 
			{
				//print_r($this->paymentMethods); //die;
	
			?>
			<style>
			/* Hide all tab content by default */
			.tab-content {
				display: none;
			}

			/* Show the selected tab content */
			.tab-content.active {
				display: block;
			}

			/* Style for tabs */
			.blink-pay-options {
				display: inline-block;
				margin-right: 10px;
				cursor: pointer;
			}

			/* Style for active tab */
			.blink-pay-options.active {
				/* background-color: #f0f0f0; */
			}

			/* Style for tab content */
			.blink-api-tabs-content {
				border: 1px solid #ccc;
				padding: 10px;
			}
			</style>
			<script>
					function updatePaymentBy2(method) {
						var redirectURL = '<?php echo esc_url(add_query_arg(array('gateway' => 'blink'), wc_get_checkout_url())); ?>';
						redirectURL += '&p=' + method;
						window.location.href = redirectURL;
					}
				</script>
				<section class="blink-api-section">
					<div class="blink-api-form-stracture">
						<section class="blink-api-tabs-content">
							<?php foreach ($this->paymentMethods as $method) : ?>
								<div class="blink-pay-options <?php if ($method == $payment_type) echo 'active';?>" data-tab="<?php echo $payment_type; ?>">
									<a href="javascript:void(0);" onclick="updatePaymentBy2('<?php echo $method; ?>')">Pay with <?php echo ucfirst($method); ?></a>
								</div>
								
								
							<?php endforeach; ?>
							<div id="tab-<?php echo $payment_type; ?> " class="tab-content active">
							<?php //print_r($this->paymentIntent); die;
								if ('credit-card' == $payment_type && $this->paymentIntent['element']['ccElement']) {
									echo $this->paymentIntent['element']['ccElement'];
								}
								if ('direct-debit' == $payment_type && $this->paymentIntent['element']['ddElement']) {
									echo $this->paymentIntent['element']['ddElement'];
								}
								if ('open-banking' == $payment_type && $this->paymentIntent['element']['obElement']) {
									echo $this->paymentIntent['element']['obElement'];
								}
								
								
								
								?>
								</div>
							<input type="hidden" name="payment_type" value="<?php echo $payment_type; ?>" />
							<input type="hidden" name="transaction_unique" value="<?php echo $this->paymentIntent['transaction_unique'];?>">
												<input type="hidden" name="amount" value="<?php echo $this->paymentIntent['amount'];?>">
												<input type="hidden" name="intent_id" value="<?php echo $this->paymentIntent['id'];?>">
												<input type="hidden" name="intent" value="<?php echo $this->paymentIntent['payment_intent'];?>">
												<input type="hidden" name="access_token" value="<?php echo $this->accessToken;?>">
												<input type="hidden" name="payment_by" id="payment_by" value="<?php echo $method;?>">
												<input type="hidden" name="action" value="blinkSubmitPayment">
												<input type="hidden" name="order_id" value="<?php //echo $blinkPay?>">
						</section>
					</div>
				</section>
				
			<?php
			} else {
				?>
				<section class="blink-api-section">
					<div class="blink-api-form-stracture">
						<input type="hidden" name="payment_by" value="" />
					</div>
				</section>
				<?php
			}
	}