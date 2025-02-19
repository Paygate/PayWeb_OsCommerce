<?php

/**
 * Copyright (c) 2025 Payfast (Pty) Ltd
 */

namespace common\modules\orderPayment;

require_once __DIR__ . '/lib/paygate/modules/payment/PaygateApi.php';

use common\models\Currencies;
use common\models\CustomersBasket;
use common\models\Orders;
use common\models\OrdersPayment;
use Paygate\Payment\PaygateApi;
use Yii;
use common\classes\modules\ModulePayment;
use common\classes\modules\ModuleStatus;
use common\classes\modules\ModuleSortOrder;
use backend\services\OrdersService;

use function Symfony\Component\String\b;


define(
    'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_ERROR_MESSAGE',
    'There has been an error processing your payment. Please try again.'
);
define(
    'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_DATA_ERROR',
    'There has been an error verifying the merchant data. Please try again.'
);

class paygate extends ModulePayment
{
    public $processing_status;

    public $paid_status;
    public $fail_paid_status;
    public $countries = [];

    private string $paygateMerchantId;
    private string $paygateSecretKey;

    public $public_title;

    protected $defaultTranslationArray = [
        'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE'       => 'Paygate',
        'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_DESCRIPTION' => 'Paygate',
        'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_NOTES'       => '',
    ];

    // Class constructor
    public function __construct()
    {
        parent::__construct();

        $this->countries   = [];
        $this->code        = 'paygate';
        $this->title       = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE : 'Paygate';
        $this->description = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_DESCRIPTION'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_DESCRIPTION : 'Paygate';

        $this->enabled = true;

        if (!defined('MODULE_PAYMENT_PAYGATE_PAYWEB3_STATUS')) {
            $this->enabled = false;

            return;
        }

        $this->processing_status = MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID;
        $this->paid_status       = MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PAID_STATUS_ID;
        $this->fail_paid_status  = MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_FAIL_STATUS_ID;

        $this->paygateMerchantId = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_PAYGATEID'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_PAYGATEID : '';
        $this->paygateSecretKey  = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ENCRYPTIONKEY'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_ENCRYPTIONKEY : '';
        $this->disableNotify     = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_DISABLE_IPN'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_DISABLE_IPN === 'True' : false;

        $this->form_action_url = 'https://secure.paygate.co.za/payweb3/process.trans';
        $this->ordersService   = \Yii::createObject(OrdersService::class);
        $this->update();
    }

    /**
     * @param Orders $order
     * @param int $orderId
     * @param $errorMsg
     *
     * @return array
     */
    public function processFailedPayment(Orders $order, int $orderId, $errorMsg): void
    {
        $order->orders_status  = $this->fail_paid_status;
        $order->payment_method = 'paygate';
        $order->save();
        $sql_data_array = array(
            'orders_id'         => $orderId,
            'orders_status_id'  => (MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID > 0 ?
                (int)MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID : (int)DEFAULT_ORDERS_STATUS_ID),
            'date_added'        => 'now()',
            'customer_notified' => '0',
            'comments'          => 'Paygate message ' . $errorMsg
        );

        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
    }

    private function update()
    {
        if (!$this->paygateMerchantId || !$this->paygateSecretKey) {
            $this->enabled = false;
        }
    }

    public function updateTitle($platformId = 0)
    {
        $mode = $this->get_config_key((int)$platformId, 'MODULE_PAYMENT_PAYGATE_PAYWEB3_DEBUG');
        if ($mode !== false) {
            $mode  = strtolower($mode);
            $title = (defined('MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE') ? constant(
                'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE'
            ) : '');
            if ($title != '') {
                $this->title = $title;
                if ($mode == 'true') {
                    $this->title .= ' [Test]';
                }
            }
            $titlePublic = (defined('MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE') ? constant(
                'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE'
            ) : '');
            if ($titlePublic != '') {
                $this->public_title = $titlePublic;
                if ($mode == 'true') {
                    $this->public_title .= " [{$this->code}; Test]";
                }
            }

            return true;
        }

        return false;
    }

    public function getTitle($method = '')
    {
        return $this->public_title;
    }

