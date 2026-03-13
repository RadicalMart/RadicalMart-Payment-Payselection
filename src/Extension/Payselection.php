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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\RadicalMart\Administrator\Helper\ParamsHelper as RadicalMartParamsHelper;
use Joomla\Component\RadicalMart\Site\Model\PaymentModel as RadicalMartPaymentModel;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\ParamsHelper as RadicalMartExpressParamsHelper;
use Joomla\Component\RadicalMartExpress\Site\Model\PaymentModel as RadicalMartExpressPaymentModel;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\HttpFactory;
use Joomla\Plugin\RadicalMartPayment\Payselection\Helper\IntegrationHelper;
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
	 * Extension name.
	 *
	 * @var string
	 *
	 * @since 2.3.0
	 */
	public string $extension = 'plg_radicalmart_payment_payselection';

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
		if (str_contains($context, 'com_radicalmart_express.'))
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
		if (!str_contains($log['action'], 'payselection'))
		{
			return;
		}

		$event              = str_replace('payselection_', '', $log['action']);
		$log['action_text'] = Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_LOGS_' . $event);

		if ($event === 'pay_error' || $event === 'callback_error')
		{
			$log['message'] = (!empty($log['error_message'])) ? $log['error_message'] : '';

			return;
		}

		if ($log['action'] === 'payselection_paid' && !empty($log['TransactionId']))
		{
			$log['message'] = Text::sprintf('PLG_RADICALMART_PAYMENT_PAYSELECTION_LOGS_PAID_MESSAGE',
				$log['TransactionId']);
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

		// Check component
		$component = IntegrationHelper::getComponentFromContext($context);
		if (!$component)
		{
			return false;
		}

		// Get method params
		$params = $this->getPaymentMethodParams($component, $order->payment->id);

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
		$component    = false;
		$debug        = false;
		$debugger     = 'payment.pay';
		$debuggerFile = 'site_payment_controller.php';
		$debugAction  = 'Init plugin';
		$debugData    = [
			'context' => $context,
		];

		try
		{
			$component = IntegrationHelper::getComponentFromContext($context);
			if (!$component)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_COMPONENT'), 500);
			}

			// Check order payment plugin
			$debug = IntegrationHelper::getDebugHelper($component);
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method plugin', 'start',
				null, null, null, false);
			if (!$this->checkOrderPaymentPlugin($order))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_PLUGIN'), 500);
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

			// Get params
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Get payment method params', 'start',
				null, null, null, false);
			$params = $this->getPaymentMethodParams($component, $order->payment->id);
			if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_PAYMENT_NOT_AVAILABLE'));
			}

			if (empty($params->get('api_id')) || empty($params->get('api_secret')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_API_ACCESS'), 403);
			}

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

			// Prepare request data
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Prepare api request data', 'start',
				null, null, null, false);

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

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success');

			// Send request
			$debugData = [
				'request_url'     => $request_url,
				'request_data'    => $data,
				'request_headers' => $request_headers,
			];

			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send api request', 'start', null, $debugData);

			$http = (new HttpFactory)->getHttp(['transport.curl' => [
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_SSL_VERIFYPEER => 0
			]]);

			$response = $http->post($request_url, $request_data, $request_headers);

			// Parse response
			$code    = $response->getStatusCode();
			$message = $response->getReasonPhrase();
			$body    = (string) $response->getBody();
			if (empty($body))
			{
				throw new \Exception($message, $code);
			}

			$contents = json_decode($body);
			if ($code === 201)
			{
				$link = $contents;
			}
			elseif ($code === 409)
			{
				$link = $contents->AddDetails->URL;
			}
			else
			{
				throw new \Exception($contents->Code, $code);
			}

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'response_data' => $contents,
			]);

			$log = [
				'plugin' => $this->_name,
				'group'  => $this->_type,
			];

			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Add order log', 'start',
				null, null, null, false);
			IntegrationHelper::addOrderLog($component, $order->id, 'payselection_pay_success', $log);
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, ['order_id' => $order->id]);

			return [
				'pay_instant' => true,
				'link'        => $link,
			];
		}
		catch (\Throwable $e)
		{
			$debugData['error']         = $e->getCode() . ': ' . $e->getMessage();
			$debugData['error_code']    = $e->getCode();
			$debugData['error_message'] = $e->getMessage();

			if ($debug)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error', $debugData['error'], $debugData);
			}

			IntegrationHelper::addLog($this->extension . '.pay.error', Log::ERROR,
				$e->getMessage(), $debugData, $e->getCode());

			if ($component)
			{
				IntegrationHelper::addOrderLog($component, $order->id, 'payselection_pay_error', [
					'plugin'        => $this->_name,
					'group'         => $this->_type,
					'error'         => $debugData['error'],
					'error_code'    => $debugData['error_code'],
					'error_message' => $debugData['error_message'],
				]);
			}

			throw new \Exception('Payselection: ' . $e->getMessage(), $e->getCode(), $e);
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
		$app          = $this->getApplication();
		$component    = false;
		$order_id     = 0;
		$debug        = false;
		$debugger     = 'payment.callback';
		$debuggerFile = 'site_payment_controller.php';
		$debugAction  = 'Init plugin';
		$debugData    = [
			'context' => $context,
		];

		try
		{
			$component = IntegrationHelper::getComponentFromContext($context);
			if (!$component)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_COMPONENT'), 500);
			}

			// Check input data
			$debug     = IntegrationHelper::getDebugHelper($component);
			$debugData = [
				'input' => $input,
			];
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check input data', 'start', null,
				$debugData, null, false);

			if (empty($input['TransactionId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'));
			}
			$transaction_id = $input['TransactionId'];

			if (empty($input['Event']) || $input['Event'] !== 'Payment')
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
					Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_INPUT_STATUS'));

				$app->close(200);
			}

			if (empty($input['OrderId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_ORDER_NOT_FOUND'));
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success');

			// Get site order
			$debugData    = [
				'input_OrderId' => $input['OrderId'],
				'order_number'  => $input['OrderId'],
			];
			$order_number = $input['OrderId'];
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Get order', 'start', null, $debugData);

			if (!$order = $model->getOrder($order_number))
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

				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error', null, [
					'messages' => $messages,
				]);

				throw new \Exception(implode(PHP_EOL, $messages), 500);
			}

			$order_id     = (int) $order->id;
			$order_number = $order->number;

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'order_id'     => $order_id,
				'order_number' => $order->number,
			]);


			// Check order payment method
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method', 'start', null, null,
				null, false);
			if (!$this->checkOrderPaymentPlugin($order))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_PLUGIN'));
			}

			// Check params
			$params = $this->getPaymentMethodParams($component, $order->payment->id);
			if (empty($params->get('api_id')) || empty($params->get('api_secret')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_API_ACCESS'), 403);
			}

			if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
					Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_PAYMENT_NOT_AVAILABLE'));

				$app->close(200);
			}

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

			// Validate request
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

			$debugData = [
				'request_url'     => $request_url,
				'request_headers' => $request_headers,
			];

			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send validate api request', 'start', null, $debugData);

			$http = (new HttpFactory)->getHttp(['transport.curl' => [
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_SSL_VERIFYPEER => 0
			]]);

			$response = $http->get($request_url, $request_headers, 15);

			// Parse response
			$code    = $response->getStatusCode();
			$message = $response->getReasonPhrase();
			$body    = (string) $response->getBody();
			if (empty($body))
			{
				throw new \Exception($message, $code);
			}

			$contents = json_decode($body);
			if ($response->code !== 200)
			{
				throw new \Exception($contents->Code, $code);
			}

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'response_data' => $contents,
			]);


			// Check payment
			$paymentOrderId       = $contents->OrderId;
			$paymentState         = $contents->TransactionState;
			$paymentTransactionId = $contents->TransactionId;

			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment data', 'start', null, [
				'payment_OrderId'        => $paymentOrderId,
				'payment_State'          => $paymentState,
				'payment_TransactionId ' => $paymentTransactionId,
				'order_id'               => $order_id,
				'order_number'           => $order_number,
			]);

			if ($contents->OrderId !== $order->number)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_ORDER_NOT_FOUND'), 403);
			}

			if ($contents->TransactionState !== 'success')
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
					Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_INCORRECT_INPUT_STATUS'));

				$app->close(200);
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

			// Add order log
			$addLog = true;
			foreach ($order->logs as $log)
			{
				if ($log['action'] === 'payselection_paid' && $log['TransactionId'] === $paymentTransactionId)
				{
					$addLog = false;
					break;
				}
			}
			if ($addLog)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Add order log', 'start', null, null, null, false);

				$model->addLog($order->id, 'payselection_paid', [
					'plugin'        => $this->_name,
					'group'         => $this->_type,
					'TransactionId' => $paymentTransactionId,
					'user_id'       => -1
				]);
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);
			}

			// Set paid status
			$paid = (int) $params->get('paid_status', 0);
			if (!empty($paid) && (int) $order->status->id !== $paid)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Change order status', 'start', null, [
					'order_id'      => $order_id,
					'order_number'  => $order_number,
					'new_status_id' => $paid,
				]);

				if (!$model->updateStatus($order->id, $paid, false, -1))
				{
					$messages = [];
					foreach ($model->getErrors() as $error)
					{
						$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
					}

					throw new \Exception(implode(PHP_EOL, $messages));
				}

				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);
			}

		}
		catch (\Throwable $e)
		{
			$debugData['error']         = $e->getCode() . ': ' . $e->getMessage();
			$debugData['error_code']    = $e->getCode();
			$debugData['error_message'] = $e->getMessage();

			if ($debug)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error', $debugData['error'], $debugData);
			}

			IntegrationHelper::addLog($this->extension . '.callback.error', Log::ERROR,
				$e->getMessage(), $debugData, $e->getCode());

			if ($component && $order_id)
			{
				IntegrationHelper::addOrderLog($component, $order_id, 'payselection_callback_error', [
					'plugin'        => $this->_name,
					'group'         => $this->_type,
					'error_code'    => $e->getCode(),
					'error_message' => $e->getMessage(),
				]);

			}

			throw new \Exception('Payselection: ' . $e->getMessage(), 500, $e);
		}

		$app->close(200);
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
	 * Method to check order payment plugin.
	 *
	 * @param   object  $order
	 *
	 * @return bool True if current plugin, false if not.
	 *
	 * @since 2.3.0
	 */
	protected function checkOrderPaymentPlugin(object $order): bool
	{
		return ((!empty($order->payment) && !empty($order->payment->plugin)) && $order->payment->plugin === $this->_name);
	}

	/**
	 * Method to get Payment method params.
	 *
	 * @param   string  $component  Component selector string.
	 * @param   int     $method_id  Payment method id.
	 *
	 * @return Registry Payment method params
	 *
	 * @since 2.3.0
	 */
	protected function getPaymentMethodParams(string $component, int $method_id): Registry
	{
		$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($method_id);

		// Trim params
		foreach (['api_id', 'api_secret'] as $path)
		{
			$params->set($path, trim($params->get($path, '')));
		}

		// Add RadicalMartExpress payment enable statuses
		if ($component === IntegrationHelper::RadicalMartExpress)
		{
			$params->set('payment_available', [1]);
			$params->set('paid_status', 2);
		}

		if (!empty($params->get('promo_codes')))
		{
			if (!is_array($params->get('promo_codes')))
			{
				$codes = [];

				foreach ((new Registry($params->get('promo_codes')))->toArray() as $promo_code)
				{
					$codes[trim($promo_code['promo_value'])] = trim($promo_code['promo_label']);
				}

				$params->set('promo_codes', $codes);
			}
		}

		return $params;
	}
}