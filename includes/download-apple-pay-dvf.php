<?php
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="apple-developer-merchantid-domain-association"');
echo file_get_contents('https://secure.blinkpayment.co.uk/.well-known/apple-developer-merchantid-domain-association');
exit;
