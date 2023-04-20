<?php
/*
 * @package     RadicalMart Payment Payselection Plugin
 * @subpackage  plg_radicalmart_payment_payselection
 * @version     __DEPLOY_VERSION__
 * @author      Delo Design - delo-design.ru
 * @copyright   Copyright (c) 2023 Delo Design. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://delo-design.ru/
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
use Joomla\Component\RadicalMart\Administrator\Helper\ParamsHelper as RadicalMartParamsHelper;
use Joomla\Component\RadicalMart\Site\Model\PaymentModel as RadicalMartPaymentModel;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\ParamsHelper as RadicalMartExpressParamsHelper;
use Joomla\Component\RadicalMartExpress\Site\Model\PaymentModel as RadicalMartExpressPaymentModel;
use Joomla\Event\SubscriberInterface;
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
	 * Loads the application object.
	 *
	 * @var  \Joomla\CMS\Application\CMSApplication
	 *
	 * @since  1.2.0
	 */
	protected $app = null;

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
	public function onRadicalMartPrepareMethodForm(Form $form, $data = [], $tmpData = [])
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
	public function onRadicalMartExpressPrepareConfigForm(Form $form, $data = [])
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
	                                         array  $products, array $currency)
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
	public function onGetOrderLogs(string $context, array &$log)
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
	 * @param   object    $order   Order data object.
	 * @param   array     $links   Plugin links.
	 * @param   Registry  $params  Component params.
	 *
	 * @throws  \Exception
	 *
	 * @return  array  Payment redirect data on success.
	 *
	 * @since  2.0.0
	 */
	public function onPaymentPay(string $context, object $order, array $links, Registry $params): array
	{
		try
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
			$params = $this->getMethodParams($context, $order->payment->id);
			if (!$params)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Check access
			if (empty($params->get('api_id')) || empty($params->get('api_secret')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Check order status
			if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_PAYMENT_NOT_AVAILABLE'));
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

				// Add products
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

			// Convert data
			$data = json_encode($data);

			// Prepare request
			$url               = 'https://webform.payselection.com/webpayments/create';
			$site              = Uri::getInstance()->getHost();
			$request_id        = md5('createTransaction' . '_' . $site . '_' . $data);
			$request_signature = hash_hmac('sha256',
				implode(PHP_EOL, [
					'POST',
					'/webpayments/create',
					$params->get('api_id'),
					$request_id,
					$data])
				, $params->get('api_secret')
			);

			$headers = [
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
			$response = $http->post($url, $data, $headers);
			$body     = $response->body;
			if (empty($body))
			{
				$message = preg_replace('#^[0-9]*\s#', '', $response->headers['Status']);

				throw new \Exception('Payselection: ' . $message, $response->code);
			}

			$context = json_decode($body);
			if ($response->code === 201)
			{
				$link = $context;
			}
			elseif ($response->code === 409)
			{
				$link = $context->AddDetails->URL;
			}
			else
			{
				throw new \Exception('Payselection: ' . $context->Code, $response->code);
			}

			$result['link'] = $link;

			return $result;
		}
		catch (\Exception $e)
		{
			throw new \Exception('Payselection: ' . $e->getMessage(), $e->getCode());
		}
	}

	/**
	 * Method to set RadicalMart & RadicalMartExpress order pay status after payment.
	 *
	 * @param   array                                                    $input   Input data.
	 * @param   RadicalMartPaymentModel| RadicalMartExpressPaymentModel  $model   RadicalMart model.
	 * @param   Registry                                                 $params  RadicalMart params.
	 *
	 * @throws \Exception
	 *
	 * @since  2.0.0
	 */
	public function onPaymentCallback(string $context, array $input, $model, Registry $params)
	{
		// Add logger
		Log::addLogger([
			'text_file'         => 'plg_radicalmart_payment_payselection.php',
			'text_entry_format' => "{DATETIME}\t{CLIENTIP}\t{MESSAGE}\t{PRIORITY}"],
			Log::ALL, ['plg_radicalmart_payment_payselection']);

		try
		{
			if (empty($input['TransactionId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'));
			}

			// Get order
			if (empty($input['OrderId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_ORDER_NOT_FOUND'));
			}

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

			// Check order payment method
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
			if (empty($params->get('api_id')) || empty($params->get('api_secret')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'));
			}

			// Check order status
			if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
			{
				$this->app->close(200);

				return;
			}

			// Get transaction
			$transaction_id    = $input['TransactionId'];
			$url               = 'https://gw.payselection.com/transactions/' . $transaction_id;
			$site              = Uri::getInstance()->getHost();
			$request_id        = md5('getTransaction' . '_' . $site . '_' . $transaction_id);
			$request_signature = hash_hmac('sha256',
				implode(PHP_EOL, [
					'GET',
					'/transactions/' . $transaction_id,
					$params->get('api_id'),
					$request_id,
					''])
				, $params->get('api_secret'));
			$headers           = [
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

			// Parse request
			$response = $http->get($url, $headers);
			$body     = $response->body;
			if (empty($body))
			{
				$message = preg_replace('#^[0-9]*\s#', '', $response->headers['Status']);
				throw new \Exception($message, $response->code);
			}

			$context = new Registry($body);
			if ($response->code === 200)
			{
				$transaction = $context;
			}
			elseif ($response->code === 404)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_TRANSACTION_NOT_FOUND'), 404);
			}
			else
			{
				throw new \Exception($context->get('Code'), $response->code);
			}
			$transaction_id = str_replace(['PS00000', 'GE00000'], '', $transaction->get('TransactionId'));

			// Get order
			if ($transaction->get('OrderId') !== $order->number)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_ORDER_NOT_FOUND'));
			}

			// Check transaction state
			if ($transaction->get('TransactionState') !== 'success')
			{
				$this->app->close(200);

				return;
			}

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
				$model->addLog($order->id, 'payselection_paid', [
					'plugin'         => 'payselection',
					'group'          => 'radicalmart_payment',
					'transaction_id' => $transaction_id,
					'user_id'        => -1
				]);
			}

			// Set paid status
			$paidStatus = (int) $params->get('paid_status', 0);
			if (!empty($paidStatus))
			{
				if (!$model->updateStatus($order->id, $paidStatus, false, -1))
				{
					$messages = [];
					foreach ($model->getErrors() as $error)
					{
						$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
					}

					throw new \Exception(implode(PHP_EOL, $messages));
				}
			}

			$this->app->close(200);
		}
		catch (\Exception $e)
		{
			Log::add($e->getMessage(), Log::ERROR, 'plg_radicalmart_payment_payselection');

			throw new \Exception('Payselection: ' . $e->getMessage(), 500);
		}

		$this->app->close(200);
	}

	/**
	 * Method to get payment method params.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   int     $pk       Payment method id.
	 *
	 * @return false|Registry Method prams registry object on success, False on failure.
	 *
	 * @since 2.0.0
	 */
	protected function getMethodParams(string $context, int $pk)
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
}