    public function update_status()
    {
        $order = $this->manager->getOrderInstance();

        if (($this->enabled) && ((int)MODULE_PAYMENT_PAYGATE_PAYWEB3_ZONE > 0)) {
            $check_flag  = false;
            $check_query = tep_db_query(
                "select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PAYGATE_PAYWEB3_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id"
            );
            while ($check = tep_db_fetch_array($check_query)) {
                if ($check['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
            }

            if (!$check_flag) {
                $this->enabled = false;
            }
        }
    }

    public function javascript_validation()
    {
        return false;
    }

    public function selection()
    {
        $selection = array(
            'id'     => $this->code,
            'module' => $this->title
        );
        if (defined('MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_NOTES') && !empty(MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_NOTES)) {
            $selection['notes'][] = MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_NOTES;
        }

        return $selection;
    }

    public function pre_confirmation_check()
    {
        return false;
    }

    public function confirmation()
    {
        return true;
    }

    public function process_button()
    {
        $order = $this->manager->getOrderInstance();

        $order->info['order_status'] = $this->processing_status;

        $order->save_order();

        $order->save_totals();

        $order->save_products(false);

        $stock_updated = false;

        $orderId = $order->order_id;
        $post    = $this->sanitizeInput($_POST);

        $pgPayGateID = MODULE_PAYMENT_PAYGATE_PAYWEB3_PAYGATEID;
        $pgReference = $this->createUUID();
        $pgAmount    = (string)((int)($order->info['total'] * 100));
        $pgCurrency  = $order->info['currency'];
        $pgReturnURL = tep_href_link(
            'callback/webhooks.payment.' . $this->code,
            "action=redirect&orders_id=$orderId&reference=$pgReference",
            'SSL'
        );
        $pgNotifyURL = tep_href_link(
            'callback/webhooks.payment.' . $this->code,
            "action=notify&orders_id=$orderId&reference=$pgReference",
            'SSL'
        );

        $pgTransactionDate = gmstrftime("%Y-%m-%d %H:%M");
        $pgCustomerEmail   = $order->customer['email_address'];

        $data = array(
            'PAYGATE_ID'       => filter_var($pgPayGateID, FILTER_SANITIZE_STRING),
            'REFERENCE'        => filter_var($pgReference, FILTER_SANITIZE_STRING),
            'AMOUNT'           => filter_var($pgAmount, FILTER_SANITIZE_NUMBER_INT),
            'CURRENCY'         => filter_var($pgCurrency, FILTER_SANITIZE_STRING),
            'RETURN_URL'       => filter_var($pgReturnURL, FILTER_SANITIZE_URL),
            'TRANSACTION_DATE' => filter_var($pgTransactionDate, FILTER_SANITIZE_STRING),
            'LOCALE'           => filter_var('en-za', FILTER_SANITIZE_STRING),
            'COUNTRY'          => filter_var($order->customer['country']['iso_code_3'], FILTER_SANITIZE_STRING),
            'EMAIL'            => filter_var($pgCustomerEmail, FILTER_SANITIZE_EMAIL),
        );

        if (!empty($post['payment_method'])) {
            $data['PAY_METHOD'] = $post['payment_method'];
        }

        if (!$this->disableNotify) {
            $data['NOTIFY_URL'] = $pgNotifyURL;
        }
        $encryption_key = MODULE_PAYMENT_PAYGATE_PAYWEB3_ENCRYPTIONKEY;

        // Set the session vars once we have cleaned the inputs
        $_SESSION['pgid']          = $data['PAYGATE_ID'];
        $_SESSION['reference']     = $data['REFERENCE'];
        $_SESSION['key']           = $encryption_key;
        $_SESSION['customerEmail'] = filter_var($pgCustomerEmail, FILTER_SANITIZE_EMAIL);

        // Initiate the Paygate 3 helper class
        $payWeb3 = new PaygateApi();

        // If debug is set to true, the curl request and result as well as the calculated
        // checksum source will be logged to the php error log
        if (MODULE_PAYMENT_PAYGATE_PAYWEB3_DEBUG) {
            $payWeb3->setDebug(true);
        }

        // Set the encryption key of your Paygate configuration
        $payWeb3->setEncryptionKey($encryption_key);

        // Set the array of fields to be posted to Paygate
        $payWeb3->setInitiateRequest($data);

        // Do the curl post to Paygate
        $payWeb3->doInitiate();
        $isValid = $payWeb3->validateChecksum($payWeb3->initiateResponse);

        $hiddenVars = '';
        if ($isValid) {
            // If the checksums match loop through the returned fields and create the redirect from
            foreach ($payWeb3->processRequest as $key => $value) {
                $hiddenVars .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
            }
        }

        return $hiddenVars;
    }

    public function after_process()
    {
        $this->manager->clearAfterProcess();
    }

    /**
     * This is hit by the redirect and/or notify POSTS from Paygate
     *
     * @return true|void
     */
    public function call_webhooks()
    {
        if ($_GET['action'] === 'redirect') {
            // Do redirect
            $this->handleRedirect($this->sanitizeInput($_GET), $this->sanitizeInput($_POST));
        } elseif ($_GET['action'] === 'notify') {
            // Do notify
            $this->handleNotify($this->sanitizeInput($_GET), $this->sanitizeInput($_POST));
            echo 'OK';
            die();
        } else {
            die();
        }
    }

    /**
     * @param array $data
     *
     * @return array
     */
    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $datum) {
            $sanitized[$key] = htmlspecialchars($datum);
        }

        return $sanitized;
    }

