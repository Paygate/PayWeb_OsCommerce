<?php
/*
 * Copyright (c) 2018 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 * 
 * Released under the GNU General Public License
 */

require_once 'paygate.payweb3.php';

class paygate_payweb3 extends PayGate_PayWeb3_Class
{

    public $code, $title, $description, $enabled;

    // Class constructor
    public function paygate_payweb3()
    {
        global $order;

        $this->code        = 'PayGate_PayWeb3';
        $this->title       = MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_TITLE;
        $this->description = MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_DESCRIPTION;
        $this->enabled     = (  ( MODULE_PAYMENT_PAYGATE_PAYWEB3_STATUS == 'True' ) ? true : false );
        $this->sort_order  = MODULE_PAYMENT_PAYGATE_PAYWEB3_SORT_ORDER;

        if ( (int) MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_STATUS_ID > 0 ) {
            $this->order_status = MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_STATUS_ID;
        }

        if ( is_object( $order ) ) {
            $this->update_status();
        }

        $this->form_action_url = $this->process_url;
    }

    public function update_status()
    {
        global $order;

        if (  ( $this->enabled == true ) && ( (int) MODULE_PAYMENT_PAYGATE_PAYWEB3_ZONE > 0 ) ) {
            $check_flag  = false;
            $check_query = tep_db_query( "select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PAYGATE_PAYWEB3_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id" );
            while ( $check = tep_db_fetch_array( $check_query ) ) {
                if ( $check['zone_id'] < 1 ) {
                    $check_flag = true;
                    break;
                } elseif ( $check['zone_id'] == $order->billing['zone_id'] ) {
                    $check_flag = true;
                    break;
                }
            }

            if ( $check_flag == false ) {
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
        return array( 'id' => $this->code,
            'module'          => $this->title );
    }

    public function pre_confirmation_check()
    {
        return false;
    }

    public function confirmation()
    {
        return false;
    }

    public function process_button()
    {
        global $order;

        $pgPayGateID       = MODULE_PAYMENT_PAYGATE_PAYWEB3_PAYGATEID;
        $pgReference       = $this->createUUID();
        $pgAmount          = (string) ( (int) ( $order->info['total'] * 100 ) );
        $pgCurrency        = $order->info['currency'];
        $pgReturnURL       = tep_href_link( FILENAME_CHECKOUT_PROCESS, tep_session_name() . '=' . tep_session_id(), 'SSL' );
        $pgTransactionDate = gmstrftime( "%Y-%m-%d %H:%M" );
        $pgCustomerEmail   = $order->customer['email_address'];

        $data = array(
            'PAYGATE_ID'       => filter_var( $pgPayGateID, FILTER_SANITIZE_STRING ),
            'REFERENCE'        => filter_var( $pgReference, FILTER_SANITIZE_STRING ),
            'AMOUNT'           => filter_var( $pgAmount, FILTER_SANITIZE_NUMBER_INT ),
            'CURRENCY'         => filter_var( $pgCurrency, FILTER_SANITIZE_STRING ),
            'RETURN_URL'       => filter_var( $pgReturnURL, FILTER_SANITIZE_URL ),
            'TRANSACTION_DATE' => filter_var( $pgTransactionDate, FILTER_SANITIZE_STRING ),
            'LOCALE'           => filter_var( 'en-za', FILTER_SANITIZE_STRING ),
            'COUNTRY'          => filter_var( $order->customer['country']['iso_code_3'], FILTER_SANITIZE_STRING ),
            'EMAIL'            => filter_var( $pgCustomerEmail, FILTER_SANITIZE_EMAIL ),
        );
        $encryption_key = MODULE_PAYMENT_PAYGATE_PAYWEB3_ENCRYPTIONKEY;

        // Set the session vars once we have cleaned the inputs
        $_SESSION['pgid']      = $data['PAYGATE_ID'];
        $_SESSION['reference'] = $data['REFERENCE'];
        $_SESSION['key']       = $encryption_key;

        // Initiate the PayWeb 3 helper class
        $PayWeb3 = new PayGate_PayWeb3_Class();

        // If debug is set to true, the curl request and result as well as the calculated
        // checksum source will be logged to the php error log
        if ( MODULE_PAYMENT_PAYGATE_PAYWEB3_DEBUG ) {
            $PayWeb3->setDebug( true );
        }

        // Set the encryption key of your PayGate PayWeb3 configuration
        $PayWeb3->setEncryptionKey( $encryption_key );

        // Set the array of fields to be posted to PayGate
        $PayWeb3->setInitiateRequest( $data );

        // Do the curl post to PayGate
        $PayWeb3->doInitiate();
        $isValid = $PayWeb3->validateChecksum( $PayWeb3->initiateResponse );

        if ( $isValid ) {
            // If the checksums match loop through the returned fields and create the redirect from
            foreach ( $PayWeb3->processRequest as $key => $value ) {
                $hiddenVars .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
            }
        }
        return $hiddenVars;
    }

    public function before_process()
    {
        global $order;

        // Follow up transaction
        $data = array(
            'PAYGATE_ID'         => $_SESSION['pgid'],
            'PAY_REQUEST_ID'     => $_POST['PAY_REQUEST_ID'],
            'TRANSACTION_STATUS' => $_POST['TRANSACTION_STATUS'],
            'REFERENCE'          => $_SESSION['reference'],
            'CHECKSUM'           => $_POST['CHECKSUM'],
        );
        $_SESSION['PAY_REQUEST_ID'] = $_POST['PAY_REQUEST_ID'];

        // Initiate the PayWeb 3 helper class
        $PayWeb3 = new PayGate_PayWeb3_Class();

        // Set the encryption key of your PayGate PayWeb3 configuration
        $PayWeb3->setEncryptionKey( $_SESSION['key'] );

        // Check that the checksum returned matches the checksum we generate
        $isValid = $PayWeb3->validateChecksum( $data );

        if ( !$isValid ) {
            tep_redirect( tep_href_link( FILENAME_CHECKOUT_PAYMENT, 'error_message=An error occured while processing transaction', 'SSL', true, false ) );
        } else if ( $data['TRANSACTION_STATUS'] ) {
            if ( $data['TRANSACTION_STATUS'] == 1 ) {
                // Approved
                $GLOBALS['PAYGATE_TRANSACTION_STATUS']      = $_POST['TRANSACTION_STATUS'];
                $GLOBALS['PAYGATE_TRANSACTION_STATUS_DESC'] = 'Approved';
                $GLOBALS['PAYGATE_REFERENCE']               = $_SESSION['REFERENCE'];
                $GLOBALS['PAYGATE_AMOUNT']                  = (string) ( (int) ( $order->info['total'] * 100 ) );
            } else if ( $data['TRANSACTION_STATUS'] == 2 ) {
                // Declined
                tep_redirect( tep_href_link( FILENAME_CHECKOUT_PAYMENT, 'error_message=Transaction has been declined', 'SSL', true, false ) );
            } else if ( $data['TRANSACTION_STATUS'] == 4 ) {
                // User Cancelled
                tep_redirect( tep_href_link( FILENAME_CHECKOUT_PAYMENT, 'error_message=User cancelled transaction', 'SSL', true, false ) );
            } else {
                // Cancelled
                tep_redirect( tep_href_link( FILENAME_CHECKOUT_PAYMENT, 'error_message=Unknown error', 'SSL', true, false ) );
            }
        }
    }

    public function after_process()
    {
        global $insert_id;
        $subject = "PayGate processed oscommerce order, OrderID: " . $insert_id;
        $message = "Order has been " . $GLOBALS['PAYGATE_TRANSACTION_STATUS_DESC'] . "\n" .
        "\n" .
        "The order details are:\n" .
        "Order Number: " . $insert_id . "\n" .
        "PayGate Transaction Reference: " . $GLOBALS['PAYGATE_REFERENCE'] . "\n" .
        "Processed Amount: " . number_format( (int) $GLOBALS['PAYGATE_AMOUNT'] / 100, 2 ) . "\r\n";
        tep_mail( '', MODULE_PAYMENT_PAYGATE_PAYWEB3_AUTH_EMAIL, $subject, $message, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS );

        // Create array of data to query PAyGate with
        $data = array(
            'PAYGATE_ID'     => $_SESSION['pgid'],
            'PAY_REQUEST_ID' => $_SESSION['PAY_REQUEST_ID'],
            'REFERENCE'      => $_SESSION['reference'],
        );

        // Initiate the PayWeb 3 helper class
        $PayWeb3 = new PayGate_PayWeb3_Class();

        // Set the encryption key of your PayGate PayWeb3 configuration
        $PayWeb3->setEncryptionKey( $_SESSION['key'] );

        // Set the array of fields to be posted to PayGate
        $PayWeb3->setQueryRequest( $data );

        // Do the curl post to PayGate
        $PayWeb3->doQuery();

        if ( isset( $PayWeb3->queryResponse ) || isset( $PayWeb3->lastError ) ) {
            // We have received a response from PayWeb3
            if ( !isset( $PayWeb3->lastError ) ) {
                $payGateResponse = '';
                foreach ( $PayWeb3->queryResponse as $key => $value ) {
                    $payGateResponse .= $key . ": " . $value . "\n";
                }

                $sql_data_array = array( 'orders_id' => $insert_id,
                    'orders_status_id'                  => MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_STATUS_ID,
                    'date_added'                        => 'now()',
                    'customer_notified'                 => '0',
                    'comments'                          => $payGateResponse );

                tep_db_perform( TABLE_ORDERS_STATUS_HISTORY, $sql_data_array );
            }
        }

        return false;
    }

    public function get_error()
    {
        global $HTTP_GET_VARS;

        $error = array(
            'title' => MODULE_PAYMENT_PAYGATE_PAYWEB3_TEXT_ERROR,
            'error' => stripslashes( urldecode( $HTTP_GET_VARS['error'] ) ),
        );

        return $error;
    }

    public function check()
    {
        if ( !isset( $this->_check ) ) {
            $check_query  = tep_db_query( "select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYGATE_PAYWEB3_STATUS'" );
            $this->_check = tep_db_num_rows( $check_query );
        }
        return $this->_check;
    }

    public function install()
    {
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable PayGate PayWeb3 Module', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_STATUS', 'True', 'Do you want to accept PayGate payments?', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())" );
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('PayGate ID', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_PAYGATEID', '10011072130', 'Your PayGate ID', '6', '0', now())" );
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Email Address', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_AUTH_EMAIL', '', 'Email address to send a warning email when transaction is not authenticated', '6', '0', now())" );
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Encryption Key', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_ENCRYPTIONKEY', 'secret', 'Your Encryption Key; this must be identical to the Encryption Key on the BackOffice', '6', '0', now())" );
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'tep_get_zone_class_title', 'tep_cfg_pull_down_zone_classes(', now())" );
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_STATUS_ID', '0', 'Set the status of orders made with this payment module to this value', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())" );
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0' , now())" );
        tep_db_query( "insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Debug Mode', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_DEBUG', 'False', 'Allows greater detailed error messages for testing purposes.', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())" );
    }

    public function remove()
    {
        tep_db_query( "delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode( "', '", $this->keys() ) . "')" );
    }

    public function keys()
    {
        return array( 'MODULE_PAYMENT_PAYGATE_PAYWEB3_STATUS', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_PAYGATEID', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_ENCRYPTIONKEY', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_AUTH_EMAIL', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_ORDER_STATUS_ID', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_ZONE', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_SORT_ORDER', 'MODULE_PAYMENT_PAYGATE_PAYWEB3_DEBUG' );
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
        $uuid = sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );

        return ( $uuid );
    }

    public function curlPost( $url, $fields )
    {
        $curl = curl_init( $url );
        curl_setopt( $curl, CURLOPT_POST, count( $fields ) );
        curl_setopt( $curl, CURLOPT_POSTFIELDS, $fields );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
        $response = curl_exec( $curl );
        curl_close( $curl );
        return $response;
    }

}
