<?php
/**
 *
 * @author    DerikonDevelopment <ionut@derikon.com>
 * @copyright Copyright (c) permanent, DerikonDevelopment
 * @license   Addons PrestaShop license limitation
 * @link      http://www.derikon.com/
 *
 */

if ( ! defined( '_TB_VERSION_' ) ) {
	exit;
}

if ( ! class_exists( 'Lunar\\Client' ) ) {
	require_once( 'api/Client.php' );
}

require_once( 'helpers/CurrencyHelper.php' );


class LunarPayment extends PaymentModule {
	private $html = '';
	protected $statuses_array = array();

	public function __construct() {
		$this->name       = 'lunarpayment';
		$this->tab        = 'payments_gateways';
		$this->version    = '1.0.0';
		$this->author     = 'DerikonDevelopment';
		$this->bootstrap  = true;
		$this->module_key = '154ec60ab1da9fb7225997498bc431a6'; // now this is hash of version (prestashop artifact?)

		$this->currencies      = true;
		$this->currencies_mode = 'checkbox';

		parent::__construct();
		$this->displayName      = $this->l( 'Lunar' );
		$this->description      = $this->l( 'Receive payment via Lunar' );
		$this->confirmUninstall = $this->l( 'Are you sure about removing Lunar?' );
	}

