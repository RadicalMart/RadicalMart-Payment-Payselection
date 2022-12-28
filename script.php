<?php
/*
 * @package     RadicalMart Payment Payselection Plugin
 * @subpackage  plg_radicalmart_payment_payselection
 * @version     1.1.0
 * @author      Delo Design - delo-design.ru
 * @copyright   Copyright (c) 2022 Delo Design. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://delo-design.ru/
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;

class PlgRadicalMart_PaymentPayselectionInstallerScript
{

	/**
	 * Runs right before any installation action.
	 *
	 * @param   string            $type    Type of PostFlight action.
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @throws  Exception
	 *
	 * @return  boolean True on success, False on failure.
	 *
	 * @since  1.0.1
	 */
	function preflight($type, $parent)
	{
		// Change update servers
		if ($type === 'update')
		{
			$this->changeUpdateServer();
		}

		return true;
	}

	/**
	 * Runs right after any installation action.
	 *
	 * @param   string            $type    Type of PostFlight action. Possible values are:
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @throws  Exception
	 *
	 * @return  boolean True on success, False on failure.
	 *
	 * @since   1.0.0
	 */
	function postflight($type, $parent)
	{
		// Enable plugin
		if ($type == 'install') $this->enablePlugin($parent);

		return true;
	}

	/**
	 * Enable plugin after installation.
	 *
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @since   1.0.0
	 */
	protected function enablePlugin($parent)
	{
		// Prepare plugin object
		$plugin          = new stdClass();
		$plugin->type    = 'plugin';
		$plugin->element = $parent->getElement();
		$plugin->folder  = (string) $parent->getParent()->manifest->attributes()['group'];
		$plugin->enabled = 1;

		// Update record
		Factory::getDbo()->updateObject('#__extensions', $plugin, array('type', 'element', 'folder'));
	}

	/**
	 * Method to change update server.
	 *
	 * @since 1.0.1
	 */
	protected function changeUpdateServer()
	{
		$old = 'https://radicalmart.ru/update?element=plg_radicalmart_payment_payselection';
		$new = 'https://sovmart.ru/update?element=plg_radicalmart_payment_payselection';

		$db    = Factory::getDbo();
		$query = $db->getQuery(true)
			->select(['update_site_id', 'location'])
			->from($db->quoteName('#__update_sites'))
			->where($db->quoteName('location') . ' LIKE ' .
				$db->quote($old));
		if ($update = $db->setQuery($query)->loadObject())
		{
			$update->location = $new;
			$db->updateObject('#__update_sites', $update, 'update_site_id');
		}
	}
}