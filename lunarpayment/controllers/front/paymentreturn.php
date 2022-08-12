<?php
/**
 *
 * @author    DerikonDevelopment <ionut@derikon.com>
 * @copyright Copyright (c) permanent, DerikonDevelopment
 * @version   1.0.4
 * @link      http://www.derikon.com/
 *
 */

if (!class_exists('Lunar\\Client')) {
    require_once('modules/lunarpayment/api/Client.php');
}

class LunarpaymentPaymentReturnModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        $this->display_column_right = false;
        $this->display_column_left = false;
        $this->context = Context::getContext();
    }

    public function init()
    {
        parent::init();
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'lunarpayment') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('Lunar payment method is not available.', 'paymentreturn'));
        }


        if (Configuration::get('LUNAR_CHECKOUT_MODE') == 'delayed') {
            $this->fetch();
        } else {
            $this->capture();
        }
    }

    public function fetch()
    {
        $cart = $this->context->cart;
        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        Lunar\Client::setKey(Configuration::get('LUNAR_SECRET_KEY'));
        $cart_total = $cart->getOrderTotal(true, Cart::BOTH);
        $currency = new Currency((int)$cart->id_currency);
        $decimals = (int)$currency->decimals * _PS_PRICE_COMPUTE_PRECISION_;
        $currency_multiplier = $this->module->getLunarCurrencyMultiplier($currency->iso_code);
        $cart_amount = ceil(Tools::ps_round($cart_total, 2) * $currency_multiplier);
        $transactionid = Tools::getValue('transactionid');

        $transaction_failed = false;

        $fetch = Lunar\Transaction::fetch($transactionid);
        //print_r($fetch);

        if (is_array($fetch) && isset($fetch['error']) && $fetch['error'] == 1) {
            Logger::addLog($fetch['message']);
            $this->context->smarty->assign(array(
                'lunar_order_error' => 1,
                'lunar_error_message' => $fetch['message']
            ));
            return $this->setTemplate('payment_error.tpl');
        } else {
            if (!empty($fetch['transaction'])) {
                $transaction = $fetch['transaction'];
                if ((string)$transaction['amount'] != (string)$cart_amount || $transaction['currency'] != $currency->iso_code) {
                    Logger::addLog('Invalid transaction.');
                    $this->context->smarty->assign(array(
                        'lunar_order_error' => 1,
                        'lunar_error_message' => 'Invalid transaction.'
                    ));
                    return $this->setTemplate('payment_error.tpl');
                } else {
                    $message = 'Trx ID: '.$transactionid.'
                        Authorized Amount: '.($fetch['transaction']['amount'] / $currency_multiplier).'
                        Captured Amount: '.($fetch['transaction']['capturedAmount'] / $currency_multiplier).'
                        Order time: '.$fetch['transaction']['created'].'
                        Currency code: '.$fetch['transaction']['currency'];

                    $total = $fetch['transaction']['amount'] / $currency_multiplier;
                    $amount = $fetch['transaction']['amount'];

                    //$status_paid = Configuration::get('PS_OS_PAYMENT');
                    $status_authorized = Configuration::get( 'LUNAR_ORDER_STATUS_AUTHORIZED' );

                    if ($this->module->validateOrder((int)$cart->id, $status_authorized, $total, $this->module->displayName, $message, array(), null, false, $customer->secure_key)) {
                        $this->module->storeTransactionID($transactionid, $this->module->currentOrder, $total, $captured = 'NO');

                        $redirectLink = __PS_BASE_URI__.'index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key;
                        Tools::redirectLink($redirectLink);
                    } else {
                        //Lunar\Transaction::void($transactionid, ['amount' => $amount]); //Cancel Order
                        Lunar\Transaction::void($transactionid, array('amount' => $fetch['transaction']['amount'])); //Cancel Order

                        Logger::addLog('Invalid transaction.');
                        $this->context->smarty->assign(array(
                            'lunar_order_error' => 1,
                            'lunar_error_message' => 'Invalid transaction.'
                        ));
                        return $this->setTemplate('payment_error.tpl');
                    }
                }
            } else {
                if (!empty($fetch[0]['message'])) {
                    Logger::addLog($fetch[0]['message']);
                    $this->context->smarty->assign(array(
                        'lunar_order_error' => 1,
                        'lunar_error_message' => $fetch[0]['message']
                    ));
                    return $this->setTemplate('payment_error.tpl');
                } else {
                    Logger::addLog('Invalid transaction.');
                    $this->context->smarty->assign(array(
                        'lunar_order_error' => 1,
                        'lunar_error_message' => 'Invalid transaction.'
                    ));
                    return $this->setTemplate('payment_error.tpl');
                }
            }
        }
    }

    public function capture()
    {
        $cart = $this->context->cart;
        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        Lunar\Client::setKey(Configuration::get('LUNAR_SECRET_KEY'));
        $cart_total = $cart->getOrderTotal(true, Cart::BOTH);
        $currency = new Currency((int)$cart->id_currency);
        $decimals = (int)$currency->decimals * _PS_PRICE_COMPUTE_PRECISION_;
        $currency_multiplier = $this->module->getLunarCurrencyMultiplier($currency->iso_code);
        $cart_amount = ceil(Tools::ps_round($cart_total, $decimals) * $currency_multiplier);
        //$status_paid = (int)Configuration::get('LUNAR_ORDER_STATUS_CAPTURED');
        //$status_paid = Configuration::get('PS_OS_PAYMENT');
        $transactionid = Tools::getValue('transactionid');

        Lunar\Client::setKey(Configuration::get('LUNAR_SECRET_KEY'));
        $fetch = Lunar\Transaction::fetch($transactionid);

        if (is_array($fetch) && isset($fetch['error']) && $fetch['error'] == 1) {
            Logger::addLog($fetch['message']);
            $this->context->smarty->assign(array(
                'lunar_order_error' => 1,
                'lunar_error_message' => $fetch['message']
            ));
            return $this->setTemplate('payment_error.tpl');
        } else {
            if (!empty($fetch['transaction'])) {
                $transaction = $fetch['transaction'];
                if ((string)$transaction['amount'] != (string)$cart_amount || $transaction['currency'] != $currency->iso_code ) {
                    Logger::addLog('Invalid transaction.');
                    $this->context->smarty->assign(array(
                        'lunar_order_error' => 1,
                        'lunar_error_message' => 'Invalid transaction.'
                    ));
                    return $this->setTemplate('payment_error.tpl');
                } else {
                    $status_paid = (int)Configuration::get('LUNAR_ORDER_STATUS_CAPTURED');

                    $data = array(
                        'currency' => $currency->iso_code,
                        'amount' => $cart_amount,
                    );
                    $capture = Lunar\Transaction::capture($transactionid, $data);

                    if (is_array($capture) && !empty($capture['error']) && $capture['error'] == 1) {
                        Logger::addLog($capture['message']);
                        $this->context->smarty->assign(array(
                            'lunar_order_error' => 1,
                            'lunar_error_message' => $capture['message']
                        ));
                        return $this->setTemplate('payment_error.tpl');
                    } else if (!empty($capture['transaction'])) {

                        $total = $capture['transaction']['amount'] / $currency_multiplier;
                        $amount = $capture['transaction']['amount'];

                        $validOrder = $this->module->validateOrder((int)$cart->id, $status_paid, $total, $this->module->displayName, null, array(), null, false, $customer->secure_key);

                        $message = 'Trx ID: '.$transactionid.'
                            Authorized Amount: '.($capture['transaction']['amount'] / $currency_multiplier).'
                            Captured Amount: '.($capture['transaction']['capturedAmount'] / $currency_multiplier).'
                            Order time: '.$capture['transaction']['created'].'
                            Currency code: '.$capture['transaction']['currency'];

                        $msg = new Message();
                        $message = strip_tags($message, '<br>');
                        if (Validate::isCleanHtml($message)) {
                            $msg->message = $message;
                            $msg->id_cart = (int)$cart->id;
                            $msg->id_customer = (int)$cart->id_customer;
                            $msg->id_order = (int)$this->module->currentOrder;
                            $msg->private = 1;
                            $msg->add();
                        }

                        $this->module->storeTransactionID($transactionid, $this->module->currentOrder, $total, $captured = 'YES');
                        $redirectLink = __PS_BASE_URI__.'index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key;
                        Tools::redirectLink($redirectLink);
                    } else {
                        Lunar\Transaction::void($transactionid, array('amount' => $fetch['transaction']['amount'])); //Cancel Order
                        Logger::addLog('Invalid transaction.');
                        $this->context->smarty->assign(array(
                            'lunar_order_error' => 1,
                            'lunar_error_message' => 'Invalid transaction.'
                        ));
                        return $this->setTemplate('payment_error.tpl');
                    }
                }
            } else {
                if (!empty($fetch[0]['message'])) {
                    Logger::addLog($fetch[0]['message']);
                    $this->context->smarty->assign(array(
                        'lunar_order_error' => 1,
                        'lunar_error_message' => $fetch[0]['message']
                    ));
                    return $this->setTemplate('payment_error.tpl');
                } else {
                    Logger::addLog('Invalid transaction.');
                    $this->context->smarty->assign(array(
                        'lunar_order_error' => 1,
                        'lunar_error_message' => 'Invalid transaction.'
                    ));
                    return $this->setTemplate('payment_error.tpl');
                }
            }
        }
    }
}
