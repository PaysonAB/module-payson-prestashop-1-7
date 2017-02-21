<?php
global $cookie;
include_once(dirname(__FILE__) . '/../../config/config.inc.php');
include_once(dirname(__FILE__) . '/../../init.php');
include_once(dirname(__FILE__) . '/paysondirect.php');
include_once(_PS_MODULE_DIR_ . 'paysondirect/payson_api/def.payson.php');
include_once(_PS_MODULE_DIR_ . 'paysondirect/payson/paysonapi.php');

$payson = new Paysondirect();

$cart = new Cart(intval($cookie->id_cart));

$address = new Address(intval($cart->id_address_invoice));

$state = NULL;

$amount = 0;

if ($address->id_state){
    $state = new State(intval($address->id_state));
}
$customer = new Customer(intval($cart->id_customer));

$receiverEmail = Configuration::get('PAYSON_EMAIL');

$invoiceEnabled = Configuration::get('PAYSON_INVOICE_ENABLED') == 1;
$isInvoicePurchase = isset($_GET["method"]) && ($_GET["method"] == "invoice");

if ($isInvoicePurchase && !$invoiceEnabled){
    Logger::addLog('Cant pay with invoice when invoice isnt enabled', 1, NULL, NULL, NULL, true);
    Tools::redirect('index.php?controller=order&step=1');
}

if (!Validate::isEmail($receiverEmail)){
    Logger::addLog($payson->getL('Payson error: (invalid or undefined business account email)'), 1, NULL, NULL, NULL, true);
    Tools::redirect('index.php?controller=order&step=1');
}

if (!Validate::isLoadedObject($address)){
    Logger::addLog($payson->getL('Payson error: (invalid address)'), 1, NULL, NULL, NULL, true);
    Tools::redirect('index.php?controller=order&step=1');
}

if (!Validate::isLoadedObject($customer)){
    Logger::addLog($payson->getL('Payson error: (invalid customer)'), 1, NULL, NULL, NULL, true);
    Tools::redirect('index.php?controller=order&step=1');
}

// check currency of payment
$currency_order = new Currency(intval($cart->id_currency));
$currencies_module = $payson->getCurrency();

if (is_array($currencies_module)) {
    foreach ($currencies_module AS $some_currency_module) {
        if ($currency_order->iso_code == $some_currency_module['iso_code']) {
            $currency_module = $some_currency_module;
        }
    }
} else {
    $currency_module = $currencies_module;
}

if ($currency_order->id != $currency_module['id_currency']) {
    $cookie->id_currency = $currency_module['id_currency'];
    $cart->id_currency = $currency_module['id_currency'];
    $cart->update();
}

$useAllInOne = Configuration::get('PAYSON_INVOICE_ENABLED') == 1 && Configuration::get('PAYSON_ALL_IN_ONE_ENABLED') == 1 && Configuration::get('PAYSON_ORDER_BEFORE_REDIRECT') == 0 && Context::getContext()->country->iso_code == 'SE'? 1 : NULL;

if(!Configuration::get('PAYSON_ORDER_BEFORE_REDIRECT')){
    $amount = floatval($cart->getOrderTotal(true, 3));
}

if ($isInvoicePurchase){
    if(!Configuration::get('PAYSON_ORDER_BEFORE_REDIRECT')){
      $amount += $payson->paysonInvoiceFee();  
    }else{
       $invoicefee = Db::getInstance()->getRow("SELECT id_product FROM " . _DB_PREFIX_ . "product WHERE reference = 'PS_FA'");

       /* Check if the product is already in the cart and if there is a product with ref PS_FA*/
       if((int)$invoicefee['id_product'] > 0 && $cart->containsProduct((int)$invoicefee['id_product'])['quantity'] == 0){
           //Set the invoice fee to the cart.
           $cart->updateQty(1,(int)$invoicefee['id_product'],null,false,'up',0,new Shop((int)$cart->id_shop),false); 
       }
    }
}

elseif ($useAllInOne){
    $amount += $payson->paysonInvoiceFee();
}