    /**
     * @param array $get
     * @param array $post
     *
     * @return void
     */
    private function handleRedirect(array $get, array $post): void
    {
        $orderId  = (int)$get['orders_id'];
        $order    = Orders::findOne($orderId);
        $currency = Currencies::find()
                              ->where(['code' => $order->currency])
                              ->one();
        if (!$order) {
            tep_redirect(
                tep_href_link(
                    FILENAME_CHECKOUT_PAYMENT,
                    'error_message=An error occured while processing transaction. The order could not be found',
                    'SSL',
                    true,
                    false
                )
            );
            die();
        }
        $_SESSION['customer_id'] = $order->customers_id;
        $data                    = [
            'PAYGATE_ID'         => $this->paygateMerchantId,
            'PAY_REQUEST_ID'     => $post['PAY_REQUEST_ID'] ?? '',
            'TRANSACTION_STATUS' => $post['TRANSACTION_STATUS'] ?? '',
            'REFERENCE'          => $_GET['reference'],
            'CHECKSUM'           => $post['CHECKSUM'] ?? '',
        ];
        $payWeb3                 = new PaygateApi();
        $payWeb3->setEncryptionKey($this->paygateSecretKey);
        // Validate checksum
        if (!$payWeb3->validateChecksum($data)) {
            tep_redirect(
                tep_href_link(
                    FILENAME_CHECKOUT_PAYMENT,
                    'error_message=An error occured while processing transaction. The checksum could not be verified',
                    'SSL',
                    true,
                    false
                )
            );
            die();
        }
        // Now query Paygate to get the full response - same as the notify POST response
        $queryData = [
            'PAYGATE_ID'     => $this->paygateMerchantId,
            'PAY_REQUEST_ID' => $_POST['PAY_REQUEST_ID'],
            'REFERENCE'      => $_GET['reference'],
        ];
        $payWeb3->setQueryRequest($queryData);
        $payWeb3->doQuery();
        $queryResponse = $payWeb3->queryResponse;
        if ($queryResponse == null || array_key_exists('ERROR', $queryResponse)) {
            tep_redirect(
                tep_href_link(
                    FILENAME_CHECKOUT_PAYMENT,
                    'error_message=An error occured while processing transaction. Error: ' . $queryResponse['ERROR'],
                    'SSL',
                    true,
                    false
                )
            );
            $this->processFailedPayment($order, $orderId, 'Declined Transaction');

            die();
        }
        switch ($queryResponse['TRANSACTION_STATUS']) {
            case '1':
                // Successful payment
                $this->processSuccessfulPayment($queryResponse, $order, $currency);
                $this->clearCart($order);

                tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS, 'orders_id=' . $orderId, 'SSL'));
                break;
            case '2':
                // Declined
                $this->processFailedPayment($order, $orderId, $queryResponse['RESULT_DESC']);

