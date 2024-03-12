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

\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\Path;

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
				'update2_1_0'
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
				if ($type !== 'uninstall')
				{
					$this->checkFiscalizationInstaller($installer);

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
				}

				return true;
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
			 * Method to check fiscalization plugin and install if needed.
			 *
			 * @param   Installer|null  $installer  Installer calling object.
			 *
			 * @throws  \Exception
			 *
			 * @since  2.1.0
			 */
			protected function checkFiscalizationInstaller(Installer $installer = null)
			{
				try
				{
					// Find extension
					$db    = $this->db;
					$query = $db->getQuery(true)
						->select('extension_id')
						->from($db->quoteName('#__extensions'))
						->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
						->where($db->quoteName('element') . ' = ' . $db->quote('fiscalization'))
						->where($db->quoteName('folder') . ' = ' . $db->quote('radicalmart'));
					if (!$db->setQuery($query, 0, 1)->loadResult())
					{
						// Download extension
						$src  = 'https://sovmart.ru/download?element=plg_radicalmart_fiscalization';
						$dest = Path::clean($installer->getPath('source') . '/plg_radicalmart_fiscalization.zip');

						if (!$context = file_get_contents($src))
						{
							throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_DOWNLOAD'), -1);
						}
						if (!file_put_contents($dest, $context))
						{
							throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_DOWNLOAD'), -1);
						}

						// Install extension
						if (!$package = InstallerHelper::unpack($dest, true))
						{
							throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
						}

						if (!$package['type'])
						{
							InstallerHelper::cleanupInstall(null, $package['extractdir']);

							throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
						}

						$installer = Installer::getInstance();
						$installer->setPath('source', $package['dir']);
						if (!$installer->findManifest())
						{
							InstallerHelper::cleanupInstall(null, $package['extractdir']);

							throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
						}

						if (!$installer->install($package['dir']))
						{
							InstallerHelper::cleanupInstall(null, $package['extractdir']);

							throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
						}

						InstallerHelper::cleanupInstall(null, $package['extractdir']);
					}
				}
				catch (Exception $e)
				{
					$this->app->enqueueMessage($e->getMessage(), 'error');
				}
			}

			/**
			 * Method to update to 2.1.0 version.
			 *
			 * @since  2.1.0
			 */
			protected function update2_1_0()
			{
				$folders = [
					Path::clean(JPATH_ROOT . '/administrator/language/en-GB'),
					Path::clean(JPATH_ROOT . '/administrator/language/ru-RU'),
				];

				// Remove old language files
				foreach ($folders as $folder)
				{
					$files = Folder::files($folder, '.plg_radicalmart_payment_payselection.', true, true);

					foreach ($files as $file)
					{
						if (strpos($file, '.plg_radicalmart_payment_payselection.') !== false)
						{
							File::delete($file);
						}
					}
				}
			}
		});
	}
};