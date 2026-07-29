<?php

define('ABSPATH', __DIR__ . '/');

$test_orders = array();
$test_options = array();
$test_preauthorize_payments = false;

function __($text, $domain = null) {
    return $text;
}

function add_option($key, $value) {
    global $test_options;
    if (isset($test_options[$key])) {
        return false;
    }
    $test_options[$key] = $value;
    return true;
}

function delete_option($key) {
    global $test_options;
    unset($test_options[$key]);
    return true;
}

function wc_get_order($order_id) {
    global $test_orders;
    return isset($test_orders[$order_id]) ? $test_orders[$order_id] : null;
}

function WC() {
    static $woocommerce;
    if (!$woocommerce) {
        $woocommerce = new class {
            public $cart = null;
            public $payment_gateways;

            public function __construct() {
                $this->payment_gateways = new class {
                    public function payment_gateways() {
                        global $test_preauthorize_payments;
                        return array(
                            'blink' => new class($test_preauthorize_payments) {
                                public $preauthorize_payments;

                                public function __construct($preauthorize_payments) {
                                    $this->preauthorize_payments = $preauthorize_payments;
                                }
                            },
                        );
                    }
                };
            }
        };
    }
    return $woocommerce;
}

require_once dirname(__DIR__) . '/includes/helper.php';

class Blink_Test_Order {
    private $id;
    private $status = 'pending';
    private $meta;
    public $payment_complete_calls = 0;
    public $transaction_id = '';
    public $notes = array();

    public function __construct($id, $is_preauth) {
        $this->id = $id;
        $this->meta = array('_blink_preauth' => $is_preauth ? 'yes' : 'no');
    }

    public function get_id() {
        return $this->id;
    }

    public function get_payment_method() {
        return 'blink';
    }

    public function get_meta($key, $single = true) {
        return isset($this->meta[$key]) ? $this->meta[$key] : '';
    }

    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
    }

    public function save() {
        return $this->id;
    }

    public function add_order_note($note) {
        $this->notes[] = $note;
    }

    public function has_status($statuses) {
        return in_array($this->status, (array) $statuses, true);
    }

    public function update_status($status, $note = '') {
        $this->status = $status;
    }

    public function payment_complete($transaction_id = '') {
        $this->payment_complete_calls++;
        $this->transaction_id = $transaction_id;
        $this->status = 'processing';
    }

    public function get_status() {
        return $this->status;
    }
}

function blink_test_order($is_preauth) {
    static $next_id = 1000;
    global $test_orders;
    $order = new Blink_Test_Order($next_id++, $is_preauth);
    $test_orders[$order->get_id()] = $order;
    return $order;
}

function blink_assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$tests = array(
    'pre-auth authorized is on-hold' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-AUTH', '  AUTHORIZED  ', 'Card');
        blink_assert_same('on-hold', $order->get_status(), 'Authorized pre-auth status');
    },
    'gateway Pre-Auth status is on-hold' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-PRE-AUTH', 'Pre-Auth', 'Card');
        blink_assert_same('on-hold', $order->get_status(), 'Pre-Auth gateway status');
    },
    'pre-auth declined is failed' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-DECLINED', 'declined', 'Card');
        blink_assert_same('failed', $order->get_status(), 'Declined pre-auth status');
    },
    'pre-auth failed is failed' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-FAILED', 'failed', 'Card');
        blink_assert_same('failed', $order->get_status(), 'Failed pre-auth status');
    },
    'pre-auth expired is failed' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-EXPIRED', 'expired', 'Card');
        blink_assert_same('failed', $order->get_status(), 'Expired pre-auth status');
    },
    'pre-auth captured uses paid lifecycle' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-CAPTURED', 'captured', 'Card');
        blink_assert_same('processing', $order->get_status(), 'Captured pre-auth status');
        blink_assert_same(1, $order->payment_complete_calls, 'Captured payment_complete call count');
        blink_assert_same('BL-CAPTURED', $order->transaction_id, 'Captured transaction ID');
    },
    'non-pre-auth declined is failed' => function () {
        $order = blink_test_order(false);
        blink_change_status($order, 'BL-SALE-DECLINED', 'declined', 'Card');
        blink_assert_same('failed', $order->get_status(), 'Declined sale status');
    },
    'unknown pre-auth status is failed' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-UNKNOWN', 'unexpected gateway state', 'Card');
        blink_assert_same('failed', $order->get_status(), 'Unknown pre-auth status');
    },
    'reversed pre-auth maps to hold' => function () {
        $order = blink_test_order(true);
        blink_assert_same('hold', blink_get_status('reversed', '', $order), 'Reversed pre-auth status');
    },
    'reversed pre-auth order is on-hold' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-REVERSED', 'reversed', 'Card');
        blink_assert_same('on-hold', $order->get_status(), 'Reversed pre-auth order status');
    },
    'reversed non-pre-auth preserves hold mapping' => function () {
        $order = blink_test_order(false);
        blink_assert_same('hold', blink_get_status('reversed', '', $order), 'Reversed sale status');
    },
    'pre auth pre-auth maps to hold' => function () {
        $order = blink_test_order(true);
        blink_assert_same('hold', blink_get_status('pre auth', '', $order), 'Pre auth pre-auth status');
    },
    'Preauth pre-auth maps to hold' => function () {
        $order = blink_test_order(true);
        blink_assert_same('hold', blink_get_status('Preauth', '', $order), 'Preauth pre-auth status');
    },
    'Preauth pre-auth order is on-hold' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-GOOGLE-PAY-PREAUTH', 'Preauth', 'googlepay');
        blink_assert_same('on-hold', $order->get_status(), 'Google Pay Preauth order status');
    },
    'Preauth non-pre-auth remains failed' => function () {
        $order = blink_test_order(false);
        blink_assert_same('failed', blink_get_status('Preauth', '', $order), 'Non-pre-auth Preauth status');
    },
    'failed 3DS pre-auth is failed' => function () {
        $order = blink_test_order(true);
        blink_change_status($order, 'BL-3DS-FAILED', 'Failed_3DS', 'Card');
        blink_assert_same('failed', $order->get_status(), 'Failed 3DS pre-auth status');
    },
    'explicit sale metadata overrides enabled global pre-auth' => function () {
        global $test_preauthorize_payments;
        $test_preauthorize_payments = true;

        $order = blink_test_order(false);
        blink_change_status($order, 'BL-SALE-APPROVED', 'approved', 'Card');

        blink_assert_same('processing', $order->get_status(), 'Approved sale status');
        blink_assert_same('no', $order->get_meta('_blink_preauth', true), 'Sale pre-auth metadata');
        $test_preauthorize_payments = false;
    },
);

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name} - {$error->getMessage()}\n");
    }
}

echo count($tests) . ' tests, ' . $failures . " failures\n";
exit($failures === 0 ? 0 : 1);
