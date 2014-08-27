<?php

namespace Oro\Bundle\EntityExtendBundle\Extend;

use Psr\Log\LoggerInterface;

use Oro\Bundle\InstallerBundle\Process\PhpExecutableFinder;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Tools\CommandExecutor;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\PlatformBundle\Maintenance\Mode as MaintenanceMode;

class EntityProcessor
{
    /**
     * @var MaintenanceMode
     */
    protected $maintenance;

    /**
     * @var ConfigManager
     */
    protected $configManager;

    /**
     * @var CommandExecutor
     */
    protected $commandExecutor;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $commands = [
        'oro:entity-extend:update-config' => [],
        'oro:entity-extend:update-schema' => [],
        // TODO: Update foreign keys for extended relation fields (manyToOne, oneToMany, manyToMany)
        // TODO: Should be fixed in scope of https://magecore.atlassian.net/browse/BAP-3621
        //'doctrine:schema:update'          => ['--force' => true],
        // TODO: Update extended entity cache after schema update
        // TODO: Should be fixed in scope of https://magecore.atlassian.net/browse/BAP-3652
        //'cache:clear'                     => [],
    ];

    /**
     * @param MaintenanceMode $maintenance
     * @param ConfigManager   $configManager
     * @param CommandExecutor $commandExecutor
     * @param LoggerInterface $logger
     */
    public function __construct(
        MaintenanceMode $maintenance,
        ConfigManager $configManager,
        CommandExecutor $commandExecutor,
        LoggerInterface $logger
    ) {
        $this->maintenance     = $maintenance;
        $this->configManager   = $configManager;
        $this->commandExecutor = $commandExecutor;
        $this->logger          = $logger;
    }

    /**
     * Update database and generate extended field
     *
     * @param bool $generateProxies
     * @return bool
     */
    public function updateDatabase($generateProxies = true)
    {
        set_time_limit(0);

        $this->maintenance->activate();

        $exitCode = 0;
        foreach ($this->commands as $command => $options) {
            $code = $this->commandExecutor->runCommand(
                $command,
                $options,
                $this->logger
            );

            if ($code !== 0) {
                $exitCode = $code;
            }
        }

        $isSuccess = $exitCode === 0;

        if ($isSuccess && $generateProxies) {
            $this->generateProxies();
        }

        return $isSuccess;
    }

    /**
     * Generate doctrine proxy classes for extended entities
     */
    public function generateProxies()
    {
        $em = $this->configManager->getEntityManager();

        $isAutoGenerated = $em->getConfiguration()->getAutoGenerateProxyClasses();
        if (!$isAutoGenerated) {
            $extendConfigProvider = $this->configManager->getProvider('extend');
            $entityConfigs        = $extendConfigProvider->getConfigs(null, true);
            foreach ($entityConfigs as $entityConfig) {
                if (!$entityConfig->is('is_extend')) {
                    continue;
                }
                if ($entityConfig->in('state', [ExtendScope::STATE_NEW, ExtendScope::STATE_DELETED])) {
                    continue;
                }

                $proxyFileName = $em->getConfiguration()->getProxyDir() . DIRECTORY_SEPARATOR . '__CG__'
                    . str_replace('\\', '', $entityConfig->getId()->getClassName()) . '.php';
                if (!file_exists($proxyFileName)) {
                    $proxyFactory = $em->getProxyFactory();
                    $proxyDir     = $em->getConfiguration()->getProxyDir();
                    $meta         = $em->getClassMetadata($entityConfig->getId()->getClassName());

                    $proxyFactory->generateProxyClasses([$meta], $proxyDir);
                }
            }
        }
    }

    /**
     * @return string
     * @throws \RuntimeException
     */
    protected function getPhp()
    {
        $phpFinder = new PhpExecutableFinder();
        if (!$phpPath = $phpFinder->find()) {
            throw new \RuntimeException(
                'The php executable could not be found, add it to your PATH environment variable and try again'
            );
        }

        return $phpPath;
    }
}