	public function install() {
		$popup_title   = ( ! empty( Configuration::get( 'PS_SHOP_NAME' ) ) ) ? Configuration::get( 'PS_SHOP_NAME' ) : 'Payment';
		$language_code = $this->context->language->iso_code;

		Configuration::updateValue( 'LUNAR_LANGUAGE_CODE', $language_code );
		Configuration::updateValue( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE', 'Credit card' );
		Configuration::updateValue( 'LUNAR_PAYMENT_METHOD_LOGO', 'visa.svg' );
		Configuration::updateValue( $language_code . '_LUNAR_PAYMENT_METHOD_DESC', 'Secure payment with credit card via © Lunar' );
		Configuration::updateValue( $language_code . '_LUNAR_POPUP_TITLE', $popup_title );
		Configuration::updateValue( 'LUNAR_SHOW_POPUP_DESC', 'no' );
		Configuration::updateValue( $language_code . '_LUNAR_POPUP_DESC', '' );
		Configuration::updateValue( 'LUNAR_TRANSACTION_MODE', 'live' ); // defaults to live mode
		Configuration::updateValue( 'LUNAR_TEST_PUBLIC_KEY', '' );
		Configuration::updateValue( 'LUNAR_TEST_SECRET_KEY', '' );
		Configuration::updateValue( 'LUNAR_LIVE_PUBLIC_KEY', '' );
		Configuration::updateValue( 'LUNAR_LIVE_SECRET_KEY', '' );
		Configuration::updateValue( 'LUNAR_CHECKOUT_MODE', 'delayed' );
		Configuration::updateValue( 'LUNAR_ORDER_STATUS_AUTHORIZED',  1 ); // order status 1 = Payment Accepted
		Configuration::updateValue( 'LUNAR_ORDER_STATUS_CAPTURED',  3 ); // order status 3 = Shipped
		Configuration::updateValue( 'LUNAR_STATUS', 'enabled' );
		Configuration::updateValue( 'LUNAR_SECRET_KEY', '' );

		return ( parent::install()
		         && $this->registerHook( 'header' )
		         && $this->registerHook( 'payment' )
		         && $this->registerHook( 'paymentReturn' )
		         && $this->registerHook( 'DisplayAdminOrder' )
		         && $this->registerHook( 'BackOfficeHeader' )
		         && $this->installDb() );
	}

	public function installDb() {
		return (
			Db::getInstance()->execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'lunar_admin` (
                `id`				INT(11) NOT NULL AUTO_INCREMENT,
                `lunar_tid`		VARCHAR(255) NOT NULL,
                `order_id`			INT(11) NOT NULL,
                `payed_at`			DATETIME NOT NULL,
                `payed_amount`		DECIMAL(20,6) NOT NULL,
                `refunded_amount`	DECIMAL(20,6) NOT NULL,
                `captured`		    VARCHAR(255) NOT NULL,
                PRIMARY KEY			(`id`)
                ) ENGINE=InnoDB		DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;' )

			&& Db::getInstance()->execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'lunar_logos` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(255) NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `default_logo` INT(11) NOT NULL DEFAULT 1 COMMENT "1=Default",
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;' )

			&& Db::getInstance()->insert(
				'lunar_logos',
				array(
					array(
						'id'         => 1,
						'name'       => pSQL( 'VISA' ),
						'slug'       => pSQL( 'visa' ),
						'file_name'  => pSQL( 'visa.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
					array(
						'id'         => 2,
						'name'       => pSQL( 'VISA Electron' ),
						'slug'       => pSQL( 'visa-electron' ),
						'file_name'  => pSQL( 'visa-electron.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
					array(
						'id'         => 3,
						'name'       => pSQL( 'Mastercard' ),
						'slug'       => pSQL( 'mastercard' ),
						'file_name'  => pSQL( 'mastercard.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
					array(
						'id'         => 4,
						'name'       => pSQL( 'Mastercard Maestro' ),
						'slug'       => pSQL( 'mastercard-maestro' ),
						'file_name'  => pSQL( 'mastercard-maestro.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
				)
			)
		);
	}

	public function uninstall() {
		//$sql = 'SELECT * FROM `'._DB_PREFIX_.'lunar_logos`';
		$sql = new DbQuery();
		$sql->select( '*' );
		$sql->from( 'lunar_logos', 'PL' );
		$sql->where( 'PL.default_logo != 1' );
		$logos = Db::getInstance()->executes( $sql );

		foreach ( $logos as $logo ) {
			if ( file_exists( _PS_MODULE_DIR_ . $this->name . '/views/img/' . $logo['file_name'] ) ) {
				unlink( _PS_MODULE_DIR_ . $this->name . '/views/img/' . $logo['file_name'] );
			}
		}

		//Fetch all languages and delete Lunar configurations which has language iso_code as prefix
		$languages = Language::getLanguages( true, $this->context->shop->id );
		foreach ( $languages as $language ) {
			$language_code = $language['iso_code'];
			Configuration::deleteByName( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' );
			Configuration::deleteByName( $language_code . '_LUNAR_PAYMENT_METHOD_DESC' );
			Configuration::deleteByName( $language_code . '_LUNAR_POPUP_TITLE' );
			Configuration::deleteByName( $language_code . '_LUNAR_POPUP_DESC' );
		}

		return (
			parent::uninstall()
			&& Db::getInstance()->execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'lunar_admin`' )
			&& Db::getInstance()->execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'lunar_logos`' )
			&& Configuration::deleteByName( 'LUNAR_PAYMENT_METHOD_TITLE' )
			&& Configuration::deleteByName( 'LUNAR_PAYMENT_METHOD_LOGO' )
			&& Configuration::deleteByName( 'LUNAR_PAYMENT_METHOD_DESC' )
			&& Configuration::deleteByName( 'LUNAR_POPUP_TITLE' )
			&& Configuration::deleteByName( 'LUNAR_SHOW_POPUP_DESC' )
			&& Configuration::deleteByName( 'LUNAR_POPUP_DESC' )
			&& Configuration::deleteByName( 'LUNAR_TRANSACTION_MODE' )
			&& Configuration::deleteByName( 'LUNAR_TEST_PUBLIC_KEY' )
			&& Configuration::deleteByName( 'LUNAR_TEST_SECRET_KEY' )
			&& Configuration::deleteByName( 'LUNAR_LIVE_PUBLIC_KEY' )
			&& Configuration::deleteByName( 'LUNAR_LIVE_SECRET_KEY' )
			&& Configuration::deleteByName( 'LUNAR_CHECKOUT_MODE' )
			&& Configuration::deleteByName( 'LUNAR_ORDER_STATUS_AUTHORIZED' )
			&& Configuration::deleteByName( 'LUNAR_ORDER_STATUS_CAPTURED' )
			&& Configuration::deleteByName( 'LUNAR_STATUS' )
			&& Configuration::deleteByName( 'LUNAR_SECRET_KEY' )
		);
	}

	public function getContent() {
		$this->html = '';
		if ( Tools::isSubmit( 'submitLunar' ) ) {
			$language_code = Configuration::get( 'LUNAR_LANGUAGE_CODE' );
			$valid         = true;

			$LUNAR_PAYMENT_METHOD_TITLE = ! empty( Tools::getvalue( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) ) ? Tools::getvalue( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) : '';
			$LUNAR_PAYMENT_METHOD_DESC  = ! empty( Tools::getvalue( $language_code . '_LUNAR_PAYMENT_METHOD_DESC' ) ) ? Tools::getvalue( $language_code . '_LUNAR_PAYMENT_METHOD_DESC' ) : '';
			$LUNAR_POPUP_TITLE          = ( ! empty( Tools::getvalue( $language_code . '_LUNAR_POPUP_TITLE' ) ) ) ? Tools::getvalue( $language_code . '_LUNAR_POPUP_TITLE' ) : '';
			$_LUNAR_POPUP_DESC          = ( ! empty( Tools::getvalue( $language_code . '_LUNAR_POPUP_DESC' ) ) ) ? Tools::getvalue( $language_code . '_LUNAR_POPUP_DESC' ) : '';

			if ( empty( $LUNAR_PAYMENT_METHOD_TITLE ) ) {
				$this->context->controller->errors[ $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ] = $this->l( 'Payment method title required!' );
				$LUNAR_PAYMENT_METHOD_TITLE                                                          = ( ! empty( Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) ) ) ? Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) : '';
				$valid                                                                                 = false;
			}

			if ( count( Tools::getvalue( 'LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO' ) ) > 1 ) {
				$creditCardLogo = implode( ',', Tools::getvalue( 'LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO' ) );
			} else {
				$creditCardLogo = Tools::getvalue( 'LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO' );
			}


			if ( Tools::getvalue( 'LUNAR_TRANSACTION_MODE' ) == 'test' ) {
				if ( ! Tools::getvalue( 'LUNAR_TEST_PUBLIC_KEY' ) ) {
					$this->context->controller->errors['LUNAR_TEST_PUBLIC_KEY'] = $this->l( 'Test mode Public Key is required!' );
					$LUNAR_TEST_PUBLIC_KEY                                      = ( ! empty( Configuration::get( 'LUNAR_TEST_PUBLIC_KEY' ) ) ) ? Configuration::get( 'LUNAR_TEST_PUBLIC_KEY' ) : '';
					$valid                                                        = false;
				} else {
					$LUNAR_TEST_PUBLIC_KEY = ( ! empty( Tools::getvalue( 'LUNAR_TEST_PUBLIC_KEY' ) ) ) ? Tools::getvalue( 'LUNAR_TEST_PUBLIC_KEY' ) : '';
				}

				if ( ! Tools::getvalue( 'LUNAR_TEST_SECRET_KEY' ) ) {
					$this->context->controller->errors['LUNAR_TEST_SECRET_KEY'] = $this->l( 'Test mode App Key is required!' );
					$LUNAR_TEST_SECRET_KEY                                      = ( ! empty( Configuration::get( 'LUNAR_TEST_SECRET_KEY' ) ) ) ? Configuration::get( 'LUNAR_TEST_SECRET_KEY' ) : '';
					$valid                                                        = false;
				} else {
					$LUNAR_TEST_SECRET_KEY = ( ! empty( Tools::getvalue( 'LUNAR_TEST_SECRET_KEY' ) ) ) ? Tools::getvalue( 'LUNAR_TEST_SECRET_KEY' ) : '';
				}
			} else if ( Tools::getvalue( 'LUNAR_TRANSACTION_MODE' ) == 'live' ) {
				if ( ! Tools::getvalue( 'LUNAR_LIVE_PUBLIC_KEY' ) ) {
					$this->context->controller->errors['LUNAR_LIVE_PUBLIC_KEY'] = $this->l( 'Public Key is required!' );
					$LUNAR_LIVE_PUBLIC_KEY                                      = ( ! empty( Configuration::get( 'LUNAR_LIVE_PUBLIC_KEY' ) ) ) ? Configuration::get( 'LUNAR_LIVE_PUBLIC_KEY' ) : '';
					$valid                                                        = false;
				} else {
					$LUNAR_LIVE_PUBLIC_KEY = ( ! empty( Tools::getvalue( 'LUNAR_LIVE_PUBLIC_KEY' ) ) ) ? Tools::getvalue( 'LUNAR_LIVE_PUBLIC_KEY' ) : '';
				}

				if ( ! Tools::getvalue( 'LUNAR_LIVE_SECRET_KEY' ) ) {
					$this->context->controller->errors['LUNAR_LIVE_SECRET_KEY'] = $this->l( 'App Key is required!' );
					$LUNAR_LIVE_SECRET_KEY                                      = ( ! empty( Configuration::get( 'LUNAR_LIVE_SECRET_KEY' ) ) ) ? Configuration::get( 'LUNAR_LIVE_SECRET_KEY' ) : '';
					$valid                                                        = false;
				} else {
					$LUNAR_LIVE_SECRET_KEY = ( ! empty( Tools::getvalue( 'LUNAR_LIVE_SECRET_KEY' ) ) ) ? Tools::getvalue( 'LUNAR_LIVE_SECRET_KEY' ) : '';
				}
			}

			Configuration::updateValue( 'LUNAR_TRANSACTION_MODE', $language_code );
			Configuration::updateValue( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE', $LUNAR_PAYMENT_METHOD_TITLE );
			Configuration::updateValue( 'LUNAR_PAYMENT_METHOD_LOGO', $creditCardLogo );
			Configuration::updateValue( $language_code . '_LUNAR_PAYMENT_METHOD_DESC', $LUNAR_PAYMENT_METHOD_DESC );
			Configuration::updateValue( $language_code . '_LUNAR_POPUP_TITLE', $LUNAR_POPUP_TITLE );
			Configuration::updateValue( 'LUNAR_SHOW_POPUP_DESC', Tools::getvalue( 'LUNAR_SHOW_POPUP_DESC' ) );
			Configuration::updateValue( $language_code . '_LUNAR_POPUP_DESC', $_LUNAR_POPUP_DESC );
			Configuration::updateValue( 'LUNAR_TRANSACTION_MODE', Tools::getvalue( 'LUNAR_TRANSACTION_MODE' ) );
			if ( Tools::getvalue( 'LUNAR_TRANSACTION_MODE' ) == 'test' ) {
				Configuration::updateValue( 'LUNAR_TEST_PUBLIC_KEY', $LUNAR_TEST_PUBLIC_KEY );
				Configuration::updateValue( 'LUNAR_TEST_SECRET_KEY', $LUNAR_TEST_SECRET_KEY );
			} else if ( Tools::getvalue( 'LUNAR_TRANSACTION_MODE' ) == 'live' ) {
				Configuration::updateValue( 'LUNAR_LIVE_PUBLIC_KEY', $LUNAR_LIVE_PUBLIC_KEY );
				Configuration::updateValue( 'LUNAR_LIVE_SECRET_KEY', $LUNAR_LIVE_SECRET_KEY );
			}
			Configuration::updateValue( 'LUNAR_CHECKOUT_MODE', Tools::getValue( 'LUNAR_CHECKOUT_MODE' ) );
			Configuration::updateValue( 'LUNAR_ORDER_STATUS_AUTHORIZED', Tools::getValue( 'LUNAR_ORDER_STATUS_AUTHORIZED' ) );
			Configuration::updateValue( 'LUNAR_ORDER_STATUS_CAPTURED', Tools::getValue( 'LUNAR_ORDER_STATUS_CAPTURED' ) );
			Configuration::updateValue( 'LUNAR_STATUS', Tools::getValue( 'LUNAR_STATUS' ) );

			if ( $valid ) {
				$this->context->controller->confirmations[] = $this->l( 'Settings saved successfully' );
			}
		}

		//Get configuration form
		$this->html .= $this->renderCurrencyWarning();
		$this->html .= $this->renderForm();

		$this->html .= $this->getModalForAddMoreLogo();

		return $this->html;
	}

	public function renderCurrencyWarning() {
		$currencies         = Currency::getCurrencies();
		$warning_currencies = array();
		foreach ( $currencies as $currency ) {
			if ( CurrencyHelper::getCurrencyMultiplier( $currency['iso_code'] ) == 1 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 0 ) {
				$warning_currencies[0][] = $currency['iso_code'];
			} elseif ( CurrencyHelper::getCurrencyMultiplier( $currency['iso_code'] ) == 10 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 1 ) {
				$warning_currencies[1][] = $currency['iso_code'];
			} elseif ( CurrencyHelper::getCurrencyMultiplier( $currency['iso_code'] ) == 100 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 2 ) {
				$warning_currencies[2][] = $currency['iso_code'];
			} elseif ( CurrencyHelper::getCurrencyMultiplier( $currency['iso_code'] ) == 1000 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 3 ) {
				$warning_currencies[3][] = $currency['iso_code'];
			} elseif ( CurrencyHelper::getCurrencyMultiplier( $currency['iso_code'] ) == 10000 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 4 ) {
				$warning_currencies[4][] = $currency['iso_code'];
			}
		}
		if ( count( $warning_currencies ) ) {
			$this->context->smarty->assign(
				array(
					'warning_currencies_decimal' => $warning_currencies,
					'PS_PRICE_DISPLAY_PRECISION' => Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ),
					'preferences_url'            => $this->context->link->getAdminLink( 'AdminPreferences' )
				)
			);

			return $this->display( __FILE__, 'views/templates/admin/currency-warning.tpl' );
		} else {
			return '';
		}
	}

	public function renderForm() {
		$this->languages_array = array();
		$this->statuses_array  = array();
		$this->logos_array     = array();

		$language_code = Configuration::get( 'LUNAR_LANGUAGE_CODE' );

		//Fetch all active languages
		$languages = Language::getLanguages( true, $this->context->shop->id );
		foreach ( $languages as $language ) {
			$data = array(
				'id_option' => $language['iso_code'],
				'name'      => $language['name']
			);
			array_push( $this->languages_array, $data );
		}

		//Fetch Status list
		$valid_statuses = array( '1', '2', '3', '4', '5', '12' );
		$statuses       = OrderState::getOrderStates( (int) $this->context->language->id );
		foreach ( $statuses as $status ) {
			//$this->statuses_array[$status['id_order_state']] = $status['name'];
			if ( in_array( $status['id_order_state'], $valid_statuses ) ) {
				$data = array(
					'id_option' => $status['id_order_state'],
					'name'      => $status['name']
				);
				array_push( $this->statuses_array, $data );
			}
		}

		//$sql = 'SELECT * FROM `'._DB_PREFIX_.'lunar_logos`';
		$sql = new DbQuery();
		$sql->select( '*' );
		$sql->from( 'lunar_logos' );
		$logos = Db::getInstance()->executes( $sql );

		foreach ( $logos as $logo ) {
			$data = array(
				'id_option' => $logo['file_name'],
				'name'      => $logo['name']
			);
			array_push( $this->logos_array, $data );
		}

		//Set configuration form fields
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l( 'Lunar Payments Settings' ),
					'icon'  => 'icon-cogs'
				),
				'input'  => array(
					/*array(
                        'type' => 'select',
                        'label' => '<span data-toggle="tooltip" title="'.$this->l('Language').'">'.$this->l('Language').'<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
                        'name' => 'LUNAR_LANGUAGE_CODE',
                        'class' => 'lunar-config lunar-language',
                        'options' => array(
                            'query' => $this->languages_array,
                            'id' => 'id_option',
                            'name' => 'name'
                        ),
                    ),*/
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Payment method title' ) . '">' . $this->l( 'Payment method title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $language_code . '_LUNAR_PAYMENT_METHOD_TITLE',
						'class'    => 'lunar-config',
						'required' => true
					),
					array(
						'type'     => 'select',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Choose logo\s you want to have right next to the payment method on checkout page.' ) . '">' . $this->l( 'Payment method credit card logo\'s' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => 'LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO[]',
						'class'    => 'lunar-config creditcard-logo',
						'multiple' => true,
						'options'  => array(
							'query' => $this->logos_array,
							'id'    => 'id_option',
							'name'  => 'name'
						),
					),
					array(
						'type'  => 'textarea',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'Payment method description' ) . '">' . $this->l( 'Payment method description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_LUNAR_PAYMENT_METHOD_DESC',
						'class' => 'lunar-config',
						//'required' => true
					),
					array(
						'type'  => 'text',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'The text shown in the popup where the customer inserts the card details' ) . '">' . $this->l( 'Payment popup title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_LUNAR_POPUP_TITLE',
						'class' => 'lunar-config',
						//'required' => true
					),
					array(
						'type'    => 'select',
						'lang'    => true,
						'label'   => '<span data-toggle="tooltip" title="' . $this->l( 'If this is set to no the product list will be shown' ) . '">' . $this->l( 'Show payment popup description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'    => 'LUNAR_SHOW_POPUP_DESC',
						'class'   => 'lunar-config',
						'options' => array(
							'query' => array(
								array(
									'id_option' => 'yes',
									'name'      => 'Yes'
								),
								array(
									'id_option' => 'no',
									'name'      => 'No'
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'  => 'text',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'Text description that shows up on the payment popup.' ) . '">' . $this->l( 'Popup description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_LUNAR_POPUP_DESC',
						'class' => 'lunar-config'
					),
					array(
						'type'     => 'select',
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'In test mode, you can create a successful transaction with the card number 4100 0000 0000 0000 with any CVC and a valid expiration date.' ) . '">' . $this->l( 'Transaction mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => 'LUNAR_TRANSACTION_MODE',
						'class'    => 'lunar-config',
						'options'  => array(
							'query' => array(
								array(
									'id_option' => 'live',
									'name'      => 'Live'
								),
								array(
									'id_option' => 'test',
									'name'      => 'Test'
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						),
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Test mode Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => 'LUNAR_TEST_PUBLIC_KEY',
						'class'    => 'lunar-config',
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Test mode App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => 'LUNAR_TEST_SECRET_KEY',
						'class'    => 'lunar-config',
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => 'LUNAR_LIVE_PUBLIC_KEY',
						'class'    => 'lunar-config',
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => 'LUNAR_LIVE_SECRET_KEY',
						'class'    => 'lunar-config',
						'required' => true
					),
					array(
						'type'     => 'select',
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'If you deliver your product instantly (e.g. a digital product), choose Instant mode. If not, use Delayed' ) . '">' . $this->l( 'Capture mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => 'LUNAR_CHECKOUT_MODE',
						'class'    => 'lunar-config',
						'options'  => array(
							'query' => array(
								array(
									'id_option' => 'delayed',
									'name'      => $this->l( 'Delayed' )
								),
								array(
									'id_option' => 'instant',
									'name'      => $this->l( 'Instant' )
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						),
						'required' => true,
						// 'desc' => $this->l('Instant capture: Amount is captured as soon as the order is confirmed by customer.').'<br>'.$this->l('Delayed capture: Amount is captured after order status is changed to shipped.')
					),
					array(
						'type'    => 'select',
						'lang'    => true,
						'label'   => '<span data-toggle="tooltip" title="' . $this->l( 'The status on which the order will be set once it gets the payment authorized' ) . '">' . $this->l( 'Order status after authorization' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'    => 'LUNAR_ORDER_STATUS_AUTHORIZED',
						'class'   => 'lunar-config',
						'options' => array(
							'query' => $this->statuses_array,
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'    => 'select',
						'lang'    => true,
						//'label' => '<span data-toggle="tooltip" title="'.$this->l('The transaction will be captured once the order has the chosen status').'">'.$this->l('Capture on order status (delayed mode)').'<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'label'   => '<span data-toggle="tooltip" title="' . $this->l( 'The status on which the order will be set once it gets the payment captured' ) . '">' . $this->l( 'Order status after capture' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'    => 'LUNAR_ORDER_STATUS_CAPTURED',
						'class'   => 'lunar-config',
						'options' => array(
							'query' => $this->statuses_array,
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'    => 'select',
						'lang'    => true,
						'name'    => 'LUNAR_STATUS',
						'label'   => $this->l( 'Status' ),
						'class'   => 'lunar-config',
						'options' => array(
							'query' => array(
								array(
									'id_option' => 'enabled',
									'name'      => 'Enabled'
								),
								array(
									'id_option' => 'disabled',
									'name'      => 'Disabled'
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
				),
				'submit' => array(
					'title' => $this->l( 'Save' ),
				)
			),
		);

		$helper                           = new HelperForm();
		$helper->show_toolbar             = false;
		$helper->table                    = $this->table;
		$lang                             = new Language( (int) Configuration::get( 'PS_LANG_DEFAULT' ) );
		$helper->default_form_language    = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) ? Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) : 0;
		$this->fields_form                = array();

		$helper->identifier    = $this->identifier;
		$helper->submit_action = 'submitLunar';
		$helper->currentIndex  = $this->context->link->getAdminLink( 'AdminModules', false ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
		$helper->token         = Tools::getAdminTokenLite( 'AdminModules' );
		$helper->tpl_vars      = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id
		);

		$errors = $this->context->controller->errors;
		foreach ( $fields_form['form']['input'] as $key => $field ) {
			if ( array_key_exists( $field['name'], $errors ) ) {
				$fields_form['form']['input'][ $key ]['class'] = ! empty( $fields_form['form']['input'][ $key ]['class'] ) ? $fields_form['form']['input'][ $key ]['class'] . ' has-error' : 'has-error';
			}
		}

		return $helper->generateForm( array( $fields_form ) );
	}

	public function getConfigFieldsValues() {
		$language_code = Configuration::get( 'LUNAR_LANGUAGE_CODE' );

		$creditCardLogo = explode( ',', Configuration::get( 'LUNAR_PAYMENT_METHOD_LOGO' ) );

		$payment_method_title = ( ! empty( Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) ) ) ? Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) : ( ! empty( Configuration::get( 'en_LUNAR_PAYMENT_METHOD_TITLE' ) ) ? Configuration::get( 'en_LUNAR_PAYMENT_METHOD_TITLE' ) : '' );
		$payment_method_desc  = ( ! empty( Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_DESC' ) ) ) ? Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_DESC' ) : ( ! empty( Configuration::get( 'en_LUNAR_PAYMENT_METHOD_DESC' ) ) ? Configuration::get( 'en_LUNAR_PAYMENT_METHOD_DESC' ) : '' );
		$popup_title          = ( ! empty( Configuration::get( $language_code . '_LUNAR_POPUP_TITLE' ) ) ) ? Configuration::get( $language_code . '_LUNAR_POPUP_TITLE' ) : ( ! empty( Configuration::get( 'en_LUNAR_POPUP_TITLE' ) ) ? Configuration::get( 'en_LUNAR_POPUP_TITLE' ) : '' );
		$popup_description    = ( ! empty( Configuration::get( $language_code . '_LUNAR_POPUP_DESC' ) ) ) ? Configuration::get( $language_code . '_LUNAR_POPUP_DESC' ) : ( ! empty( Configuration::get( 'en_LUNAR_POPUP_DESC' ) ) ? Configuration::get( 'en_LUNAR_POPUP_DESC' ) : '' );

		if ( empty( $payment_method_title ) ) {
			$this->context->controller->errors[ $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ] = $this->l( 'Payment method title is required!' );
		}

		if ( Configuration::get( 'LUNAR_TRANSACTION_MODE' ) == 'test' ) {
			if ( ! Configuration::get( 'LUNAR_TEST_PUBLIC_KEY' ) ) {
				$this->context->controller->errors['LUNAR_TEST_PUBLIC_KEY'] = $this->l( 'Test mode Public Key is required!' );
			}
			if ( ! Configuration::get( 'LUNAR_TEST_SECRET_KEY' ) ) {
				$this->context->controller->errors['LUNAR_TEST_SECRET_KEY'] = $this->l( 'Test mode App Key is required!' );
			}
		} else if ( Configuration::get( 'LUNAR_TRANSACTION_MODE' ) == 'live' ) {
			if ( ! Configuration::get( 'LUNAR_LIVE_PUBLIC_KEY' ) ) {
				$this->context->controller->errors['LUNAR_LIVE_PUBLIC_KEY'] = $this->l( 'Public Key is required!' );
			}
			if ( ! Configuration::get( 'LUNAR_LIVE_SECRET_KEY' ) ) {
				$this->context->controller->errors['LUNAR_LIVE_SECRET_KEY'] = $this->l( 'App Key is required!' );
			}
		}
		//print_r($this->context->controller->errors);
		//die(Configuration::get('LUNAR_TRANSACTION_MODE'));

		return array(
			'LUNAR_LANGUAGE_CODE'                          => Configuration::get( 'LUNAR_LANGUAGE_CODE' ),
			$language_code . '_LUNAR_PAYMENT_METHOD_TITLE' => $payment_method_title,
			'LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO[]'       => $creditCardLogo,
			$language_code . '_LUNAR_PAYMENT_METHOD_DESC'  => $payment_method_desc,
			$language_code . '_LUNAR_POPUP_TITLE'          => $popup_title,
			'LUNAR_SHOW_POPUP_DESC'                        => Configuration::get( 'LUNAR_SHOW_POPUP_DESC' ),
			$language_code . '_LUNAR_POPUP_DESC'           => $popup_description,
			'LUNAR_TRANSACTION_MODE'                       => Configuration::get( 'LUNAR_TRANSACTION_MODE' ),
			'LUNAR_TEST_PUBLIC_KEY'                        => Configuration::get( 'LUNAR_TEST_PUBLIC_KEY' ),
			'LUNAR_TEST_SECRET_KEY'                        => Configuration::get( 'LUNAR_TEST_SECRET_KEY' ),
			'LUNAR_LIVE_PUBLIC_KEY'                        => Configuration::get( 'LUNAR_LIVE_PUBLIC_KEY' ),
			'LUNAR_LIVE_SECRET_KEY'                        => Configuration::get( 'LUNAR_LIVE_SECRET_KEY' ),
			'LUNAR_CHECKOUT_MODE'                          => Configuration::get( 'LUNAR_CHECKOUT_MODE' ),
			'LUNAR_ORDER_STATUS_AUTHORIZED'                => Configuration::get( 'LUNAR_ORDER_STATUS_AUTHORIZED' ),
			'LUNAR_ORDER_STATUS_CAPTURED'                  => Configuration::get( 'LUNAR_ORDER_STATUS_CAPTURED' ),
			'LUNAR_STATUS'                                 => Configuration::get( 'LUNAR_STATUS' ),
		);
	}

	public function getModalForAddMoreLogo() {
		$this->context->smarty->assign( array(
			'request_uri' => $this->context->link->getAdminLink( 'AdminOrders', false )
		) );

		return $this->display( __FILE__, 'views/templates/admin/modal.tpl' );
	}

	public function hookHeader() {
		/*if(Configuration::get('LUNAR_STATUS') == 'enabled' && $this->context->controller->php_self == 'order') {
            $this->context->controller->addJs('https://sdk.paylike.io/6.js');
        }*/
	}

	public function hookPayment( $params ) {
		$language_code = Configuration::get( 'LUNAR_LANGUAGE_CODE' );

		//ensure lunar key is set
		if ( Configuration::get( 'LUNAR_TRANSACTION_MODE' ) == 'test' ) {
			if ( ! Configuration::get( 'LUNAR_TEST_PUBLIC_KEY' ) || ! Configuration::get( 'LUNAR_TEST_SECRET_KEY' ) ) {
				return false;
			} else {
				$LUNAR_PUBLIC_KEY = Configuration::get( 'LUNAR_TEST_PUBLIC_KEY' );
				Configuration::updateValue( 'LUNAR_SECRET_KEY', Configuration::get( 'LUNAR_TEST_SECRET_KEY' ) );
			}
		}

		if ( Configuration::get( 'LUNAR_TRANSACTION_MODE' ) == 'live' ) {
			if ( ! Configuration::get( 'LUNAR_LIVE_PUBLIC_KEY' ) || ! Configuration::get( 'LUNAR_LIVE_SECRET_KEY' ) ) {
				return false;
			} else {
				$LUNAR_PUBLIC_KEY = Configuration::get( 'LUNAR_LIVE_PUBLIC_KEY' );
				Configuration::updateValue( 'LUNAR_SECRET_KEY', Configuration::get( 'LUNAR_LIVE_SECRET_KEY' ) );
			}
		}

		if ( ! Configuration::get( 'LUNAR_TEST_PUBLIC_KEY' ) && ! Configuration::get( 'LUNAR_TEST_SECRET_KEY' ) && ! Configuration::get( 'LUNAR_LIVE_PUBLIC_KEY' ) && ! Configuration::get( 'LUNAR_LIVE_SECRET_KEY' ) ) {
			return false;
		}

		$products       = $params['cart']->getProducts();
		$products_array = array();
		$products_label = array();
		$p              = 0;
		foreach ( $products as $product ) {
			$products_array[]     = array(
				$this->l( 'ID' )       => $product['id_product'],
				$this->l( 'Name' )     => $product['name'],
				$this->l( 'Quantity' ) => $product['cart_quantity']
			);
			$products_label[ $p ] = $product['quantity'] . 'x ' . $product['name'];
			$p ++;
		}

		$payment_method_title = ( ! empty( Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) ) ) ? Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_TITLE' ) : ( ! empty( Configuration::get( 'en_LUNAR_PAYMENT_METHOD_TITLE' ) ) ? Configuration::get( 'en_LUNAR_PAYMENT_METHOD_TITLE' ) : '' );
		$payment_method_desc  = ( ! empty( Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_DESC' ) ) ) ? Configuration::get( $language_code . '_LUNAR_PAYMENT_METHOD_DESC' ) : ( ! empty( Configuration::get( 'en_LUNAR_PAYMENT_METHOD_DESC' ) ) ? Configuration::get( 'en_LUNAR_PAYMENT_METHOD_DESC' ) : '' );
		$popup_title          = ( ! empty( Configuration::get( $language_code . '_LUNAR_POPUP_TITLE' ) ) ) ? Configuration::get( $language_code . '_LUNAR_POPUP_TITLE' ) : ( ! empty( Configuration::get( 'en_LUNAR_POPUP_TITLE' ) ) ? Configuration::get( 'en_LUNAR_POPUP_TITLE' ) : '' );

		if ( Configuration::get( 'LUNAR_SHOW_POPUP_DESC' ) == 'yes' ) {
			$popup_description = ( ! empty( Configuration::get( $language_code . '_LUNAR_POPUP_DESC' ) ) ) ? Configuration::get( $language_code . '_LUNAR_POPUP_DESC' ) : ( ! empty( Configuration::get( 'en_LUNAR_POPUP_DESC' ) ) ? Configuration::get( 'en_LUNAR_POPUP_DESC' ) : '' );
		} else {
			$popup_description = implode( ", & ", $products_label );
		}

		$cart  = $this->context->cart;
		$total = $cart->getOrderTotal( true, Cart::BOTH );
		// echo "Total : ".$total;
		//die();
		if ( CurrencyHelper::getCurrencyMultiplier( $this->context->currency->iso_code ) == 1 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 0 ) {
			return false;
		} elseif ( CurrencyHelper::getCurrencyMultiplier( $this->context->currency->iso_code ) == 10 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 1 ) {
			return false;
		} elseif ( CurrencyHelper::getCurrencyMultiplier( $this->context->currency->iso_code ) == 100 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 2 ) {
			return false;
		} elseif ( CurrencyHelper::getCurrencyMultiplier( $this->context->currency->iso_code ) == 1000 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 3 ) {
			return false;
		} elseif ( CurrencyHelper::getCurrencyMultiplier( $this->context->currency->iso_code ) == 10000 && Configuration::get( 'PS_PRICE_DISPLAY_PRECISION' ) != 4 ) {
			return false;
		}
		$currency            = new Currency( (int) $params['cart']->id_currency );
		$decimals            = (int) $currency->decimals * _PS_PRICE_COMPUTE_PRECISION_;
		$currency_multiplier = CurrencyHelper::getCurrencyMultiplier( $currency->iso_code );
		$amount              = ceil( Tools::ps_round( $params['cart']->getOrderTotal(), $decimals ) * $currency_multiplier ); //paid amounts with 100 to handle
		$currency_code       = $currency->iso_code;
		$exponent            = CurrencyHelper::getCurrency($currency->iso_code)['exponent'];
		$customer            = new Customer( (int) $params['cart']->id_customer );
		$name                = $customer->firstname . ' ' . $customer->lastname;
		$email               = $customer->email;
		$customer_address    = new Address( (int) ( $params['cart']->id_address_delivery ) );
		$telephone           = (! empty( $customer_address->phone ) ? $customer_address->phone : ! empty( $customer_address->phone_mobile )) ? $customer_address->phone_mobile : '';
		$address             = $customer_address->address1 . ', ' . $customer_address->address2 . ', ' . $customer_address->city . ', ' . $customer_address->country . ' - ' . $customer_address->postcode;
		$ip                  = Tools::getRemoteAddr();
		$locale              = $this->context->language->iso_code;
		$platform_version    = _TB_VERSION_;
		$ecommerce           = 'thirtybees';
		$module_version      = $this->version;

		$redirect_url = $this->context->link->getModuleLink( 'lunarpayment', 'paymentreturn', array(), true, (int) $this->context->language->id );

		if ( Configuration::get( 'PS_REWRITING_SETTINGS' ) == 1 ) {
			$redirect_url = Tools::strReplaceFirst( '&', '?', $redirect_url );
		}

		$this->context->smarty->assign( array(
			'LUNAR_PUBLIC_KEY'             => $LUNAR_PUBLIC_KEY,
			'PS_SSL_ENABLED'                 => ( Configuration::get( 'PS_SSL_ENABLED' ) ? 'https' : 'http' ),
			'http_host'                      => Tools::getHttpHost(),
			'shop_name'                      => $this->context->shop->name,
			'payment_method_title'           => $payment_method_title,
			'payment_method_creditcard_logo' => explode( ',', Configuration::get( 'LUNAR_PAYMENT_METHOD_LOGO' ) ),
			'payment_method_desc'            => $payment_method_desc,
			'lunar_status'                 => Configuration::get( 'LUNAR_STATUS' ),
			'test_mode'                 	 => Configuration::get( 'LUNAR_TRANSACTION_MODE' ),
			'popup_title'                    => $popup_title,
			'popup_description'              => $popup_description,
			'currency_code'                  => $currency_code,
			'amount'                         => $amount,
			'exponent'                       => $exponent,
			'id_cart'                        => json_encode( $params['cart']->id ),
			'products'                       => json_encode( $products_array ),
			'name'                           => $name,
			'email'                          => $email,
			'telephone'                      => $telephone,
			'address'                        => $address,
			'ip'                             => $ip,
			'locale'                         => $locale,
			'platform_version'               => $platform_version,
			'ecommerce'                      => $ecommerce,
			'module_version'                 => $module_version,
			'redirect_url'                   => $redirect_url,
			'qry_str'                        => ( Configuration::get( 'PS_REWRITING_SETTINGS' ) ? '?' : '&' ),
			'base_uri'                       => __PS_BASE_URI__,
			'this_path_lunar'              => $this->_path,
		) );

		return $this->display( __FILE__, 'views/templates/hook/payment.tpl' );
	}

	public function hookpaymentReturn( $params ) {
		if ( ! $this->active || ! isset( $params['objOrder'] ) || $params['objOrder']->module != $this->name ) {
			return false;
		}

		if ( isset( $params['objOrder'] ) && Validate::isLoadedObject( $params['objOrder'] ) && isset( $params['objOrder']->valid ) && isset( $params['objOrder']->reference ) ) {
			$this->smarty->assign(
				'lunar_order',
				array(
					'id'        => $params['objOrder']->id,
					'reference' => $params['objOrder']->reference,
					'valid'     => $params['objOrder']->valid
				)
			);

			return $this->display( __FILE__, 'views/templates/hook/payment-return.tpl' );
		}
	}

	public function storeTransactionID( $lunar_id_transaction, $order_id, $total, $captured = 'NO' ) {
		$query = 'INSERT INTO ' . _DB_PREFIX_ . 'lunar_admin (`lunar_tid`, `order_id`, `payed_amount`, `payed_at`, `captured`) VALUES ("' . pSQL( $lunar_id_transaction ) . '", "' . pSQL( $order_id ) . '", "' . pSQL( $total ) . '" , NOW(), "' . pSQL( $captured ) . '")';

		return Db::getInstance()->execute( $query );
	}

	public function updateTransactionID( $lunar_id_transaction, $order_id, $fields = array() ) {
		if ( $lunar_id_transaction && $order_id && ! empty( $fields ) ) {
			$fieldsStr  = '';
			$fieldCount = count( $fields );
			$counter    = 0;

			foreach ( $fields as $field => $value ) {
				$counter ++;
				$fieldsStr .= '`' . pSQL( $field ) . '` = "' . pSQL( $value ) . '"';

				if ( $counter < $fieldCount ) {
					$fieldsStr .= ', ';
				}
			}
			$query = 'UPDATE ' . _DB_PREFIX_ . 'lunar_admin SET ' . ( $fieldsStr ) . ' WHERE `lunar_tid`="' . pSQL( $lunar_id_transaction ) . '" AND `order_id`="' . pSQL( $order_id ) . '"';

			return Db::getInstance()->execute( $query );
		} else {
			return false;
		}
	}

	public function hookDisplayAdminOrder( $params ) {
		$id_order = $params['id_order'];
		$order    = new Order( (int) $id_order );
		if ( $order->module == $this->name ) {
			$order_token        = Tools::getAdminToken( 'AdminOrders' . (int) Tab::getIdFromClassName( 'AdminOrders' ) . (int) $this->context->employee->id );
			$lunartransaction = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . 'lunar_admin WHERE order_id = ' . (int) $id_order );
			$this->context->smarty->assign( array(
				'ps_version'         => _TB_VERSION_,
				'id_order'           => $id_order,
				'order_token'        => $order_token,
				'lunartransaction' => $lunartransaction
			) );

			return $this->display( __FILE__, 'views/templates/hook/admin-order.tpl' );
		}
	}

	public function hookBackOfficeHeader() {
		if ( Tools::getIsset( 'vieworder' ) && Tools::getIsset( 'id_order' ) && Tools::getIsset( 'lunar_action' ) ) {
			$lunar_action     = Tools::getValue( 'lunar_action' );
			$id_order           = (int) Tools::getValue( 'id_order' );
			$order              = new Order( (int) $id_order );
			$lunartransaction = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . 'lunar_admin WHERE order_id = ' . (int) $id_order );
			$transactionid      = $lunartransaction['lunar_tid'];
			Lunar\Client::setKey( Configuration::get( 'LUNAR_SECRET_KEY' ) );
			$fetch = Lunar\Transaction::fetch( $transactionid );

			switch ( $lunar_action ) {
				case "capture":
					if ( $lunartransaction['captured'] == 'YES' ) {
						$response = array(
							'warning' => 1,
							'message' => Tools::displayError( 'Transaction was already captured.You can only capture once.' ),
						);
					} else if ( isset( $lunartransaction ) ) {
						$amount              = ( ! empty( $fetch['transaction']['pendingAmount'] ) ) ? (int) $fetch['transaction']['pendingAmount'] : 0;
						$currency            = new Currency( (int) $order->id_currency );
						$currency_multiplier = CurrencyHelper::getCurrencyMultiplier( $currency->iso_code );
						if ( $amount ) {
							//Capture transaction
							$data    = array(
								'currency' => $currency->iso_code,
								'amount'   => $amount,
							);
							$capture = Lunar\Transaction::capture( $transactionid, $data );

							if ( is_array( $capture ) && ! empty( $capture['error'] ) && $capture['error'] == 1 ) {
								Logger::addLog( $capture['message'] );
								$response = array(
									'error'   => 1,
									'message' => Tools::displayError( $capture['message'] ),
								);
							} else {
								if ( ! empty( $capture['transaction'] ) ) {
									//Update order status
									//$status_paid = (int)Configuration::get('PS_OS_PAYMENT');
									$status_paid = (int) Configuration::get( 'LUNAR_ORDER_STATUS_CAPTURED' );
									$order->setCurrentState( $status_paid, $this->context->employee->id );

									//Update transaction details
									$fields = array(
										'captured' => 'YES',
									);
									$this->updateTransactionID( $transactionid, (int) $id_order, $fields );

									//Set message
									$message = 'Trx ID: ' . $transactionid . '
                                    Authorized Amount: ' . ( $capture['transaction']['amount'] / $currency_multiplier ) . '
                                    Captured Amount: ' . ( $capture['transaction']['capturedAmount'] / $currency_multiplier ) . '
                                    Order time: ' . $capture['transaction']['created'] . '
                                    Currency code: ' . $capture['transaction']['currency'];

									$msg     = new Message();
									$message = strip_tags( $message, '<br>' );
									if ( Validate::isCleanHtml( $message ) ) {
										$msg->message     = $message;
										$msg->id_cart     = (int) $order->id_cart;
										$msg->id_customer = (int) $order->id_customer;
										$msg->id_order    = (int) $order->id;
										$msg->private     = 1;
										$msg->add();
									}

									//Set response
									$response = array(
										'success' => 1,
										'message' => Tools::displayError( 'Transaction was successfully captured.' ),
									);
								} else {
									if ( ! empty( $capture[0]['message'] ) ) {
										$response = array(
											'warning' => 1,
											'message' => Tools::displayError( $capture[0]['message'] ),
										);
									} else {
										$response = array(
											'error'   => 1,
											'message' => Tools::displayError( 'Oops! An error has occurred while capturing the payment.' ),
										);
									}
								}
							}
						} else {
							$response = array(
								'error'   => 1,
								'message' => Tools::displayError( 'The amount is not valid for capturing. Please double check the format.' ),
							);
						}
					} else {
						$response = array(
							'error'   => 1,
							'message' => Tools::displayError( 'The Lunar transaction is not valid.' ),
						);
					}

					break;

				case "refund":
					if ( $lunartransaction['captured'] == 'NO' ) {
						$response = array(
							'warning' => 1,
							'message' => Tools::displayError( 'You need to capture the transaction before refunding.' ),
						);
					} else if ( isset( $lunartransaction ) ) {
						$lunar_amount_to_refund = Tools::getValue( 'lunar_amount_to_refund' );
						$lunar_refund_reason    = Tools::getValue( 'lunar_refund_reason' );

						if ( ! Validate::isPrice( $lunar_amount_to_refund ) ) {
							$response = array(
								'error'   => 1,
								'message' => Tools::displayError( 'The amount is not valid for refunding. Please double check the format.' ),
							);
						} else {
							$currency            = new Currency( (int) $order->id_currency );
							$currency_multiplier = CurrencyHelper::getCurrencyMultiplier( $currency->iso_code );
							//Refund transaction
							$amount = ceil( Tools::ps_round( $lunar_amount_to_refund, 2 ) * $currency_multiplier );
							$data   = array(
								'descriptor' => $lunar_refund_reason,
								'amount'     => $amount,
							);
							$refund = Lunar\Transaction::refund( $transactionid, $data );

							if ( is_array( $refund ) && ! empty( $refund['error'] ) && $refund['error'] == 1 ) {
								Logger::addLog( $refund['message'] );
								$response = array(
									'error'   => 1,
									'message' => Tools::displayError( $refund['message'] ),
								);
							} else {
								if ( ! empty( $refund['transaction'] ) ) {
									//Update order status
									$order->setCurrentState( (int) Configuration::get( 'PS_OS_REFUND' ), $this->context->employee->id );

									//Update transaction details
									$fields = array(
										'refunded_amount' => $lunartransaction['refunded_amount'] + $lunar_amount_to_refund,
									);
									$this->updateTransactionID( $transactionid, (int) $id_order, $fields );

									//Set message
									$message = 'Trx ID: ' . $transactionid . '
                                        Authorized Amount: ' . ( $refund['transaction']['amount'] / $currency_multiplier ) . '
                                        Refunded Amount: ' . ( $refund['transaction']['refundedAmount'] / $currency_multiplier ) . '
                                        Order time: ' . $refund['transaction']['created'] . '
                                        Currency code: ' . $refund['transaction']['currency'];

									$msg     = new Message();
									$message = strip_tags( $message, '<br>' );
									if ( Validate::isCleanHtml( $message ) ) {
										$msg->message     = $message;
										$msg->id_cart     = (int) $order->id_cart;
										$msg->id_customer = (int) $order->id_customer;
										$msg->id_order    = (int) $order->id;
										$msg->private     = 1;
										$msg->add();
									}

									//Set response
									$response = array(
										'success' => 1,
										'message' => Tools::displayError( 'The transaction was successfully refunded.' ),
									);
								} else {
									if ( ! empty( $refund[0]['message'] ) ) {
										$response = array(
											'warning' => 1,
											'message' => Tools::displayError( $refund[0]['message'] ),
										);
									} else {
										$response = array(
											'error'   => 1,
											'message' => Tools::displayError( 'Oops! An error occurred during the refund operation.' ),
										);
									}
								}
							}
						}
					} else {
						$response = array(
							'error'   => 1,
							'message' => Tools::displayError( 'The Lunar transaction is not valid.' ),
						);
					}

					break;

				case "void":
					if ( $lunartransaction['captured'] == 'YES' ) {
						$response = array(
							'warning' => 1,
							'message' => Tools::displayError( 'The transaction can no longer be voided. It has already been captured. The only allowed operation is refund.' ),
						);
					} else if ( isset( $lunartransaction ) ) {
						//Void transaction
						$amount = (int) $fetch['transaction']['amount'] - $fetch['transaction']['refundedAmount'];
						$data   = array(
							'amount' => $amount,
						);
						$void   = Lunar\Transaction::void( $transactionid, $data );

						if ( is_array( $void ) && ! empty( $void['error'] ) && $void['error'] == 1 ) {
							Logger::addLog( $void['message'] );
							$response = array(
								'error'   => 1,
								'message' => Tools::displayError( $void['message'] ),
							);
						} else {
							if ( ! empty( $void['transaction'] ) ) {
								//Update order status
								$order->setCurrentState( (int) Configuration::get( 'PS_OS_CANCELED' ), $this->context->employee->id );

								$currency            = new Currency( (int) $order->id_currency );
								$currency_multiplier = CurrencyHelper::getCurrencyMultiplier( $currency->iso_code );
								//Set message
								$message = 'Trx ID: ' . $transactionid . '
                                        Authorized Amount: ' . ( $void['transaction']['amount'] / $currency_multiplier ) . '
                                        Refunded Amount: ' . ( $void['transaction']['refundedAmount'] / $currency_multiplier ) . '
                                        Order time: ' . $void['transaction']['created'] . '
                                        Currency code: ' . $void['transaction']['currency'];

								$msg     = new Message();
								$message = strip_tags( $message, '<br>' );
								if ( Validate::isCleanHtml( $message ) ) {
									$msg->message     = $message;
									$msg->id_cart     = (int) $order->id_cart;
									$msg->id_customer = (int) $order->id_customer;
									$msg->id_order    = (int) $order->id;
									$msg->private     = 1;
									$msg->add();
								}

								//Set response
								$response = array(
									'success' => 1,
									'message' => Tools::displayError( 'The transaction has been successfully voided.' ),
								);
							} else {
								if ( ! empty( $void[0]['message'] ) ) {
									$response = array(
										'warning' => 1,
										'message' => Tools::displayError( $void[0]['message'] ),
									);
								} else {
									$response = array(
										'error'   => 1,
										'message' => Tools::displayError( 'Oops! An error occurred during the refund operation.' ),
									);
								}
							}
						}
					} else {
						$response = array(
							'error'   => 1,
							'message' => Tools::displayError( 'The Lunar transaction is not valid.' ),
						);
					}

					break;
			}

			die( json_encode( $response ) );
		}

		if ( Tools::getIsset( 'upload_logo' ) ) {
			$logo_name = Tools::getValue( 'logo_name' );

			if ( empty( $logo_name ) ) {
				$response = array(
					'status'  => 0,
					'message' => 'The logo name is mandatory. Please add it.'
				);
				die( json_encode( $response ) );
			}

			$logo_slug = Tools::strtolower( str_replace( ' ', '-', $logo_name ) );
			$sql       = new DbQuery();
			$sql->select( '*' );
			$sql->from( 'lunar_logos', 'PL' );
			$sql->where( 'PL.slug = "' . pSQL( $logo_slug ) . '"' );
			$logos = Db::getInstance()->executes( $sql );
			if ( ! empty( $logos ) ) {
				$response = array(
					'status'  => 0,
					'message' => 'This logo name already exists. Please change it and try again.'
				);
				die( json_encode( $response ) );
			}

			if ( ! empty( $_FILES['logo_file']['name'] ) ) {
				$target_dir    = _PS_MODULE_DIR_ . $this->name . '/views/img/';
				$name          = basename( $_FILES['logo_file']["name"] );
				$path_parts    = pathinfo( $name );
				$extension     = $path_parts['extension'];
				$file_name     = $logo_slug . '.' . $extension;
				$target_file   = $target_dir . basename( $file_name );
				$imageFileType = pathinfo( $target_file, PATHINFO_EXTENSION );

				/*$check = getimagesize($_FILES['logo_file']["tmp_name"]);
                if($check === false) {
                    $response = array(
                        'status' => 0,
                        'message' => 'File is not an image. Please upload JPG, JPEG, PNG or GIF file.'
                    );
                    die(json_encode($response));
                }*/

				// Check if file already exists
				if ( file_exists( $target_file ) ) {
					$response = array(
						'status'  => 0,
						'message' => 'Sorry, it seems that the file already exists. Please load a file with a different name.'
					);
					die( json_encode( $response ) );
				}

				// Allow certain file formats
				if ( $imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg"
				     && $imageFileType != "gif" && $imageFileType != "svg" ) {
					$response = array(
						'status'  => 0,
						'message' => 'Sorry, only JPG, JPEG, PNG, GIF & SVG files are allowed.'
					);
					die( json_encode( $response ) );
				}

				if ( move_uploaded_file( $_FILES['logo_file']["tmp_name"], $target_file ) ) {
					$query = 'INSERT INTO ' . _DB_PREFIX_ . 'lunar_logos (`name`, `slug`, `file_name`, `default_logo`, `created_at`) VALUES ("' . pSQL( $logo_name ) . '", "' . pSQL( $logo_slug ) . '", "' . pSQL( $file_name ) . '", 0, NOW())';
					if ( Db::getInstance()->execute( $query ) ) {
						$response = array(
							'status'  => 1,
							'message' => "The file " . pSQL( basename( $file_name ) ) . " has been uploaded."
						);
						//Configuration::updateValue('LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO', basename($file_name));
						die( json_encode( $response ) );
					} else {
						unlink( $target_file );
						$response = array(
							'status'  => 0,
							'message' => "Oops! An error occurred while saving the logo."
						);
						die( json_encode( $response ) );
					}
				} else {
					$response = array(
						'status'  => 0,
						'message' => 'Sorry, there was an error uploading your file. Please try again.'
					);
					die( json_encode( $response ) );
				}
			} else {
				$response = array(
					'status'  => 0,
					'message' => 'Please select a file for upload.'
				);
				die( json_encode( $response ) );
			}
		}

		if ( Tools::getIsset( 'change_language' ) ) {
			$language_code = ( ! empty( Tools::getvalue( 'lang_code' ) ) ) ? Tools::getvalue( 'lang_code' ) : Configuration::get( 'LUNAR_LANGUAGE_CODE' );
			Configuration::updateValue( 'LUNAR_LANGUAGE_CODE', $language_code );
			$token = Tools::getAdminToken( 'AdminModules' . (int) Tab::getIdFromClassName( 'AdminModules' ) . (int) $this->context->employee->id );
			$link  = $this->context->link->getAdminLink( 'AdminModules' ) . '&token=' . $token . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
			Tools::redirectAdmin( $link );
		}

		if ( Tools::getValue( 'configure' ) == $this->name ) {
			$this->context->controller->addCSS( $this->_path . 'views/css/backoffice.css' );
			$this->context->controller->addJS( $this->_path . 'views/js/backoffice.js' );
		}
	}
}
