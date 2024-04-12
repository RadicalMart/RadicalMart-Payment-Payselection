<?php
/*
 * @package     RadicalMart Payment Payselection Plugin
 * @subpackage  plg_radicalmart_payment_payselection
 * @version     __DEPLOY_VERSION__
 * @author      RadicalMart Team - radicalmart.ru
 * @copyright   Copyright (c) 2024 RadicalMart. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://radicalmart.ru/
 */

namespace Joomla\Plugin\RadicalMartPayment\Payselection\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Environment\Browser;
use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;
use Joomla\Utilities\IpHelper;

class LogHelper
{
	/**
	 * Extension name.
	 *
	 * @var string
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected static string $extension = 'plg_radicalmart_payment_payselection';

	/**
	 * Logger instance.
	 *
	 * @var array|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected static ?array $_logger = null;

	/**
	 * Method to add a logger to the Log instance.
	 *
	 * @param   string|null  $context  Context selector string.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public static function addLogger(?string $context = null)
	{
		if (self::$_logger === null)
		{
			self::$_logger = [];
		}

		$category = self::getCategory($context);
		if (!isset(self::$_logger[$category]))
		{
			Log::addLogger([
				'text_file'         => $category . '.php',
				'text_entry_format' => "{DATETIME}\t{CLIENTIP}\t{MESSAGE}\t{PRIORITY}"],
				Log::ALL,
				[$category]
			);

			self::$_logger[$category] = true;
		}
	}

	/**
	 * Method to get logger category.
	 *
	 * @param   string|null  $context  Context selector string.
	 *
	 * @return string Logger category.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public static function getCategory(?string $context = null): string
	{
		$result = self::$extension;
		if (!empty($context))
		{
			$result .= '.' . $context;
		}

		return $result;
	}

	/**
	 * Method to add log debug entry.
	 *
	 * @param   string|null  $selector  Log selector name.
	 * @param   array|null   $data      Advanced data.
	 * @param   bool         $error     Is error entry.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public static function addDebug(?string $selector = null, ?array $data = [], bool $error = false)
	{
		self::addLogger('debug.' . $selector);
		$category = self::getCategory('debug.' . $selector);
		$entry    = self::prepareEntry($data);

		Log::add($entry, ($error) ? Log::ERROR : Log::INFO, $category);
	}

	/**
	 * Method to add log warning entry.
	 *
	 * @param   array|null  $data  Entry data.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public static function addWarning(?array $data = [])
	{
		self::addLogger('warning');
		$category = self::getCategory('warning');
		$entry    = self::prepareEntry($data);

		Log::add($entry, Log::WARNING, $category);
	}

	/**
	 * Method to add log error entry.
	 *
	 * @param   array|null  $data  Entry data.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public static function addError(?array $data = [])
	{
		self::addLogger('error');
		$category = self::getCategory('error');
		$entry    = self::prepareEntry($data);

		Log::add($entry, Log::ERROR, $category);
	}

	/**
	 * Method to prepare log entry record.
	 *
	 * @param   array|null  $data  Entry data.
	 *
	 * @return string Log entry string.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public static function prepareEntry(?array $data = []): string
	{
		$entry = [];
		if (isset($data['context']))
		{
			$entry['context'] = $data['context'];
			unset($data['context']);
		}

		$entry['time'] = (new Date((string) time()))->format('Y-m-d H:i:s');

		if (isset($data['message']))
		{
			$entry['message'] = $data['message'];
			unset($data['message']);
		}

		$entry['ip']         = IpHelper::getIp();
		$entry['user_agent'] = Browser::getInstance()->getAgentString();

		$entry['data'] = $data;

		return (new Registry($entry))->toString();
	}
}