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

namespace Joomla\Plugin\RadicalMartPayment\Payselection\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\ModelInterface;
use Joomla\Component\RadicalMart\Administrator\Helper\DebugHelper as RadicalMartDebugHelper;
use Joomla\Component\RadicalMart\Administrator\Helper\LayoutsHelper as RadicalMartLayoutsHelper;
use Joomla\Component\RadicalMart\Administrator\Helper\ParamsHelper as RadicalMartParamsHelper;
use Joomla\Component\RadicalMart\Administrator\Helper\PluginsHelper as RadicalMartPluginsHelper;
use Joomla\Component\RadicalMart\Administrator\Helper\UserHelper as RadicalMartUserHelper;
use Joomla\Component\RadicalMart\Administrator\Model\OrderModel as RadicalMartOrderModel;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\DebugHelper as RadicalMartExpressDebugHelper;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\LayoutsHelper as RadicalMartExpressLayoutsHelper;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\ParamsHelper as RadicalMartExpressParamsHelper;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\PluginsHelper as RadicalMartExpressPluginsHelper;
use Joomla\Component\RadicalMartExpress\Administrator\Helper\UserHelper as RadicalMartExpressUserHelper;
use Joomla\Component\RadicalMartExpress\Administrator\Model\OrderModel as RadicalMartExpressOrderModel;
use Joomla\Registry\Registry;

class IntegrationHelper
{
	public const RadicalMart = 'com_radicalmart';
	public const RadicalMartExpress = 'com_radicalmart_express';

	/**
	 * Loggers initials cache.
	 *
	 * @var array
	 *
	 * @since 2.3.0
	 */
	protected static array $_loggers = [];

	/**
	 * Method to get component from form name
	 *
	 * @param   Form|null  $form  Joomla form object.
	 *
	 * @return string|bool Component name on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getComponentFromForm(?Form $form = null): string|bool
	{
		if (empty($form))
		{
			return false;
		}

		$formName = $form->getName();
		if (empty($formName))
		{
			return false;
		}

		if (str_starts_with($formName, 'com_radicalmart.'))
		{
			return self::RadicalMart;
		}

		if (str_starts_with($formName, 'com_radicalmart_express.'))
		{
			return self::RadicalMartExpress;
		}

		return false;
	}

	/**
	 * Method to get component from form context.
	 *
	 * @param   string|null  $context  Context selector string.
	 *
	 * @return string|bool Component name on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getComponentFromContext(?string $context = null): string|bool
	{
		if (empty($context))
		{
			return false;
		}

		if (str_starts_with($context, 'com_radicalmart.'))
		{
			return self::RadicalMart;
		}

		if (str_starts_with($context, 'com_radicalmart_express.'))
		{
			return self::RadicalMartExpress;
		}

		return false;
	}

	/**
	 * Method to get DebugHelper class name.
	 *
	 * @param   string  $component  Component name.
	 *
	 * @return string|bool|RadicalMartDebugHelper|RadicalMartExpressDebugHelper Helper class name on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getDebugHelper(string $component): bool|string
	{
		if ($component === self::RadicalMart)
		{
			return RadicalMartDebugHelper::class;
		}

		if ($component === self::RadicalMartExpress)
		{
			return RadicalMartExpressDebugHelper::class;
		}

		return false;
	}

	/**
	 * Method to get LayoutsHelper class name.
	 *
	 * @param   string  $component  Component name.
	 *
	 * @return string|bool|RadicalMartLayoutsHelper|RadicalMartExpressLayoutsHelper Helper class name on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getLayoutsHelper(string $component): bool|string
	{
		if ($component === self::RadicalMart)
		{
			return RadicalMartLayoutsHelper::class;
		}

		if ($component === self::RadicalMartExpress)
		{
			return RadicalMartExpressLayoutsHelper::class;
		}

		return false;
	}

	/**
	 * Method to get ParamsHelper class name.
	 *
	 * @param   string  $component  Component name.
	 *
	 * @return string|bool|RadicalMartParamsHelper|RadicalMartExpressParamsHelper Helper class name on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getParamsHelper(string $component): bool|string
	{
		if ($component === self::RadicalMart)
		{
			return RadicalMartParamsHelper::class;
		}

		if ($component === self::RadicalMartExpress)
		{
			return RadicalMartExpressParamsHelper::class;
		}

		return false;
	}

	/**
	 * Method to get PluginsHelper class name.
	 *
	 * @param   string  $component  Component name.
	 *
	 * @return string|bool|RadicalMartPluginsHelper|RadicalMartExpressPluginsHelper Helper class name on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getPluginsHelper(string $component): bool|string
	{
		if ($component === self::RadicalMart)
		{
			return RadicalMartPluginsHelper::class;
		}

		if ($component === self::RadicalMartExpress)
		{
			return RadicalMartExpressPluginsHelper::class;
		}

		return false;
	}

	/**
	 * Method to get UserHelper class name.
	 *
	 * @param   string  $component  Component name.
	 *
	 * @return string|bool|RadicalMartUserHelper|RadicalMartExpressUserHelper Helper class name on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getUserHelper(string $component): bool|string
	{
		if ($component === self::RadicalMart)
		{
			return RadicalMartUserHelper::class;
		}

		if ($component === self::RadicalMartExpress)
		{
			return RadicalMartExpressUserHelper::class;
		}

		return false;
	}

	/**
	 * Method to get component model.
	 *
	 * @param   string  $component
	 * @param   string  $name    The name of the model.
	 * @param   string  $prefix  Optional model prefix.
	 * @param   array   $config  Optional configuration array for the model.
	 *
	 * @throws \Exception
	 *
	 * @return ModelInterface|bool Component model object instanse on success, False on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getModel(string $component, string $name, string $prefix = '',
	                                array  $config = ['ignore_request' => true]): bool|ModelInterface
	{
		/** @var SiteApplication|AdministratorApplication $app */
		$app = Factory::getApplication();
		if ($component === self::RadicalMart)
		{
			$boot = $app->bootComponent('com_radicalmart');
		}
		elseif ($component === self::RadicalMartExpress)
		{
			$boot = $app->bootComponent('com_radicalmart_express');
		}
		else
		{
			return false;
		}

