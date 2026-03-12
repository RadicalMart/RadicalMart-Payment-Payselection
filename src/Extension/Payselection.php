<?php
/*
 * @package     RadicalMart Payment Payselection Plugin
 * @subpackage  plg_radicalmart_payment_payselection
 * @version     __DEPLOY_VERSION__
 * @author      RadicalMart Team - radicalmart.ru
 * @copyright   Copyright (c) 2026 RadicalMart. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://radicalmart.ru/
 */

namespace Joomla\Plugin\RadicalMartPayment\Payselection\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\RadicalMart\Administrator\Helper\DebugHelper as RadicalMartDebugHelper;
use Joomla\Component\RadicalMart\Administrator\Helper\ParamsHelper as RadicalMartParamsHelper;
use Joomla\Component\RadicalMart\Site\Model\PaymentModel as RadicalMartPaymentModel;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\ParamsHelper as RadicalMartExpressParamsHelper;
use Joomla\Component\RadicalMartExpress\Site\Model\PaymentModel as RadicalMartExpressPaymentModel;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\RadicalMartPayment\Payselection\Helper\LogHelper;
use Joomla\Registry\Registry;

class Payselection extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    bool
	 *
	 * @since  1.2.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Enable on RadicalMart
	 *
	 * @var  bool
	 *
	 * @since  2.0.0
	 */
	public bool $radicalmart = true;

	/**
	 * Enable on RadicalMartExpress
	 *
	 * @var  bool
	 *
	 * @since  2.0.0
	 */
	public bool $radicalmart_express = true;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   2.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onRadicalMartGetOrderPaymentMethods' => 'onGetOrderPaymentMethods',
			'onRadicalMartGetOrderLogs'           => 'onGetOrderLogs',
			'onRadicalMartCheckOrderPay'          => 'onCheckOrderPay',
			'onRadicalMartPaymentPay'             => 'onPaymentPay',
			'onRadicalMartPaymentCallback'        => 'onPaymentCallback',
			'onRadicalMartPrepareMethodForm'      => 'onRadicalMartPrepareMethodForm',

			'onRadicalMartExpressGetOrderPaymentMethods' => 'onGetOrderPaymentMethods',
			'onRadicalMartExpressGetOrderLogs'           => 'onGetOrderLogs',
			'onRadicalMartExpressCheckOrderPay'          => 'onCheckOrderPay',
			'onRadicalMartExpressPaymentPay'             => 'onPaymentPay',
			'onRadicalMartExpressPaymentCallback'        => 'onPaymentCallback',
			'onRadicalMartExpressPrepareConfigForm'      => 'onRadicalMartExpressPrepareConfigForm',
		];
	}

	/**
	 * Set url_notify field default value in RadicalMart method form.
	 *
	 * @param   Form   $form     The form to be altered.
	 * @param   mixed  $data     The associated data for the form.
	 * @param   mixed  $tmpData  The temporary data for the form.
	 *
	 * @throws \Exception
	 *
	 * @since 2.0.0
	 */
	public function onRadicalMartPrepareMethodForm(Form $form, mixed $data = [], mixed $tmpData = []): void
	{
		$value = Uri::getInstance()->toString(['scheme', 'host', 'port'])
			. '/' . RadicalMartParamsHelper::getComponentParams()
				->get('payment_entry', 'radicalmart_payment') . '/payselection/callback';
		$form->setFieldAttribute('url_notify', 'default', $value, 'params');
	}

	/**
	 * Set url_notify field default value in RadicalMartExpress config form.
	 *
	 * @param   Form   $form  The form to be altered.
	 * @param   mixed  $data  The associated data for the form.
	 *
	 * @throws \Exception
	 *
	 * @since 2.0.0
	 */
	public function onRadicalMartExpressPrepareConfigForm(Form $form, mixed $data = []): void
	{
		$value = Uri::getInstance()->toString(['scheme', 'host', 'port'])
			. '/' . RadicalMartExpressParamsHelper::getComponentParams()
				->get('payment_entry', 'radicalmart_express_payment') . '/callback';
		$form->setFieldAttribute('url_notify', 'default', $value, 'payment_method_params');
	}

	/**
	 * Prepare RadicalMart & RadicalMart Express order method data.
	 *
	 * @param   string  $context   Context selector string.
	 * @param   object  $method    Method data.
	 * @param   array   $formData  Order form data.
	 * @param   array   $products  Order products data.
	 * @param   array   $currency  Order currency data.
	 *
	 * @throws  \Exception
	 *
	 * @since  2.0.0
	 */
	public function onGetOrderPaymentMethods(string $context, object $method, array $formData,
	                                         array  $products, array $currency): void
	{
		// Set disabled
		$method->disabled = false;

		// Clean secret param
		$method->params->set('api_id', '');
		$method->params->set('api_secret', '');

		// Add RadicalMartExpress payment enable statuses
		if (strpos($context, 'com_radicalmart_express.') !== false)
		{
			$method->params->set('payment_available', [1]);
			$method->params->set('paid_status', 2);
		}

		// Set order
		$method->order              = new \stdClass();
		$method->order->id          = $method->id;
		$method->order->title       = $method->title;
		$method->order->code        = $method->code;
		$method->order->description = $method->description;
		$method->order->price       = [];
	}

	/**
	 * Method to display logs in RadicalMart & RadicalMart Express order.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   array   $log      Log data.
	 *
	 * @since  2.0.0
	 */
	public function onGetOrderLogs(string $context, array &$log): void
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
	 * Check can order pay.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   object  $order    Order Item data.
	 *
	 * @throws  \Exception
	 *
	 * @return boolean True if can pay, False if not.
	 *
	 * @since 2.0.0
	 */
	public function onCheckOrderPay(string $context, object $order): bool
	{
		// Check order payment method
		if (empty($order->payment)
			|| empty($order->payment->id)
			|| empty($order->payment->plugin)
			|| $order->payment->plugin !== 'payselection')
		{
			return false;
		}

		// Check method params
		$params = $this->getMethodParams($context, $order->payment->id);
		if (!$params)
		{
			return false;
		}

		// Check access params
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
	 * Method to create transaction and redirect data to RadicalMart & RadicalMartExpress.
	 *
	 * @param   string    $context  Context selector string.
	 * @param   object    $order    Order data object.
	 * @param   array     $links    Plugin links.
	 * @param   Registry  $params   Component params.
	 *
	 * @throws  \Exception
	 *
	 * @return  array  Payment redirect data on success.
	 *
	 * @since  2.0.0
	 */
	public function onPaymentPay(string $context, object $order, array $links, Registry $params): array
	{
		$debug    = false;
		$data     = false;
		$contents = false;

		$debugger     = 'payment.pay';
		$debuggerFile = 'site_payment_controller.php';
		$this->componentDebug($context, 'addDebug', [$debugger, $debuggerFile, 'Request in plugin']);
		try
		{
			$result = [
				'pay_instant' => true,
				'link'        => false,
			];

			// Check order payment method
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Check payment method', 'start']);
			if (empty($order->payment)
				|| empty($order->payment->id)
				|| empty($order->payment->plugin)
				|| $order->payment->plugin !== 'payselection')
			{
				$this->componentDebug($context, 'addDebug',
					[$debugger, $debuggerFile, $debugAction, 'error', 'Incorrect plugin']);

				return $result;
			}

			// Get method params
			$params = $this->getMethodParams($context, $order->payment->id);
			if (!$params)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Set Debug
			if ((int) $params->get('debug_payment_pay', 0) === 1)
			{
				$debug = 'payment.pay';
			}

			// Check order status
			if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_PAYMENT_NOT_AVAILABLE'));
			}
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'success', null, [], null, false]);

			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Prepare api request data', 'start']);

			// Prepare data
			$amount = (!empty($order->receipt)) ? $order->receipt->amount : $order->total['final'];
			$amount = number_format($amount, 2, '.', '');

			$data = [
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

			// Customer Info
			$customerInfo = [];
			if (!empty($order->contacts['first_name']))
			{
				$customerInfo['FirstName'] = $order->contacts['first_name'];
			}
			if (!empty($order->contacts['last_name']))
			{
				$customerInfo['LastName'] = $order->contacts['last_name'];
			}
			if (!empty($order->contacts['email']))
			{
				$customerInfo['Email']        = $order->contacts['email'];
				$customerInfo['ReceiptEmail'] = $order->contacts['email'];
			}
			if (!empty($order->contacts['phone']))
			{
				$customerInfo['Phone'] = $order->contacts['phone'];
			}
			if (!empty($customerInfo))
			{
				$data['CustomerInfo'] = $customerInfo;
			}

			// Receipt
			if ((int) $params->get('receipt', 0) === 1 && !empty($order->receipt))
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
								'sum'  => $order->receipt->amount,
							]
						],
						'total'    => $order->receipt->amount
					]
				];

				$name = [];
				if (!empty($order->contacts['first_name']))
				{
					$name[] = $order->contacts['first_name'];
				}
				if (!empty($order->contacts['last_name']))
				{
					$name[] = $order->contacts['last_name'];
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

				$measure_codes = [
					'mm'       => 255,
					'cm'       => 20,
					'in'       => 255,
					'ft'       => 255,
					'm '       => 22,
					'm2'       => 32,
					'm3'       => 42,
					'linear_m' => 255,
					'g'        => 10,
					'kg'       => 11,
					't'        => 12,
					'lb'       => 255,
					'ozt'      => 255,
					'pcs'      => 0,
					'ml'       => 40,
					'l'        => 41,
				];
				foreach ($order->receipt->items as $item)
				{
					$measure = (isset($measure_codes[$item['measurement_unit']]))
						? $measure_codes[$item['measurement_unit']] : 255;

					$data['ReceiptData']['receipt']['items'][] = [
						'name'             => $item['name'],
						'price'            => $item['price'],
						'quantity'         => $item['quantity'],
						'sum'              => $item['sum'],
						'measurement_unit' => (string) $measure,
						'payment_method'   => $item['payment_method'],
						'payment_object'   => $item['payment_object'],
						'vat'              => ['type' => $item['vat']['type']]
					];
				}
			}
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'success']);


			// Check access
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Send api request', 'start']);
			if (empty($params->get('api_id')) || empty($params->get('api_secret')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Prepare request data
			$request_path      = '/webpayments/create';
			$request_url       = 'https://webform.payselection.com' . $request_path;
			$request_data      = json_encode($data);
			$request_id        = md5(serialize([$request_path, Uri::getInstance()->getHost(), $request_data]));
			$request_signature = hash_hmac('sha256', implode(PHP_EOL, [
				'POST',
				$request_path,
				$params->get('api_id'),
				$request_id,
				$request_data
			]), $params->get('api_secret'));
			$request_headers   = [
				'Content-Type'        => 'application/json',
				'X-SITE-ID'           => $params->get('api_id'),
				'X-REQUEST-ID'        => $request_id,
				'X-REQUEST-SIGNATURE' => $request_signature,
			];

			// Send request
			$http = new Http();
			$http->setOption('transport.curl', [
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_SSL_VERIFYPEER => 0
			]);
			$response      = $http->post($request_url, $request_data, $request_headers);
			$response_body = $response->body;
			if (empty($response_body))
			{
				$message = preg_replace('#^[0-9]*\s#', '', $response->headers['Status']);

				throw new \Exception($message, $response->code);
			}

			// Parse response
			$contents = json_decode($response_body);
			if ($response->code === 201)
			{
				$link = $contents;
			}
			elseif ($response->code === 409)
			{
				$link = $contents->AddDetails->URL;
			}
			else
			{
				throw new \Exception($contents->Code, $response->code);
			}
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'success']);

			// Add debug data
			if ($debug)
			{
				LogHelper::addDebug($debug, [
					'context'       => $context,
					'request_data'  => $data,
					'response_code' => $response->code,
					'response_data' => $contents
				]);
			}

			$result['link'] = $link;

			return $result;
		}
		catch (\Exception $e)
		{
			$debugData = [
				'context'       => $context,
				'message'       => $e->getMessage(),
				'error_code'    => $e->getCode(),
				'request_data'  => $data,
				'response_data' => $contents
			];
			if ($debug)
			{
				LogHelper::addDebug($debug, $debugData, true);
			}
			LogHelper::addError($debugData);

			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'error', $e->getMessage()]);

			throw new \Exception('Payselection: ' . $e->getCode() . ' - ' . $e->getMessage(), 500, $e);
		}
	}

	/**
	 * Method to set RadicalMart & RadicalMartExpress order pay status after payment.
	 *
	 * @param   array                                                    $input   Input data.
	 * @param   RadicalMartExpressPaymentModel| RadicalMartPaymentModel  $model   RadicalMart model.
	 * @param   Registry                                                 $params  RadicalMart params.
	 *
	 * @throws \Exception
	 *
	 * @since  2.0.0
	 */
	public function onPaymentCallback(string                                                 $context, array $input,
	                                  RadicalMartExpressPaymentModel|RadicalMartPaymentModel $model, Registry $params): void
	{
		$debug    = false;
		$contents = false;

		$debugger     = 'payment.callback';
		$debuggerFile = 'site_payment_controller.php';
		$this->componentDebug($context, 'addDebug', [$debugger, $debuggerFile, 'Request in plugin']);
		$this->componentDebug($context, 'addDebugHead', [$debugger,
			[
				'TransactionId' => (!empty($input['TransactionId'])) ? $input['TransactionId'] : null,
				'Event'         => (!empty($input['Payment'])) ? $input['Payment'] : null,
				'OrderId'       => (!empty($input['OrderId'])) ? $input['OrderId'] : null,
			]]);
		try
		{
			// Get Transaction id
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Check input data', 'start']);
			if (empty($input['TransactionId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'));
			}
			$transaction_id = $input['TransactionId'];

			// Check event
			$app = $this->getApplication();
			if (empty($input['Event']) || $input['Event'] !== 'Payment')
			{
				LogHelper::addWarning([
					'context'    => $context,
					'message'    => 'Incorrect input event',
					'input_data' => $input,
				]);

				$this->componentDebug($context, 'addDebug',
					[$debugger, $debuggerFile, $debugAction, 'error', 'Incorrect payment status']);


				$app->close(200);
			}

			// Check order id
			if (empty($input['OrderId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_ORDER_NOT_FOUND'));
			}
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'success', null, [], null, false]);

			// Get order
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Get order', 'start']);
			if (!$order = $model->getOrder($input['OrderId']))
			{
				$messages = [];
				foreach ($model->getErrors() as $error)
				{
					$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
				}

				if (empty($messages))
				{
					$messages[] = Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_ORDER_NOT_FOUND');
				}

				throw new \Exception(implode(PHP_EOL, $messages), 404);
			}
			$this->componentDebug($context, 'addDebugHead', [$debugger, ['order_id' => $order->id]]);
			$this->componentDebug($context, 'addDebug', [$debugger, $debuggerFile, $debugAction, 'success']);

			// Check order payment method
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Check payment method', 'start']);
			if (empty($order->payment)
				|| empty($order->payment->id)
				|| empty($order->payment->plugin)
				|| $order->payment->plugin !== 'payselection')
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_METHOD_NOT_FOUND'));
			}

			// Get method params
			$params = $this->getMethodParams($context, $order->payment->id);
			if (empty($params))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Set Debug
			if ((int) $params->get('debug_payment_callback', 0) === 1)
			{
				$debug = 'payment.callback';
			}

			// Check order status
			if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
			{
				LogHelper::addWarning([
					'context'       => $context,
					'message'       => 'Incorrect order status',
					'input_data'    => $input,
					'response_data' => $contents
				]);
				$this->componentDebug($context, 'addDebug',
					[$debugger, $debuggerFile, $debugAction, 'error', 'Incorrect order status']);

				$app->close(200);

				return;
			}
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'success', null, [], null, false]);

			// Check access
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Send api request', 'start']);
			if (empty($params->get('api_id')) || empty($params->get('api_secret')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Prepare request data
			$request_path      = '/transactions/' . $transaction_id . '/extended';
			$request_url       = 'https://gw.payselection.com' . $request_path;
			$request_data      = '';
			$request_id        = md5(serialize([$request_path, Uri::getInstance()->getHost(), $request_data, time()]));
			$request_signature = hash_hmac('sha256', implode(PHP_EOL, [
				'GET',
				$request_path,
				$params->get('api_id'),
				$request_id,
				$request_data
			]), $params->get('api_secret'));
			$request_headers   = [
				'Content-Type'        => 'application/json',
				'X-SITE-ID'           => $params->get('api_id'),
				'X-REQUEST-ID'        => $request_id,
				'X-REQUEST-SIGNATURE' => $request_signature
			];

			// Send request
			$http = new Http();
			$http->setOption('transport.curl', [
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_SSL_VERIFYPEER => 0
			]);
			$response      = $http->get($request_url, $request_headers);
			$response_body = $response->body;
			if (empty($response_body))
			{
				$message = preg_replace('#^[0-9]*\s#', '', $response->headers['Status']);

				throw new \Exception($message, $response->code);
			}
			$this->componentDebug($context, 'addDebug', [$debugger, $debuggerFile, $debugAction, 'success']);

			// Parse response
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction = 'Parse api response', 'start']);
			$contents = json_decode($response_body);
			if ($response->code !== 200)
			{
				throw new \Exception($contents->Code, $response->code);
			}

			// Check order
			if ($contents->OrderId !== $order->number)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PARTPAY_ERROR_ORDER_NOT_FOUND'));
			}

			// Check transaction state
			if ($contents->TransactionState !== 'success')
			{
				LogHelper::addWarning([
					'context'       => $context,
					'message'       => 'Incorrect Transaction State',
					'input_data'    => $input,
					'response_data' => $contents
				]);

				$this->componentDebug($context, 'addDebug',
					[$debugger, $debuggerFile, $debugAction, 'response', 'Incorrect Transaction State']);

				$app->close(200);

				return;
			}
			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'success', null, [], null, false]);

			// Add log
			$addLog = true;
			foreach ($order->logs as $log)
			{
				if ($log['action'] === 'payselection_paid' && $log['transaction_id'] === $transaction_id)
				{
					$addLog = false;
					break;
				}
			}
			if ($addLog)
			{
				$this->componentDebug($context, 'addDebug',
					[$debugger, $debuggerFile, $debugAction = 'Add order log', 'start']);

				$model->addLog($order->id, 'payselection_paid', [
					'plugin'         => 'payselection',
					'group'          => 'radicalmart_payment',
					'transaction_id' => $transaction_id,
					'user_id'        => -1
				]);

				$this->componentDebug($context, 'addDebug',
					[$debugger, $debuggerFile, $debugAction, 'success', null, [], null, false]);
			}

			// Set paid status
			$paidStatus = (int) $params->get('paid_status', 0);
			if (!empty($paidStatus))
			{
				$this->componentDebug($context, 'addDebug',
					[$debugger, $debuggerFile, $debugAction = 'Change order status', 'start']);

				if (!$model->updateStatus($order->id, $paidStatus, false, -1))
				{
					$messages = [];
					foreach ($model->getErrors() as $error)
					{
						$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
					}

					throw new \Exception(implode(PHP_EOL, $messages));
				}

				$this->componentDebug($context, 'addDebug', [$debugger, $debuggerFile, $debugAction, 'sucess']);
			}

			$this->componentDebug($context, 'addDebug', [$debugger, $debuggerFile, 'Callback response', 'response']);

			$app->close(200);
		}
		catch (\Exception $e)
		{
			$debugData = [
				'context'       => $context,
				'message'       => $e->getMessage(),
				'error_code'    => $e->getCode(),
				'input_data'    => $input,
				'response_data' => $contents
			];
			if ($debug)
			{
				LogHelper::addDebug($debug, $debugData, true);
			}
			LogHelper::addError($debugData);

			$this->componentDebug($context, 'addDebug',
				[$debugger, $debuggerFile, $debugAction, 'error', $e->getMessage()]);

			throw new \Exception('Payselection: ' . $e->getMessage(), 500, $e);
		}

		$app->close(200);
	}

	/**
	 * Method to get payment method params.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   int     $pk       Payment method id.
	 *
	 * @return false|Registry Method params registry object on success, False on failure.
	 *
	 * @since 2.0.0
	 */
	protected function getMethodParams(string $context, int $pk): false|Registry
	{
		if (strpos($context, 'com_radicalmart.') !== false)
		{
			return RadicalMartParamsHelper::getPaymentMethodsParams($pk);
		}
		elseif (strpos($context, 'com_radicalmart_express.') !== false)
		{
			$params = RadicalMartExpressParamsHelper::getPaymentMethodsParams($pk);

			$params->set('payment_available', [1]);
			$params->set('paid_status', 2);

			return $params;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Routing method to call component debug helper method if canned.
	 *
	 * @param   string|null  $context  Context selector string.
	 * @param   string|null  $method   Helper method name.
	 * @param   array|null   $args     Method arguments array.
	 *
	 * @since 2.1.1
	 */
	protected function componentDebug(?string $context = null, ?string $method = null, ?array $args = []): void
	{
		if (empty($context) || empty($method))
		{
			return;
		}
		if (!is_array($args))
		{
			$args = array_values((new Registry($args))->toArray());
		}

		$debugHelper = (strpos($context, 'com_radicalmart.') !== false) ? RadicalMartDebugHelper::class : false;
		if (!$debugHelper)
		{
			return;
		}

		call_user_func_array([$debugHelper, $method], $args);
	}
}