if(Configuration::get('PAYSON_ORDER_BEFORE_REDIRECT')){
    $amount = floatval($cart->getOrderTotal(true, 3));
}

$url = Tools::getHttpHost(false, true) . __PS_BASE_URI__;

$trackingId = time();

if (Configuration::get('PS_SSL_ENABLED') || Configuration::get('PS_SSL_ENABLED_EVERYWHERE')) {
    $protocol = 'https://';
} else {
    $protocol = 'http://';
}

$paysonUrl = array(
    'returnUrl' => $protocol . $url . "modules/paysondirect/validation.php?trackingId=" . $trackingId . "&id_cart=" . $cart->id,
    'ipnNotificationUrl' => $protocol . $url . 'modules/paysondirect/ipn_payson.php?id_cart=' . $cart->id,
    'cancelUrl' => $protocol . $url . "index.php?controller=order"
);

$orderItems = orderItemsList($cart, $payson);

$shopInfo = array(
    'shopName' => Configuration::get('PS_SHOP_NAME'),
    'localeCode' => Language::getIsoById($cookie->id_lang),
    'currencyCode' => $currency_module['iso_code']
);

$api = $payson->getAPIInstance();

if ($payson->testMode) {
    $receiver = new Receiver('testagent-checkout2@payson.se', $amount);
    $sender = new Sender(Configuration::get('PAYSON_SANDBOX_CUSTOMER_EMAIL'), 'name', 'lastname');
} else {
    $receiver = new Receiver($receiverEmail, $amount);
    $sender = new Sender(trim($customer->email), trim($customer->firstname), trim($customer->lastname));
}
$receivers = array($receiver);

$payData = new PayData($paysonUrl['returnUrl'], $paysonUrl['cancelUrl'], $paysonUrl['ipnNotificationUrl'], $shopInfo['shopName'], $sender, $receivers);
$payData->setCurrencyCode($shopInfo['currencyCode']);
$payData->setLocaleCode($shopInfo['localeCode']);
$payData->setTrackingId($trackingId);

$constraints = $isInvoicePurchase ? $constraints = array(FundingConstraint::INVOICE) : Configuration::get('paysonpay');

$payData->setFundingConstraints($constraints, $useAllInOne);

$payData->setOrderItems($orderItems);
if ($isInvoicePurchase) {
    if(!Configuration::get('PAYSON_ORDER_BEFORE_REDIRECT')){
        $payData->setInvoiceFee($payson->paysonInvoiceFee());
    }
} elseif ($useAllInOne){
    $payData->setInvoiceFee($payson->paysonInvoiceFee());
}

$payData->setGuaranteeOffered('NO');

$payData->setShowReceiptPage(!Configuration::get('PAYSON_RECEIPT') ? FALSE : ($payson->discount_applies ? FALSE : TRUE ));
//$payData->setShowReceiptPage($payson->discount_applies ? FALSE : TRUE);
$payResponse = $api->pay($payData);

if ($payResponse->getResponseEnvelope()->wasSuccessful()) {  //ack = SUCCESS och token  = token = Nï¿½got
    
    if(Configuration::get('PAYSON_ORDER_BEFORE_REDIRECT')){
        $payson->validateOrder((int) $cart->id, Configuration::get('PS_OS_PREPARATION'), $amount, $payson->displayName, 'Payson order: CREATED', $mailVars, (int)$currency->id, false, $customer->secure_key);
        $paymentDetails = $api->paymentDetails(new PaymentDetailsData($payResponse->getToken()))->getPaymentDetails();
        $payson->createPaysonOrderEvents($paymentDetails, 0);
    }
    
    header("Location: " . $api->getForwardPayUrl($payResponse));
} else {
    $error = $payResponse->getResponseEnvelope()->getErrors();
    if (Configuration::get('PAYSON_LOGS') == 'yes') {
        $message = '<Payson Direct api>' . $error[0]->getErrorId() . '***' . $error[0]->getMessage() . '***' . $error[0]->getParameter();
        Logger::addLog($message, 1, NULL, NULL, NULL, true);
    }
    $payson->paysonApiError($error[0]->getMessage() . ' Please try using a different payment method.');
}

