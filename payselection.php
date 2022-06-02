<?php
/*
 * @package     RadicalMart Package
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
use Joomla\Http\Response;
use Joomla\Registry\Registry;

class plgRadicalMart_PaymentPayselection extends CMSPlugin
{
	/**
	 * Loads the application object.
	 *
	 * @var  CMSApplication
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected $app = null;

	/**
	 * Loads the database object.
	 *
	 * @var  JDatabaseDriver
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected $db = null;

	/**
	 * Affects constructor behavior.
	 *
	 * @var  boolean
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected $autoloadLanguage = true;

	/**
	 * Payment method params.
	 *
	 * @var  Registry
	 *
	 * @since   __DEPLOY_VERSION__
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
	 * @since  __DEPLOY_VERSION__
	 */
	public function onRadicalMartGetPaymentMethods($context, $method, $formData, $products, $currency)
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
		$method->order->price       = array();
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
	 * @since  __DEPLOY_VERSION__
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
		if (empty($params->get('api_id')) || empty($params->get('api_secret'))) return false;

		// Check order status
		if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', array())))
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
	 * @since  __DEPLOY_VERSION__
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
	 * Method to create order in RadicalMart.
	 *
	 * @param   object    $order   Order data object.
	 * @param   Registry  $params  RadicalMart component params.
	 * @param   array     $links   RadicalMart plugin links.
	 *
	 * @throws  Exception
	 *
	 * @return  array  Payment redirect data on success.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onRadicalMartPay($order, $links, $params)
	{
		$result = array(
			'pay_instant' => true,
			'link'        => false,
		);

		// Check order payment method
		if (empty($order->payment)
			|| empty($order->payment->id)
			|| empty($order->payment->plugin)
			|| $order->payment->plugin !== 'payselection') return $result;

		// Get method params
		$params = $this->getPaymentMethodParams($order->payment->id);
		if (empty($params->get('api_id')) || empty($params->get('api_secret')))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'), 500);
		}

		// Check order status
		if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', array())))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_PAYMENT_NOT_AVAILABLE'), 500);
		}

		// Prepare data
		$data = array(
			'MetaData'       => array(
				'PaymentType' => "Pay"
			),
			'PaymentRequest' => array(
				'OrderId'     => $order->number,
				'Amount'      => (string) $order->total['final'],
				'Currency'    => $order->currency['code'],
				'Description' => $order->title,
				'RebillFlag'  => false,
				'ExtraData'   => array(
					'ReturnUrl' => $links['success'] . '/' . $order->number
				)
			),
		);

		// Create transaction request
		$response = $this->sendRequest('webpayments/create', $data, array(
			'api_id'     => $params->get('api_id'),
			'api_secret' => $params->get('api_secret'),
		));
		$body     = $response->body;
		$context  = (!empty($body)) ? new Registry($response->body) : false;
		if (!$context)
		{
			$message = preg_replace('#^[0-9]*\s#', '', $response->headers['Status']);
			throw new Exception('Payselection: ' . $message, $response->code);
		}
		elseif ($response->code === 201)
		{
			$result['link'] = $context->get('scalar');
		}
		elseif ($response->code === 409)
		{
			$result['link'] = $context->get('AddDetails')->URL;
		}
		else
		{
			throw new Exception('Payselection: ' . $context->get('Code'), $response->code);
		}

		return $result;
	}

	/**
	 * Method to send api request.
	 *
	 * @param   string  $method  The api method name.
	 * @param   array   $data    Request data.
	 * @param   array   $access  Access params.
	 *
	 * @throws Exception
	 *
	 * @return  Response  Response object on success.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected function sendRequest($method = null, $data = array(), $access = array())
	{
		// Check method
		$method = trim($method, '/');
		if (empty($method)) throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_METHOD_NOT_FOUND'));

		// Check access
		if (empty($access['api_id']) || empty($access['api_secret']))
		{
			throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_EMPTY_PAYMENTS_METHOD_PARAMS'), 500);
		}

		// Convert data
		if (!is_array($data)) $data = (new Registry($data))->toArray();
		$data = json_encode($data);

		// Prepare request
		$url               = 'https://webform.payselection.com/' . $method;
		$request_id        = md5($method . '_' . $data);
		$request_signature = hash_hmac('sha256',
			implode(PHP_EOL, array('POST',
				'/' . $method,
				$access['api_id'],
				$request_id,
				$data))
			, $access['api_secret'], false);
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

		return $http->post($url, $data, $headers);
	}
}