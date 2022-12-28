<?php
/*
 * @package     RadicalMart Payment Payselection Plugin
 * @subpackage  plg_radicalmart_payment_payselection
 * @version     __DEPLOY_VERSION__
 * @author      Delo Design - delo-design.ru
 * @copyright   Copyright (c) 2022 Delo Design. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://delo-design.ru/
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\RadicalMart\Administrator\Helper\ParamsHelper;
use Joomla\Http\Response;
use Joomla\Registry\Registry;

class plgRadicalMart_PaymentPayselection extends CMSPlugin
{
	/**
	 * Loads the application object.
	 *
	 * @var  CMSApplication
	 *
	 * @since   1.0.0
	 */
	protected $app = null;

	/**
	 * Loads the database object.
	 *
	 * @var  JDatabaseDriver
	 *
	 * @since  1.0.0
	 */
	protected $db = null;

	/**
	 * Affects constructor behavior.
	 *
	 * @var  boolean
	 *
	 * @since   1.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Payment method params.
	 *
	 * @var  Registry
	 *
	 * @since   1.0.0
	 */
	protected $_paymentMethodParams = null;

	/**
	 * Prepare order shipping method data.
	 *
	 * @param   string  $context   Context selector string.
	 * @param   object  $method    Method data.
	 * @param   array   $formData  Order form data.
	 * @param   array   $products  Order products data.
	 * @param   array   $currency  Order currency data.
	 *
	 * @throws  Exception
	 *
	 * @since  1.0.0
	 */
	public function onRadicalMartGetPaymentMethods(string $context, object $method, array $formData,
	                                               array  $products, array $currency)
	{
		// Set disabled
		$method->disabled = false;

		// Clean secret param
		$method->params->set('api_id', '');
		$method->params->set('api_secret', '');

		// Set order
		$method->order              = new stdClass();
		$method->order->id          = $method->id;
		$method->order->title       = $method->title;
		$method->order->code        = $method->code;
		$method->order->description = $method->description;
		$method->order->price       = [];
	}

	/**
	 * Check can order pay.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   object  $order    Order Item data.
	 *
	 * @throws  Exception
	 *
	 * @return boolean True if can pay, False if not.
	 *
	 * @since  1.0.0
	 */
	public function onRadicalMartCheckOrderPay($context, $order)
	{
		// Check order payment method
		if (empty($order->payment)
			|| empty($order->payment->id)
			|| empty($order->payment->plugin)
			|| $order->payment->plugin !== 'payselection') return false;

		// Check method params
		$params = $this->getPaymentMethodParams($order->payment->id);
		if (empty($params->get('api_id')) || empty($params->get('api_secret')))
		{
			return false;
		}

		// Check order status
		if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
		{
			return false;
		}

		return true;
	}

	/**
	 * Method to get payment method params.
	 *
	 * @param   int  $pk  Payment method id.
	 *
	 * @return Registry Payment method params
	 *
	 * @since  1.0.0
	 */
	protected function getPaymentMethodParams($pk = null)
	{
		$pk = (int) $pk;
		if (empty($pk)) return new Registry();

		if ($this->_paymentMethodParams === null) $this->_paymentMethodParams = array();
		if (!isset($this->_paymentMethodParams[$pk]))
		{
			$db                              = $this->db;
			$query                           = $db->getQuery(true)
				->select('params')
				->from($db->quoteName('#__radicalmart_payment_methods'))
				->where('id = ' . $pk);
			$this->_paymentMethodParams[$pk] = ($result = $db->setQuery($query, 0, 1)->loadResult())
				? new Registry($result) : new Registry();
		}

		return $this->_paymentMethodParams[$pk];
	}

	/**
	 * Method to create transaction and redirect data to RadicalMart.
	 *
	 * @param   object    $order   Order data object.
	 * @param   Registry  $params  RadicalMart component params.
	 * @param   array     $links   RadicalMart plugin links.
	 *
	 * @throws  Exception
	 *
	 * @return  array  Payment redirect data on success.
	 *
	 * @since   1.0.0
	 */
	public function onRadicalMartPay($order, $links, $params)
	{
		$result = [
			'pay_instant' => true,
			'link'        => false,
		];

		// Check order payment method
		if (empty($order->payment)
			|| empty($order->payment->id)
			|| empty($order->payment->plugin)
			|| $order->payment->plugin !== 'payselection')
		{
			return $result;
		}

		// Get method params
		$params = ParamsHelper::getPaymentMethodsParams($order->payment->id);
		if (empty($params->get('api_id')) || empty($params->get('api_secret')))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
		}

		// Check order status
		if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', array())))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_PAYMENT_NOT_AVAILABLE'));
		}

		// Prepare data
		$amount = number_format($order->total['final'], 2, '.', '');
		$data   = [
			'MetaData'       => [
				'PaymentType' => 'Pay'
			],
			'PaymentRequest' => [
				'OrderId'     => $order->number,
				'Amount'      => $amount,
				'Currency'    => $order->currency['code'],
				'Description' => $order->title,
				'RebillFlag'  => false,
				'ExtraData'   => [
					'ReturnUrl' => $links['success'] . '/' . $order->number
				]
			],
		];

		if ((int) $params->get('receipt', 0) === 1)
		{
			$data['ReceiptData'] = [
				'timestamp'   => Factory::getDate()->format('d.m.Y h:m:s'),
				'external_id' => $order->number,
				'receipt'     => [
					'client'   => [],
					'company'  => [
						'inn'             => $params->get('receipt_company_inn'),
						'payment_address' => $params->get('receipt_company_payment_address', Uri::root())
					],
					'items'    => [],
					'payments' => [
						[
							'type' => (int) $params->get('receipt_payments_type', 0),
							'sum'  => $order->total['final'],
						]
					],
					'total'    => $order->total['final']
				]
			];

			$name = [];
			if (!empty($order->contacts['first_name']))
			{
				$name[] = $order->contacts['first_name'];
			}
			if (!empty($order->contacts['last_name']))
			{
				$name[] = $order->contacts['first_name'];
			}
			if (!empty($name))
			{
				$data['ReceiptData']['receipt']['client']['name'] = implode(' ', $name);
			}

			if (!empty($order->contacts['email']))
			{
				$data['ReceiptData']['receipt']['client']['email'] = $order->contacts['email'];
			}

			if (!empty($order->contacts['phone']))
			{
				$data['ReceiptData']['receipt']['client']['phone'] = $order->contacts['phone'];
			}

			foreach ($order->products as $product)
			{
				$data['ReceiptData']['receipt']['items'][] = [
					'name'           => $product->title,
					'price'          => $product->order['base'],
					'quantity'       => $product->order['quantity'],
					'sum'            => $product->order['sum_final'],
					'payment_method' => $params->get('receipt_items_product_payment_method', 'full_payment'),
					'payment_object' => $params->get('receipt_items_product_payment_object', 'commodity'),
					'vat'            => ['type' => $params->get('receipt_items_product_vat_type', 'none')]
				];
			}


			// Add shipping
			if (!empty($order->shipping) && !empty($order->shipping->order)
				&& !empty($order->shipping->order->price)
				&& (!empty($order->shipping->order->price['base']) || !empty($order->shipping->order->price['final']))
			)
			{
				$shipping = $order->shipping;
				$base     = (!empty($shipping->order->price['base']))
					? $shipping->order->price['base'] : $shipping->order->price['final'];
				$final    = (!empty($shipping->order->price['final']))
					? $shipping->order->price['final'] : $shipping->order->price['base'];

				$data['ReceiptData']['receipt']['items'][] = [
					'name'           => (!empty($shipping->order->title)) ? $shipping->order->title : $shipping->title,
					'price'          => $base,
					'quantity'       => 1,
					'sum'            => $final,
					'payment_method' => $params->get('receipt_items_shipping_payment_method', 'full_payment'),
					'payment_object' => $params->get('receipt_items_shipping_payment_object', 'service'),
					'vat'            => ['type' => $params->get('receipt_items_shipping_vat_type', 'none')]
				];
			}
		}

		// Create transaction
		$result['link'] = $this->createTransaction($data, array(
			'api_id'     => $params->get('api_id'),
			'api_secret' => $params->get('api_secret'),
		));

		return $result;
	}

	/**
	 * Method to set RadicalMart order pay status after payment.
	 *
	 * @param   array                                                  $input   Input data.
	 * @param   \Joomla\Component\RadicalMart\Site\Model\PaymentModel  $model   RadicalMart model.
	 * @param   Registry                                               $params  RadicalMart params.
	 *
	 * @throws Exception
	 *
	 * @since  1.0.0
	 */
	public function onRadicalMartCallback($input, $model, $params)
	{
		// Add logger
		Log::addLogger(array(
			'text_file'         => 'plg_radicalmart_payment_payselection.php',
			'text_entry_format' => "{DATETIME}\t{CLIENTIP}\t{MESSAGE}\t{PRIORITY}"),
			Log::ALL, array('plg_radicalmart_payment_payselection'));

		try
		{
			if (empty($input['TransactionId']))
			{
				throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'));
			}

			// Get order
			if (empty($input['OrderId']))
			{
				throw new Exception(Text::_('COM_RADICALMART_ERROR_ORDER_NOT_FOUND'));
			}
			if (!$order = $model->getOrder($input['OrderId']))
			{
				$messages = array();
				foreach ($model->getErrors() as $error)
				{
					$messages[] = ($error instanceof Exception) ? $error->getMessage() : $error;
				}
				if (empty($messages)) $messages[] = Text::_('COM_RADICALMART_ERROR_ORDER_NOT_FOUND');

				throw new Exception(implode(PHP_EOL, $messages), 404);
			}

			// Check order payment method
			if (empty($order->payment)
				|| empty($order->payment->id)
				|| empty($order->payment->plugin)
				|| $order->payment->plugin !== 'payselection')
			{
				throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_METHOD_NOT_FOUND'));
			}

			// Get method params
			$params = $this->getPaymentMethodParams($order->payment->id);
			if (empty($params->get('api_id')) || empty($params->get('api_secret')))
			{
				throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Get transaction
			$transaction = $this->getTransaction($input['TransactionId'], array(
				'api_id'     => $params->get('api_id'),
				'api_secret' => $params->get('api_secret'),
			));

			// Get order
			if ($transaction->get('OrderId') !== $order->number)
			{
				Text::_('COM_RADICALMART_EXPRESS_ERROR_ORDER_NOT_FOUND');
			}

			// Set order status
			$newStatus = (int) $params->get('paid_status');
			if ($newStatus && $newStatus !== (int) $order->status->id
				&& $transaction->get('TransactionState') === 'success')
			{
				$model->addLog($order->id, 'payselection_paid', array(
					'plugin'         => 'payselection',
					'group'          => 'radicalmart_payment',
					'transaction_id' => $input['TransactionId'],
					'user_id'        => -1
				));

				if (!$model->updateStatus($order->id, $newStatus, false, -1))
				{
					$messages = array();
					foreach ($model->getErrors() as $error)
					{
						$messages[] = ($error instanceof Exception) ? $error->getMessage() : $error;
					}
					throw new Exception(implode(PHP_EOL, $messages));
				}
			}
		}
		catch (Exception $e)
		{
			Log::add($e->getMessage(), Log::ERROR, 'plg_radicalmart_payment_payselection');

			throw new Exception($e->getMessage(), 500);
		}

		$this->app->close(200);
	}

	/**
	 * Method to display logs in RadicalMart order.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   array   $log      Log data.
	 *
	 * @since  1.0.0
	 */
	public function onRadicalMartGetOrderLogs($context = null, &$log = array())
	{
		if ($log['action'] === 'payselection_paid')
		{
			$log['action_text'] = Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_LOGS_PAYSELECTION_PAID');
			if (!empty($log['transaction_id']))
			{
				$log['message'] = Text::sprintf('PLG_RADICALMART_PAYMENT_PAYSELECTION_LOGS_PAYSELECTION_PAID_MESSAGE',
					$log['transaction_id']);
			}
		}
	}

	/**
	 * Method to send data for Express config.
	 *
	 * @return array Express list data.
	 *
	 * @since   1.0.0
	 */
	public function onRadicalMartExpressPaymentMethods()
	{
		return array(
			'text'  => Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_EXPRESS_TITLE'),
			'value' => 'payselection'
		);
	}

	/**
	 *  Method to create transaction and redirect data to RadicalMart Express.
	 *
	 * @param   object    $order   Order data object.
	 * @param   Registry  $params  RadicalMart Express component params.
	 * @param   array     $links   RadicalMart Express plugin links.
	 *
	 * @throws  Exception
	 *
	 * @return  array  Payment redirect data on success.
	 *
	 * @since   1.0.0
	 */
	public function onRadicalMartExpressPay($order, $links, $params)
	{
		$result = array(
			'pay_instant' => ($params->get('payselection_pay_instant', 1)),
			'link'        => false,
		);

		// Check params
		if (empty($params->get('payselection_api_id')) || empty($params->get('payselection_api_secret')))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
		}

		// Prepare data
		$amount = number_format($order->total['final'], 2, '.', '');
		$data   = array(
			'MetaData'       => array(
				'PaymentType' => "Pay"
			),
			'PaymentRequest' => array(
				'OrderId'     => $order->number,
				'Amount'      => $amount,
				'Currency'    => $order->currency['code'],
				'Description' => $order->title,
				'RebillFlag'  => false,
				'ExtraData'   => array(
					'ReturnUrl' => $links['success'] . '/' . $order->number
				)
			),
		);

		if ((int) $params->get('payselection_receipt', 0) === 1)
		{
			$data['ReceiptData'] = [
				'timestamp'   => Factory::getDate()->format('d.m.Y h:m:s'),
				'external_id' => $order->number,
				'receipt'     => [
					'client'   => [],
					'company'  => [
						'inn'             => $params->get('payselection_receipt_company_inn'),
						'payment_address' => $params->get('payselection_receipt_company_payment_address', Uri::root())
					],
					'items'    => [],
					'payments' => [
						[
							'type' => (int) $params->get('payselection_receipt_payments_type', 0),
							'sum'  => $order->total['final'],
						]
					],
					'total'    => $order->total['final']
				]
			];

			$name = [];
			if (!empty($order->contacts['first_name']))
			{
				$name[] = $order->contacts['first_name'];
			}
			if (!empty($order->contacts['last_name']))
			{
				$name[] = $order->contacts['first_name'];
			}
			if (!empty($name))
			{
				$data['ReceiptData']['receipt']['client']['name'] = implode(' ', $name);
			}

			if (!empty($order->contacts['email']))
			{
				$data['ReceiptData']['receipt']['client']['email'] = $order->contacts['email'];
			}

			if (!empty($order->contacts['phone']))
			{
				$data['ReceiptData']['receipt']['client']['phone'] = $order->contacts['phone'];
			}

			foreach ($order->products as $product)
			{
				$data['ReceiptData']['receipt']['items'][] = [
					'name'           => $product->title,
					'price'          => $product->order['base'],
					'quantity'       => $product->order['quantity'],
					'sum'            => $product->order['sum_final'],
					'payment_method' => $params->get('payselection_receipt_items_product_payment_method', 'full_payment'),
					'payment_object' => $params->get('payselection_receipt_items_product_payment_object', 'commodity'),
					'vat'            => ['type' => $params->get('payselection_receipt_items_product_vat_type', 'none')]
				];
			}
		}

		// Create transaction
		$result['link'] = $this->createTransaction($data, array(
			'api_id'     => $params->get('payselection_api_id'),
			'api_secret' => $params->get('payselection_api_secret'),
		));

		return $result;
	}

	/**
	 * Method to set RadicalMart Express order pay status after payment.
	 *
	 * @param   array                           $input   Input data.
	 * @param   RadicalMartExpressModelPayment  $model   RadicalMartExpress model.
	 * @param   Registry                        $params  RadicalMartExpress params.
	 *
	 * @throws Exception
	 *
	 * @since  1.0.0
	 */
	public function onRadicalMartExpressCallback($input, $model, $params)
	{
		// Add logger
		Log::addLogger(array(
			'text_file'         => 'plg_radicalmart_payment_payselection.php',
			'text_entry_format' => "{DATETIME}\t{CLIENTIP}\t{MESSAGE}\t{PRIORITY}"),
			Log::ALL, array('plg_radicalmart_payment_payselection'));

		try
		{
			if (empty($input['TransactionId']))
			{
				throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'));
			}

			// Get order
			if (empty($input['OrderId']))
			{
				throw new Exception(Text::_('COM_RADICALMART_ERROR_ORDER_NOT_FOUND'));
			}
			if (!$order = $model->getOrder($input['OrderId']))
			{
				$messages = array();
				foreach ($model->getErrors() as $error)
				{
					$messages[] = ($error instanceof Exception) ? $error->getMessage() : $error;
				}
				if (empty($messages)) $messages[] = Text::_('COM_RADICALMART_ERROR_ORDER_NOT_FOUND');

				throw new Exception(implode(PHP_EOL, $messages), 404);
			}

			// Get method params
			if (empty($params->get('payselection_api_id')) || empty($params->get('payselection_api_secret')))
			{
				throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Get transaction
			$transaction = $this->getTransaction($input['TransactionId'], array(
				'api_id'     => $params->get('payselection_api_id'),
				'api_secret' => $params->get('payselection_api_secret'),
			));

			// Get order
			if ($transaction->get('OrderId') !== $order->number)
			{
				Text::_('COM_RADICALMART_EXPRESS_ERROR_ORDER_NOT_FOUND');
			}

			// Set order status
			if ($order->status->id !== 2 && $transaction->get('TransactionState') === 'success')
			{
				if (!$model->updateStatus($order->id, 2, false, -1))
				{
					$messages = array();
					foreach ($model->getErrors() as $error)
					{
						$messages[] = ($error instanceof Exception) ? $error->getMessage() : $error;
					}
					throw new Exception(implode(PHP_EOL, $messages), 500);
				}

				$model->addLog($order->id, 'payselection_paid', array(
					'plugin'         => 'payselection',
					'group'          => 'radicalmart_payment',
					'transaction_id' => $input['TransactionId'],
					'user_id'        => -1
				));
			}
		}
		catch (Exception $e)
		{
			Log::add($e->getMessage(), Log::ERROR, 'plg_radicalmart_payment_payselection');

			throw new Exception($e->getMessage(), 500);
		}

		$this->app->close(200);
	}

	/**
	 * Method to display logs in RadicalMart Express order.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   array   $log      Log data.
	 *
	 * @since  1.0.0
	 */
	public function onRadicalMartExpressGetOrderLogs($context = null, &$log = array())
	{
		if ($log['action'] === 'payselection_paid')
		{
			$log['action_text'] = Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_LOGS_PAYSELECTION_PAID');
			if (!empty($log['transaction_id']))
			{
				$log['message'] = Text::sprintf('PLG_RADICALMART_PAYMENT_PAYSELECTION_LOGS_PAYSELECTION_PAID_MESSAGE',
					$log['transaction_id']);
			}
		}
	}

	/**
	 * Method to create Payselection transaction.
	 *
	 * @param   array  $data    Request data.
	 * @param   array  $access  Access params.
	 *
	 * @throws  Exception
	 *
	 * @return  string  Pay link on success.
	 *
	 * @since   1.0.0
	 */
	protected function createTransaction($data = array(), $access = array())
	{
		// Check access
		if (empty($access['api_id']) || empty($access['api_secret']))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
		}

		// Convert data
		if (!is_array($data)) $data = (new Registry($data))->toArray();
		$data = json_encode($data);

		// Prepare request
		$url               = 'https://webform.payselection.com/webpayments/create';
		$site              = Uri::getInstance()->getHost();
		$request_id        = md5('createTransaction' . '_' . $site . '_' . $data);
		$request_signature = hash_hmac('sha256',
			implode(PHP_EOL, array(
				'POST',
				'/webpayments/create',
				$access['api_id'],
				$request_id,
				$data))
			, $access['api_secret']);
		$headers           = array(
			'Content-Type'        => 'application/json',
			'X-SITE-ID'           => $access['api_id'],
			'X-REQUEST-ID'        => $request_id,
			'X-REQUEST-SIGNATURE' => $request_signature
		);

		// Send request
		$http = new Http();
		$http->setOption('transport.curl', array(
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => 0
		));
		$response = $http->post($url, $data, $headers);
		$body     = $response->body;
		if (empty($body))
		{
			$message = preg_replace('#^[0-9]*\s#', '', $response->headers['Status']);
			throw new Exception('Payselection: ' . $message, $response->code);
		}

		$context = json_decode($body);
		if ($response->code === 201) $link = $context;
		elseif ($response->code === 409) $link = $context->AddDetails->URL;
		else throw new Exception('Payselection: ' . $context->Code, $response->code);

		return $link;
	}

	/**
	 * Method to get Payselection transaction.
	 *
	 * @param   string  $id      Transaction id.
	 * @param   array   $access  Access params.
	 *
	 * @throws  Exception
	 *
	 * @return  Registry  Transaction data on success.
	 *
	 * @since   1.0.0
	 */
	protected function getTransaction($id = null, $access = array())
	{
		// Check transaction
		if (empty($id))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'));
		}

		// Check access
		if (empty($access['api_id']) || empty($access['api_secret']))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
		}

		// Prepare request
		$url               = 'https://gw.payselection.com/transactions/' . $id;
		$site              = Uri::getInstance()->getHost();
		$request_id        = md5('getTransaction' . '_' . $site . '_' . $id);
		$request_signature = hash_hmac('sha256',
			implode(PHP_EOL, array(
				'GET',
				'/transactions/' . $id,
				$access['api_id'],
				$request_id,
				''))
			, $access['api_secret']);
		$headers           = array(
			'Content-Type'        => 'application/json',
			'X-SITE-ID'           => $access['api_id'],
			'X-REQUEST-ID'        => $request_id,
			'X-REQUEST-SIGNATURE' => $request_signature
		);

		// Send request
		$http = new Http();
		$http->setOption('transport.curl', array(
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => 0
		));
		$response = $http->get($url, $headers, 30);
		if ($response->code)
			$body = $response->body;
		if (empty($body))
		{
			$message = preg_replace('#^[0-9]*\s#', '', $response->headers['Status']);
			throw new Exception('Payselection: ' . $message, $response->code);
		}

		$context = new Registry($body);

		if ($response->code === 200) return $context;
		elseif ($response->code === 404)
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'));
		}
		else throw new Exception('Payselection: ' . $context->get('Code'));
	}
}