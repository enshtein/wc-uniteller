<?php
/**
 * Plugin Name: Uniteller для WooCommerce
 * Description: Платежный модуль для работы с сервисом Uniteller через плагин WooCommerce
 * Version: 1.0
 * Author: Leonid
 */

defined( 'ABSPATH' ) or exit;

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

function wc_uniteller_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Uniteller_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_uniteller_add_to_gateways' );


add_action( 'plugins_loaded', 'wc_uniteller_gateway_init', 11 );

function wc_uniteller_gateway_init() {

    class WC_Uniteller_Gateway extends WC_Payment_Gateway {

    	public function __construct() {
    		$this->id = 'uniteller_gateway';
    		$this->icon = apply_filters('woocommerce_robokassa_icon', plugin_dir_url(__FILE__).'uniteller.jpg');
    		$this->has_fields = false;
    		$this->method_title       = __( 'Uniteller', 'wc_uniteller' );
			$this->method_description = __( 'Uniteller', 'wc_uniteller' );
        	$this->init_form_fields();
        	$this->init_settings();
        	$this->title = $this->settings['title'];
        	$this->description = $this->settings['description'];

        	add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        	add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));

        	add_action('woocommerce_api_wc_uniteller', array($this, 'check_ipn_response'));
         
	/*		add_filter( 'woocommerce_get_checkout_payment_url', function ( $url ) { 
			    return $url . '?gotopay=1'; 
			}, 10, 1 );*/

			/*add_action('woocommerce_checkout_create_order', function ( $order, $data ) {
			    update_post_meta( $order_id, 'ready_to_pay', 0);
			}, 20, 2);*/


			add_action( 'woocommerce_order_status_pending', function ($order_id) {
				update_post_meta( $order_id, 'ready_to_pay', 1);
			});			

			/*add_action( 'woocommerce_resume_order', function ( $order_id ) { 
    			$order = new WC_Order($order_id);
				$order->update_status('processing');
			}, 10, 1 );*/
    	}

    	function check_ipn_response()
    	{
    		global $woocommerce;

    		$upload_dir = wp_upload_dir();
    		//file_put_contents("{$upload_dir['basedir']}/debug.log", var_export($_REQUEST, true));

    		if (!$_REQUEST['Order_ID']) wp_die( 'Номер заказа не получен!', 'Обработка платежа Uniteller',  array('response'=>500) );

    		$status = $_REQUEST['status'] ? $_REQUEST['status'] : $_REQUEST['Status'];
    		
    		if (!$status) wp_die( 'Статус заказа не получен!', 'Обработка платежа Uniteller',  array('response'=>501) );

    		if (!$_REQUEST['Signature']) wp_die( 'Цифровая подпись заказа не получена!', 'Обработка платежа Uniteller',  array('response'=>502) );
    		
    		if ($_REQUEST['Signature'] != strtoupper(md5($_REQUEST['Order_ID'] . $status . $this->settings['password']))) wp_die( 'Неверная цифровая подпись заказа!', 'Обработка платежа Uniteller',  array('response'=>503) );
    		
	    	$order = new WC_Order($_REQUEST['Order_ID']);
	    	if (!is_object($order)) wp_die( "Заказ {$_REQUEST['Order_ID']} не найден в базе данных сайта!", 'Обработка платежа Uniteller',  array('response'=>504) );

	    	switch ($status) {
	    		case 'authorized':
	    			# средства успешно заблокированы
	    			$order->update_status('on-hold', __('Ожидание подтверждения платежа...', 'wc_uniteller'));
	    			break;
	    		case 'paid':
	    			# оплачен
	    			$order->payment_complete();
	    			break;	
	    		case 'canceled':
	    			# отменён
	    			$order->update_status('cancelled', __('Платеж отменен!', 'wc_uniteller'));
	    			break;	
	    		case 'waiting':
	    			# ожидается оплата выставленного счёта
	    			$order->update_status('on-hold', __('Ожидание оплаты выставленного счёта...', 'wc_uniteller'));
	    			break; 
	    	}

	    	exit();
    	}

    	function init_form_fields() {
	        $this->form_fields = array(
	            'enabled' => array(
	                'title' => __('Включить/Выключить', 'wc_uniteller'),
	                'type' => 'checkbox',
	                'label' => $this->long_name,
	                'default' => 'no'
	            ),
	            'title' => array(
	                'title' => __('Заголовок', 'wc_uniteller'),
	                'type'=> 'text',
	                'description' => __('Название, которое пользователь видит во время оплаты','wc_uniteller'),
	                'default' => $this->method_title
	            ),
	            'description' => array(
	                'title' => __('Описание','wc_uniteller'),
	                'type' => 'textarea',
	                'description' => __('Описание, которое пользователь видит во время оплаты','wc_uniteller'),
	                'default' => $this->long_name,
	            ),
	            'Shop_IDP' => array(
	                'title' => __('Shop_IDP', 'wc_uniteller'),
	                'type'=> 'text',
	                'description' => __('Идентификатор точки продажи в системе Uniteller. В Личном кабинете этот параметр называется Uniteller Point ID и его значение доступно на странице «Точки продажи компании» (пункт меню «Точки продажи») в столбце Uniteller Point ID.','wc_uniteller'),
	            ),
	            'password' => array(
	                'title' => __('password', 'wc_uniteller'),
	                'type'=> 'password',
	                'description' => __('Пароль из раздела «Параметры Авторизации»
Личного кабинета системы Uniteller.','wc_uniteller'),
	            ),
	            'Currency' => array(
	                'title' => __('Currency', 'wc_uniteller'),
	                'type'=> 'select',
	                'options' => array('RUB'=>'RUB — российский рубль','USD'=>'USD - доллар США', 'EUR' => 'EUR — евро', 'UAH' => 'UAH — украинская гривна'),
	                'description' => __('Валюта платежа. Параметр обязателен для точек продажи,  работающих с валютой, отличной от российского рубля. Для оплат в российских рублях параметр необязательный.','wc_uniteller'),
	            ),
	            'url_page_processing' => array(
	                'title' => __('URL-адрес страницы обработки заказа', 'wc_uniteller'),
	                'type'=> 'text',
	                'description' => __('URL-адрес страницы с сообщнием об обработке заказа','wc_uniteller'),
	                'default' => $this->url_page_processing
	            ),
	        );
	    }

	    function sha256($value)
	    {
	    	return hash('sha256', $value);
	    }

	    function receipt_page($order_id) {

	    	global $woocommerce;
	    	$this->order = new WC_Order($order_id);

			// если заказ не помечен как готовый к оплате, то меняем статус на "Обработка" и перекидываем на страницу с уведомлением! 
			if (get_post_meta( $this->order->id, 'ready_to_pay', true) != 1) {
	    		$woocommerce->cart->empty_cart();
	    		$this->order->update_status('processing');
	    		wp_redirect( $this->settings['url_page_processing'] . '?order_id=' .  $order_id );
				return false;
	    	}

	    	$order = $this->order;

	    	//$_lines = apply_filters('filter_uniteller_order_lines', $order, -1, 2);
	    	$_lines = apply_filters('filter_uniteller_order_lines', $order, -1, 2);

	    	/*$items = $this->order->get_items();

	    	$_discount = __getOrderDiscount($order);

			if ($_discount) {
	    		if (count($items)) {
			    	foreach ( $items as $item ) {
			    		$_product = $item->get_product();
			    		$_price = $_product->get_price();
			    		$_price = $_price - ( $_price * ($_discount / 100) );
			    		$_lines[] = array(
			    			'name' => mb_substr($item['name'], 0 , 128, 'utf-8'),
			    			'price' => (float) $_price,
			    			'qty' => (int) $item['quantity'],
			    			'sum' => (float) $_price * $item['quantity'],
			    			'vat' => -1,
			    			'taxmode' => 2,
			    		);
					}
				}
	    	} else {
	    		if (count($items)) {
			    	foreach ( $items as $item ) {
			    		$_lines[] = array(
			    			'name' => mb_substr($item['name'], 0 , 128, 'utf-8'),
			    			'price' => (float) $this->order->get_item_total( $item ),
			    			'qty' => (int) $item['quantity'],
			    			'sum' => (float) $item['total'],
			    			'vat' => -1,
			    			'taxmode' => 2,
			    		);
					}
				}
			}*/

			

/*			if (count($items)) {
		    	foreach ( $items as $item ) {
		    		$_price = $this->order->get_item_total( $item ) ;
		    		$_price = $_price - ( $_price * ($_discount / 100) );

		    		$_lines[] = array(
		    			'name' => mb_substr($item['name'], 0 , 128, 'utf-8'),
		    			'price' => (int) $_price,
		    			'qty' => (int) $item['quantity'],
		    			'sum' => (int) $_price * $item['quantity'],
		    			'vat' => -1,
		    			'taxmode' => 2,
		    		);
				}
			}*/

	    	//echo $order->get_view_order_url();

	    	$_fields['Shop_IDP'] = $this->settings['Shop_IDP'];
	    	$_fields['Currency'] = $this->settings['Currency'];
	    	//$_fields['URL_RETURN'] = $order->get_checkout_payment_url();
	    	$_fields['URL_RETURN_NO'] = $order->get_checkout_payment_url();
	    	$_fields['URL_RETURN_OK'] = $order->get_checkout_order_received_url();

			$_fields['Lifetime'] = "";

	    	$_fields['Order_IDP'] = $order_id;

	    	if (version_compare($woocommerce->version, "3.0", ">=")) {
	            $_fields['FirstName'] = $this->order->get_billing_first_name();
	            $_fields['LastName'] = $this->order->get_billing_last_name();
	            $_fields['Phone'] = $this->order->get_billing_phone();
	            $_fields['Email'] = $this->order->get_billing_email();
	            $_fields['Subtotal_P'] = $order->get_total();
	        } else {
	            $_fields['FirstName'] = $this->order->billing_first_name;
	            $_fields['LastName'] = $this->order->billing_last_name;
	            $_fields['Email'] = $this->order->billing_phone;
	            $_fields['Email'] = $this->order->billing_email;
	            $_fields['Subtotal_P'] = number_format($order->order_total, 2, '.', '');
	        }

	        //$_fields['Subtotal_P'] = number_format($_fields['Subtotal_P'], 2, '.', '');

	        
	        //$_fields['Subtotal_P'] = (float) wc_trim_zeros($_fields['Subtotal_P']);

	        $_fields['Subtotal_P'] = (float) $order->order_total;

	        //$this->log($_fields['Subtotal_P']);

	        //$_fields['Subtotal_P'] = sprintf('%01.2f', $_fields['Subtotal_P']);

	        //$_fields['Signature'] = strtoupper(md5($_fields['Shop_IDP'] . $_fields['Order_IDP'] . $_fields['Subtotal_P'] . $_fields['password']));

		    $_fields['Signature'] = strtoupper( md5( md5($_fields['Shop_IDP']) . "&" . md5($_fields['Order_IDP']) . "&" . md5($_fields['Subtotal_P']) . "&" . md5('') . "&" . md5('') . "&" . md5($_fields['Lifetime']) . "&" . md5('') . "&" . md5('') . "&" . md5('') . "&" . md5('') . "&" . md5($this->settings['password']) ) );


	       	$_fields['Receipt'] = json_encode(array(
	       		'customer' => array(
	       			'phone'=> urlencode($_fields['Phone']),
	       			'email'=>$_fields['Email'],
	       		),
	       		'lines' => $_lines,
	       		'total' => (float) $order->order_total, // 'total' => $_fields['Subtotal_P'],
	       		//'total' => $_fields['Subtotal_P'],
	       		'taxmode' => 2,
	       		'payments' => array(array(
	       			'kind' => 1,
	       			'type' => 0,
	       			'amount' => (float) $order->order_total,
	       		)),
	       	), JSON_UNESCAPED_UNICODE);

	       	//$this->log($_fields);

	       	$_fields['Receipt'] = base64_encode($_fields['Receipt']);

	       	$_fields['ReceiptSignature'] = strtoupper( $this->sha256(
	       		$this->sha256($_fields['Shop_IDP']) . '&' . $this->sha256($_fields['Order_IDP']) . '&' .
	       		$this->sha256($_fields['Subtotal_P']) . '&' . $this->sha256($_fields['Receipt']) . '&' . 
	       		$this->sha256($this->settings['password']) ) ) ;

	       	//$this->log($_fields, true);


	       	/*ReceiptSignature = uppercase( sha256( 
			sha256(Shop_IDP) + '&' + sha256(Order_IDP) + '&' +
			sha256(Subtotal_P) + '&' + sha256(Receipt) + '&' +
			sha256(password) ) )*/


	       	//$this->log($_fields);

			//$_fields['ReceiptSignature'] =  upper( sha256(sha256(Shop_IDP) + '&' + sha256(Order_IDP) + '&' + sha256(Subtotal_P) + '&' + sha256(Receipt) + '&' + sha256(password) ) );

	    	//$html .= '<form method="post" action="https://wpay.uniteller.ru/pay/" id="form_' . $this->id.'">';
	    	//$html .= '<form method="post" action="https://fpay.uniteller.ru/v1/pay" id="form_' . $this->id.'">';
	    	$html .= '<form method="post" action="https://fpay.uniteller.ru/v2/pay" id="form_' . $this->id.'">';
	    	if (count($_fields)) {
	    		foreach ($_fields as $_name => $_value) {
	    			$html .= '<input name="' . esc_attr($_name) . '" type="hidden" value="'. $_value . '">';
	    		}
	    	}
			$html .= '<p>Спасибо за заказ. Сейчас Вы будете автоматически перенаправлены на страницу оплаты. Если этого не произойдет автоматически, нажмите на кнопку ниже:</p>';
	    	$html .= '<input type="submit" value="Перейти к оплате >">';
	    	$html .= '</form>';
	    	$html .= '<script type="text/javascript">jQuery(document).ready(function(){jQuery("#form_' . $this->id . '").submit();});</script>';
	    	echo $html;	    	
	    }

	    function process_payment($order_id)
	    {
	        $order = new WC_Order($order_id);
	        return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
	    }

	    function log($text, $append=false)
	    {
	    	if (is_array($text)) $text = var_export($text, true);
	    	$upload_dir = wp_upload_dir();
	    	
	    	if ($append)
    			file_put_contents("{$upload_dir['basedir']}/debug.log", $text, FILE_APPEND);	    	
    		else 
    			file_put_contents("{$upload_dir['basedir']}/debug.log", $text);	    	
	    }
    }
}

//function get_wc_uniteller_order_lines($order, $vat, $taxmode)
function get_wc_uniteller_order_lines($order, $vat)
{
	$items = $order->get_items();
	if (count($items)) {
    	foreach ( $items as $item ) {
    		$_lines[] = array(
    			'name' => mb_substr($item['name'], 0 , 128, 'utf-8'),
    			'price' => (float) $order->get_item_total( $item ),
    			'qty' => (int) $item['quantity'],
    			'sum' => (float) $item['total'],
    			'vat' => $vat,
    			'payattr' => 1,
 				'lineattr' =>1,
    			//'taxmode' => $taxmode,
    		);
		}
	}
	return (array) $_lines;
}