/*
 * @return void
 * @param array $paysonUrl, $productInfo, $shopInfo, $moduleVersionToTracking
 * @disc the function request and redirect Payson API Sandbox
 */

/*
 * @return product list
 * @param int $id_cart
 * @disc 
 */

function orderItemsList($cart, $payson) {

    include_once(_PS_MODULE_DIR_ . 'paysondirect/payson/orderitem.php');

    $orderitemslist = array();
    foreach ($cart->getProducts() AS $cartProduct) {
        if (isset($cartProduct['quantity_discount_applies']) && $cartProduct['quantity_discount_applies'] == 1){
            $payson->discount_applies = 1;
        }
        
        $my_taxrate = $cartProduct['rate'] / 100;
        $product_price = $cartProduct['price'];
        $attributes_small = isset($cartProduct['attributes_small']) ? $cartProduct['attributes_small'] : '';

        $orderitemslist[] = new OrderItem(
                $cartProduct['name'] . '  ' . $attributes_small, number_format($product_price, 2, '.', ''), $cartProduct['cart_quantity'], number_format($my_taxrate, 3, '.', ''), $cartProduct['id_product']
        );
    }

    // check discounts
    $cartDiscounts = $cart->getDiscounts();
    $carrier = new Carrier($cart->id_carrier, $cart->id_lang);
    
    $tax_rate_discount = 0;
    $taxDiscount = Cart::getTaxesAverageUsed((int) ($cart->id));
    if (isset($taxDiscount) AND $taxDiscount != 1){
        $tax_rate_discount = $taxDiscount * 0.01;
    }

    foreach ($cartDiscounts AS $cartDiscount) {
        if ((!$carrier->is_free && !$cartDiscount['reduction_tax']) || ($carrier->is_free && !$cartDiscount['reduction_tax']) || (!$carrier->is_free && $cartDiscount['reduction_tax'])){
            $value = (Tools::ps_round($cartDiscount['value_real'], Configuration::get('PS_PRICE_DISPLAY_PRECISION')) - (empty($cartDiscounts) ? 0 : $cartDiscounts[$i]['obj']->free_shipping ? Tools::ps_round($total_shipping_wt, Configuration::get('PS_PRICE_DISPLAY_PRECISION')) : 0)) / (1 + $tax_rate_discount);
        }else{
            $value = $cartDiscount['value_tax_exc']; //$objDiscount->getValue(sizeof($cartDiscounts), $cart->getOrderTotal(true, 1), $cart->getTotalShippingCost(), $cart->id);
        }

        $orderitemslist[] = new OrderItem($cartDiscount['name'], number_format(-$value, 2, '.', ''), 1, $tax_rate_discount, 'discoun');
    }

    $total_shipping_wt = _PS_VERSION_ >= 1.5 ? floatval($cart->getTotalShippingCost()) : floatval($cart->getOrderShippingCost());

    if ($total_shipping_wt > 0) {

        $carriertax = Tax::getCarrierTaxRate((int) $carrier->id, $cart->id_address_invoice);
        $carriertax_rate = $carriertax / 100;

        $forward_vat = 1 + $carriertax_rate;
        $total_shipping_wot = $total_shipping_wt / $forward_vat;

        $orderitemslist[] = new OrderItem(
                isset($carrier->name) ? $carrier->name : 'shipping', number_format($total_shipping_wot, 2, '.', ''), 1, number_format($carriertax_rate, 2, '.', ''), 9998
        );
    }
    
     if ($cart->gift) {
        $wrapping_price_temp = Tools::convertPrice((float) $cart->getOrderTotal(false, Cart::ONLY_WRAPPING), Currency::getCurrencyInstance((int) $cart->id_currency));
        $orderitemslist[] = new OrderItem(
                'gift wrapping', $wrapping_price_temp, 1, number_format((((($cart->getOrderTotal(true, Cart::ONLY_WRAPPING) * 100) / $cart->getOrderTotal(false, Cart::ONLY_WRAPPING)) - 100) / 100), 4, '.', ''), 9999
        );
    }


    return $orderitemslist;
}

//ready, -----------------------------------------------------------------------
?>