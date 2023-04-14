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

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Path;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Registry\Registry;

return new class () implements ServiceProviderInterface {
	public function register(Container $container)
	{
		$container->set(InstallerScriptInterface::class, new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
			/**
			 * The application object
			 *
			 * @var  AdministratorApplication
			 *
			 * @since  2.0.0
			 */
			protected AdministratorApplication $app;

			/**
			 * The Database object.
			 *
			 * @var   DatabaseDriver
			 *
			 * @since  2.0.0
			 */
			protected DatabaseDriver $db;

			/**
			 * Minimum Joomla version required to install the extension.
			 *
			 * @var  string
			 *
			 * @since  2.0.0
			 */
			protected string $minimumJoomla = '4.2';

			/**
			 * Minimum PHP version required to install the extension.
			 *
			 * @var  string
			 *
			 * @since  2.0.0
			 */
			protected string $minimumPhp = '7.4';

			/**
			 * Update methods.
			 *
			 * @var  array
			 *
			 * @since  2.0.0
			 */
			protected array $updateMethods = [
				'update2_0_0'
			];

			/**
			 * Constructor.
			 *
			 * @param   AdministratorApplication  $app  The application object.
			 *
			 * @since 2.0.0
			 */
			public function __construct(AdministratorApplication $app)
			{
				$this->app = $app;
				$this->db  = Factory::getContainer()->get('DatabaseDriver');
			}

			/**
			 * Function called after the extension is installed.
			 *
			 * @param   InstallerAdapter  $adapter  The adapter calling this method
			 *
			 * @return  boolean  True on success
			 *
			 * @since   2.0.0
			 */
			public function install(InstallerAdapter $adapter): bool
			{
				$this->enablePlugin($adapter);

				return true;
			}

			/**
			 * Function called after the extension is updated.
			 *
			 * @param   InstallerAdapter  $adapter  The adapter calling this method
			 *
			 * @return  boolean  True on success
			 *
			 * @since   2.0.0
			 */
			public function update(InstallerAdapter $adapter): bool
			{
				return true;
			}

			/**
			 * Function called after the extension is uninstalled.
			 *
			 * @param   InstallerAdapter  $adapter  The adapter calling this method
			 *
			 * @return  boolean  True on success
			 *
			 * @since   2.0.0
			 */
			public function uninstall(InstallerAdapter $adapter): bool
			{
				return true;
			}

			/**
			 * Function called before extension installation/update/removal procedure commences.
			 *
			 * @param   string            $type     The type of change (install or discover_install, update, uninstall)
			 * @param   InstallerAdapter  $adapter  The adapter calling this method
			 *
			 * @return  boolean  True on success
			 *
			 * @since   1.0.1
			 */
			public function preflight(string $type, InstallerAdapter $adapter): bool
			{
				// Check compatible
				if (!$this->checkCompatible())
				{
					return false;
				}

				if ($type === 'update')
				{
					// Check update server
					$this->changeUpdateServer();
				}

				return true;
			}

			/**
			 * Function called after extension installation/update/removal procedure commences.
			 *
			 * @param   string            $type     The type of change (install or discover_install, update, uninstall)
			 * @param   InstallerAdapter  $adapter  The adapter calling this method
			 *
			 * @return  boolean  True on success
			 *
			 * @since   2.0.0
			 */
			public function postflight(string $type, InstallerAdapter $adapter): bool
			{
				$installer = $adapter->getParent();
				// Run updates script
				if ($type === 'update')
				{
					foreach ($this->updateMethods as $method)
					{
						if (method_exists($this, $method))
						{
							$this->$method($adapter);
						}
					}
				}

				return true;
			}

			/**
			 * Method to change current update server.
			 *
			 * @throws  \Exception
			 *
			 * @since  1.0.1
			 */
			protected function changeUpdateServer()
			{
				$old = 'https://radicalmart.ru/update?element=plg_radicalmart_payment_payselection';
				$new = 'https://sovmart.ru/update?element=plg_radicalmart_payment_payselection';

				$db    = $this->db;
				$query = $db->getQuery(true)
					->select(['update_site_id', 'location'])
					->from($db->quoteName('#__update_sites'))
					->where($db->quoteName('location') . ' = :location')
					->bind(':location', $old);
				if ($update = $db->setQuery($query)->loadObject())
				{
					$update->location = $new;
					$db->updateObject('#__update_sites', $update, 'update_site_id');
				}
			}

			/**
			 * Method to check compatible.
			 *
			 * @throws  \Exception
			 *
			 * @return  bool True on success, False on failure.
			 *
			 * @since  2.0.0
			 */
			protected function checkCompatible(): bool
			{
				$app = Factory::getApplication();

				// Check joomla version
				if (!(new Version())->isCompatible($this->minimumJoomla))
				{
					$app->enqueueMessage(Text::sprintf('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_COMPATIBLE_JOOMLA', $this->minimumJoomla),
						'error');

					return false;
				}

				// Check PHP
				if (!(version_compare(PHP_VERSION, $this->minimumPhp) >= 0))
				{
					$app->enqueueMessage(Text::sprintf('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_COMPATIBLE_PHP', $this->minimumPhp),
						'error');

					return false;
				}

				return true;
			}

			/**
			 * Enable plugin after installation.
			 *
			 * @param   InstallerAdapter  $adapter  Parent object calling object.
			 *
			 * @since  1.0.0
			 */
			protected function enablePlugin(InstallerAdapter $adapter)
			{
				// Prepare plugin object
				$plugin          = new \stdClass();
				$plugin->type    = 'plugin';
				$plugin->element = $adapter->getElement();
				$plugin->folder  = (string) $adapter->getParent()->manifest->attributes()['group'];
				$plugin->enabled = 1;

				// Update record
				$this->db->updateObject('#__extensions', $plugin, ['type', 'element', 'folder']);
			}

			/**
			 * Method to update to 2.0.0 version.
			 *
			 * @since  2.0.0
			 */
			protected function update2_0_0()
			{
				// Update Radicalmart Express params
				if (ComponentHelper::isEnabled('com_radicalmart_express'))
				{
					$params = ComponentHelper::getParams('com_radicalmart_express')->toArray();
					if (isset($params['payselection_api_id']) && !empty($params['payment_method_plugin'])
						&& $params['payment_method_plugin'] === 'payselection')
					{

						if (!isset($params['payment_method_params']))
						{
							$params['payment_method_params'] = [];
						}
						foreach ($params as $key => $value)
						{
							if (strpos($key, 'payselection_') !== false)
							{
								$params['payment_method_params'][str_replace('payselection_', '', $key)] = $value;
								unset($params[$key]);

							}
						}

						$update               = new \stdClass();
						$update->extension_id = ComponentHelper::getComponent('com_radicalmart_express')->id;
						$update->params       = (new Registry($params))->toString();

						$this->db->updateObject('#__extensions', $update, 'extension_id');
					}
				}

				// Delete files
				$files = [
					'/plugins/radicalmart_payment/payselection/forms/express.xml',
					'/plugins/radicalmart_payment/payselection/forms/paymentmethod.xml',
				];
				foreach ($files as $file)
				{
					$path = Path::clean(JPATH_ROOT . '/' . $file);
					if (File::exists($path))
					{
						File::delete($path);
					}
				}
			}
		});
	}
};