                tep_redirect(
                    tep_href_link(
                        FILENAME_CHECKOUT_PAYMENT,
                        'error_message=Transaction has been declined',
                        'SSL',
                        true,
                        false
                    )
                );
                break;
            case '4':
                // User cancelled
                $this->processFailedPayment($order, $orderId, $queryResponse['RESULT_DESC']);

                tep_redirect(
                    tep_href_link(
                        FILENAME_CHECKOUT_PAYMENT,
                        'error_message=User cancelled transaction',
                        'SSL',
                        true,
                        false
                    )
                );
                break;
            default:
                tep_redirect(
                    tep_href_link(
                        FILENAME_CHECKOUT_PAYMENT,
                        'error_message=An unknown error occurred',
                        'SSL',
                        true,
                        false
                    )
                );
                break;
        }
    }

    /**
     * @param \common\models\Orders $order
     *
     * @return void
     */
    private function clearCart(Orders $order): void
    {
        CustomersBasket::clearBasket($order->customers_id);
        $this->manager->clearAfterProcess();
    }

    /**
     * @param array $get
     * @param array $post
     *
     * @return void
     */
    private function handleNotify(array $get, array $post): void
    {
        $orderId  = (int)$get['orders_id'];
        $order    = Orders::findOne($orderId);
        $currency = Currencies::find()
                              ->where(['code' => $order->currency])
                              ->one();
        if (!$order) {
            echo 'OK';

            return;
        }
        $payWeb3 = new PaygateApi();
        $payWeb3->setEncryptionKey($this->paygateSecretKey);
        // Validate checksum
        if (!$payWeb3->validateChecksum($post)) {
            echo 'OK';

            return;
        }
        if (array_key_exists('ERROR', $post)) {
            echo 'OK';

            return;
        }
        switch ($post['TRANSACTION_STATUS']) {
            case '1':
                $this->processSuccessfulPayment($post, $order, $currency);
                $this->clearCart($order);
                break;
            case '2':
            case '4':
                $this->processFailedPayment($order, $orderId, $post['RESULT_DESC']);
                break;
            default:
                break;
        }
        echo 'OK';
    }

    /**
     * @param array $data
     * @param \common\models\Orders $order
     * @param \common\models\Currencies $currency
     *
     * @return void
     */
    private function processSuccessfulPayment(array $data, Orders $order, Currencies $currency): void
    {
        $orderId               = $order->orders_id;
        $order->orders_status  = $this->paid_status;
        $order->payment_method = 'paygate';
        $order->save();
        $orderPayment = OrdersPayment::find()
                                     ->where(['orders_payment_order_id' => $orderId])
                                     ->one();
        if (!$orderPayment) {
            $orderPayment                             = new OrdersPayment();
            $orderPayment->orders_payment_date_create = date('Y-m-d H:i:s');
        }
        $orderPayment->orders_payment_order_id       = $orderId;
        $orderPayment->orders_payment_amount         = $data['AMOUNT'] / 100.0;
        $orderPayment->orders_payment_currency       = $data['CURRENCY'];
        $orderPayment->orders_payment_module         = 'paygate';
        $orderPayment->orders_payment_module_name    = 'Paygate';
        $orderPayment->orders_payment_date_update    = date('Y-m-d H:i:s');
        $orderPayment->orders_payment_transaction_id = $data['TRANSACTION_ID'];
        $orderPayment->orders_payment_status         = 20;
        $orderPayment->save();
        $orderTotals = $order->getOrdersTotals()->all();
        foreach ($orderTotals as $orderTotal) {
            if ($orderTotal->class === 'ot_paid') {
                $orderTotal->value        = $data['AMOUNT'] / 100.0;
                $orderTotal->text         = $currency->symbol_left . number_format(
                        $data['AMOUNT'] / 100.0,
                        $currency->decimal_places,
                        $currency->decimal_point,
                        $currency->thousands_point
                    );
                $orderTotal->text_inc_tax = $orderTotal->text;
                $orderTotal->text_exc_tax = $orderTotal->text;
            } elseif ($orderTotal->class === 'ot_due') {
                $orderTotal->value        = 0.00;
                $orderTotal->text         = $currency->symbol_left . number_format(
                        0.00,
                        $currency->decimal_places,
                        $currency->decimal_point,
                        $currency->thousands_point
                    );
                $orderTotal->text_inc_tax = $orderTotal->text;
                $orderTotal->text_exc_tax = $orderTotal->text;
            }
            $orderTotal->save();
        }

        $sql_data_array = array(
            'orders_id'         => $orderId,
            'orders_status_id'  => (MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID > 0 ?
                (int)MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID : (int)DEFAULT_ORDERS_STATUS_ID),
            'date_added'        => 'now()',
            'customer_notified' => '0',
            'comments'          => 'Payment Successful. Transaction ID: ' . $data['TRANSACTION_ID']
        );

        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
    }

    public function get_error()
    {
        global $HTTP_GET_VARS;

        return array(
            'title' => MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_ERROR,
            'error' => stripslashes(urldecode($HTTP_GET_VARS['error'])),
        );
    }

    /**
     * createUUID
     *
     * This function creates a pseudo-random UUID according to RFC 4122
     *
     * @see http://www.php.net/manual/en/function.uniqid.php#69164
     */
    public function createUUID()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    public function curlPost($url, $fields)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, count($fields));
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }

    public function configure_keys()
    {
        $status_id = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID : $this->getDefaultOrderStatusId();

        $status_id_paid = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PAID_STATUS_ID'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PAID_STATUS_ID : $this->getDefaultOrderStatusId();

        $status_id_fail = defined(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_FAIL_STATUS_ID'
        ) ? MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_FAIL_STATUS_ID : $this->getDefaultOrderStatusId();

        return array(
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_STATUS' => array(
                'title'        => 'Paygate Enable Module',
                'value'        => 'True',
                'description'  => 'Do you want to accept Paygate payments?',
                'sort_order'   => '1',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),

            'MODULE_PAYMENT_PAYGATE_PAYWEB3_PAYGATEID'     => array(
                'title'       => 'Paygate ID',
                'value'       => '',
                'description' => 'This is the Paygate ID you receive from Paygate',
                'sort_order'  => '2',
            ),
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ENCRYPTIONKEY' => array(
                'title'       => 'EncryptionKey Key',
                'value'       => '',
                'description' => 'This is the EncryptionKey Key set in the Paygate back office',
                'sort_order'  => '3',
            ),
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_DISABLE_IPN'   => array(
                'title'        => 'Disable IPN (notify)',
                'value'        => 'False',
                'description'  => 'Disable IPN (Notify). This is not recommended as IPN is more reliable than Redirect',
                'sort_order'   => '6',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_SORT_ORDER'    => array(
                'title'       => 'Sort order of display.',
                'value'       => '0',
                'description' => 'Sort order of display. Lowest is displayed first.',
                'sort_order'  => '5',
            ),
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_TEST_MODE'     => array(
                'title'        => 'Paygate Test mode',
                'value'        => 'True',
                'description'  => 'Sandbox mode',
                'sort_order'   => '6',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_DEBUG'         => array(
                'title'        => 'Paygate Debug mode',
                'value'        => 'False',
                'description'  => 'Sandbox debug mode',
                'sort_order'   => '16',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),

            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PROCESS_STATUS_ID' => array(
                'title'        => 'PAYGATE Set Order Processing Status',
                'value'        => $status_id,
                'description'  => 'Set the process status of orders made with this payment module to this value',
                'sort_order'   => '14',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ),
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_PAID_STATUS_ID'    => array(
                'title'        => 'PAYGATE Set Order Paid Status',
                'value'        => $status_id_paid,
                'description'  => 'Set the process status of orders made with this payment module to this value',
                'sort_order'   => '14',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ),
            'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_FAIL_STATUS_ID'    => array(
                'title'        => 'PAYGATE Set Order Fail Status',
                'value'        => $status_id_fail,
                'description'  => 'Set the process status of orders made with this payment module to this value',
                'sort_order'   => '14',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ),
        );
    }

    public function install($platform_id)
    {
        return parent::install($platform_id);
    }

    function isOnline()
    {
        return true;
    }

    public function describe_status_key()
    {
        return new ModuleStatus('MODULE_PAYMENT_PAYGATE_PAYWEB3_STATUS', 'True', 'False');
    }


    public function describe_sort_key()
    {
        return new ModuleSortOrder('MODULE_PAYMENT_PAYGATE_PAYWEB3_SORT_ORDER');
    }
}
