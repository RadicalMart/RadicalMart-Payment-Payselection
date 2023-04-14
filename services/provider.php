<?php
/*
 * @package     RadicalMart Payment Payselection Plugin
 * @subpackage  plg_radicalmart_payment_payselection
 * @version     2.0.0
 * @author      Delo Design - delo-design.ru
 * @copyright   Copyright (c) 2023 Delo Design. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://delo-design.ru/
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\RadicalMartPayment\Payselection\Extension\Payselection;

return new class implements ServiceProviderInterface {

	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @since   2.0.0
	 */
	public function register(Container $container)
	{
		$container->set(PluginInterface::class,
			function (Container $container) {
				$plugin  = PluginHelper::getPlugin('radicalmart_payment', 'payselection');
				$subject = $container->get(DispatcherInterface::class);

				$plugin = new Payselection($subject, (array) $plugin);
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};