		return $boot->getMVCFactory()->createModel($name, $prefix, $config);
	}

	/**
	 * Method to get Admin order model.
	 *
	 * @param   string  $component  Component name.
	 *
	 * @throws \Exception
	 *
	 * @return bool|RadicalMartOrderModel|RadicalMartExpressOrderModel|null Admin order model on success, False or null on failure.
	 *
	 * @since 2.3.0
	 */
	public static function getOrderModel(string $component): bool|null|RadicalMartOrderModel|RadicalMartExpressOrderModel
	{
		return self::getModel($component, 'Order', 'Administrator');
	}

	/**
	 * Method to add order logs.
	 *
	 * @param   string  $component  Component name.
	 * @param   int     $order_id   Order id.
	 * @param   array   $logs       Logs data array.
	 *
	 * @throws \Exception
	 *
	 * @since 2.3.0
	 */
	public static function addOrderLogs(string $component, int $order_id, array $logs): void
	{
		$model = self::getOrderModel($component);
		if (!$model)
		{
			return;
		}

		if ($component === self::RadicalMart)
		{
			$model->addLogs($order_id, $logs);

			return;
		}

		foreach ($logs as $log)
		{
			if (empty($log['action']))
			{
				continue;
			}
			$action = $log['action'];
			unset($log['action']);

			$model->addLog($order_id, $action, $log);
		}
	}

	/**
	 * Method to add order log.
	 *
	 * @param   string  $component  Component name.
	 * @param   int     $order_id   Order id.
	 * @param   string  $action     Action name.
	 * @param   array   $data       Log data.
	 *
	 * @throws \Exception
	 *
	 * @since 2.3.0
	 */
	public static function addOrderLog(string $component, int $order_id, string $action, array $data): void
	{
		$model = self::getOrderModel($component);
		if (!$model)
		{
			return;
		}

		$model->addLog($order_id, $action, $data);
	}

	/**
	 * Method to log error.
	 *
	 * @param   string       $category  Log category name.
	 * @param   int          $priority  Message priority.
	 * @param   string|null  $message   Message text.
	 * @param   array        $data      Message advanced data.
	 * @param   int          $code      Message code.
	 *
	 * @since 2.3.0
	 */
	public static function addLog(string  $category, int $priority = Log::INFO,
	                              ?string $message = null, array $data = [], int $code = 0): void
	{
		if (!isset(self::$_loggers[$category]))
		{
			Log::addLogger([
				'text_file'         => $category . '.php',
				'text_entry_format' => "{DATETIME}\t{CLIENTIP}\t{MESSAGE}\t{PRIORITY}"],
				Log::ALL,
				[$category]
			);

			self::$_loggers[$category] = true;
		}

		if (!empty($data))
		{
			$entry = [];
			if (!empty($code))
			{
				$entry['code'] = $code;
			}

			if (!empty($message))
			{
				$entry['message'] = $message;
			}

			$entry['data'] = $data;

			$entry = (new Registry($entry))->toString();
		}

		else
		{
			$entry = (!empty($message)) ? $message : '';
		}


		Log::add($entry, $priority, $category);
	}
}