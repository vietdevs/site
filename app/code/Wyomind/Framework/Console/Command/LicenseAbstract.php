<?php


/**
 * Copyright © 2020 Wyomind. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Wyomind\Framework\Console\Command;


use Symfony\Component\Console\Command\Command;

/**
 * Class Activate
 * @package Wyomind\Framework\Console\Command\License
 */
class LicenseAbstract extends Command
{
    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;

    /**
     * @var \Wyomind\Framework\Model\ResourceModel\ConfigFactory
     */
    protected $configFactory;
    /**
     * @var
     */
    protected $license;
    /**
     * @var
     */
    protected $configHelper;
    /**
     * @var
     */
    protected $config;
    /**
     * @var
     */
    protected $frameworkVersion;
    /**
     * @var \Magento\Framework\Module\Dir\ReaderFactory
     */
    protected $directoryReaderFactory;

    /**
     * @var mixed
     */
    protected $logger;


    /**
     * LicenseAbstract constructor.
     * @param \Magento\Framework\App\State $state
     * @param \Wyomind\Framework\Helper\Module $license
     * @param \Wyomind\Framework\Model\ResourceModel\ConfigFactory $configFactory
     * @param \Magento\Framework\Module\Dir\ReaderFactory $directoryReaderFactory
     */
    public function __construct(
        \Magento\Framework\App\State $state,
        \Wyomind\Framework\Helper\ModuleFactory $license,
        \Wyomind\Framework\Model\ResourceModel\ConfigFactory $configFactory,
        \Magento\Framework\Module\Dir\ReaderFactory $directoryReaderFactory

    )
    {

        parent::__construct();
        $this->state = $state;
        $this->configFactory = $configFactory;
        $this->directoryReaderFactory = $directoryReaderFactory;
        $this->config = $this->configFactory->create();
        $this->license = $license;


    }

    function create()
    {
        $this->license = $this->license->create();
        $this->logger = $this->license->getLogger();
        $this->frameworkVersion = $this->license->getFrameworkVersion();


    }

    /**
     * @param $module
     * @param $input
     * @param $output
     * @param bool $ak
     * @param $autoRequest
     * @throws \Exception
     */

    protected function activate($module, & $input, & $output, $ak = false, $autoRequest)
    {


        $licenseCode = "";
        $frameworkVersion = $this->license->getFrameworkVersion();
        $ext = strtolower($module);

        $prefix = $this->license->getPrefix($module);
        $directory = $this->directoryReaderFactory->create()->getModuleDir('', $module);
        $xml = simplexml_load_file($directory . "/etc/module.xml");

        $currentVersion = (string)$xml->module['setup_version'];


        if (!$ak) {
            $output->writeln(" ");

            $output->writeln("<fg=black;bg=yellow>" . __("Activating") . " " . $module . "</>");
            $ak = $this->license->getDefaultConfigUncrypted($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/activation_key");
            if (empty($ak)) {
                $output->writeln("<error>" . __("Unable to activate: no license key found for") . " " . $module . "</error>");
                $output->writeln("<comment>" . __("Please run wyomind:license:activate") . " " . $module . " " . "<activation_key>" . "</comment>");

                return;
            }

        }


        $registeredVersion = $this->config->getDefaultValueByPath($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/extension_version");
        if ($registeredVersion == "") {
            $registeredVersion = $this->license->getStoreConfig($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/extension_version");
        }

        $domain = str_replace("{{unsecure_base_url}}", $this->config->getDefaultValueByPath("web/unsecure/base_url"), $this->config->getDefaultValueByPath("web/secure/base_url"));
        if ($domain == "") {
            $domain = str_replace("{{unsecure_base_url}}", $this->license->getStoreConfig("web/unsecure/base_url"), $this->license->getStoreConfig("web/secure/base_url"));
        }

        $soapParams = [
            "method" => "get",
            "rv" => ($registeredVersion != null) ? $registeredVersion : "",
            "cv" => ($currentVersion != null) ? $currentVersion : "",
            "namespace" => $ext,
            "activation_key" => $ak,
            "domain" => $domain,
            "magento" => $this->license->getMagentoVersion(),
            "licensemanager" => $frameworkVersion,
            "enterprise" => $this->license->moduleIsEnabled("Magento_Enterprise")
        ];


        // licence deleted because wrong ak or ac

        if ($registeredVersion != "" && $registeredVersion != $currentVersion && $licenseCode) { // Extension upgrade
            $this->license->setStoreConfig($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/activation_code", "");
            $this->license->setStoreConfig($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/extension_version", $currentVersion);
            $output->writeln($this->license->sprintfArray("upgrade", [$registeredVersion, $currentVersion]));
        } elseif ($ak && (!$licenseCode || empty($licenseCode))) { // not yet activated --> automatic activation
            try {
                $options = ['location' => \Wyomind\Framework\Helper\License::SOAP_URL, 'uri' => \Wyomind\Framework\Helper\License::SOAP_URI];
                if (!class_exists("\SoapClient")) {
                    throw new \RuntimeException();
                }
                $api = new \SoapClient(null, $options);

                $ws = $api->checkActivation($soapParams, true);
                $wsResult = json_decode($ws);

                $this->logger->notice("---------------------------------");
                $this->logger->notice("Cli activation");
                $this->logger->notice(print_r($wsResult, true));

                switch ($wsResult->status) {
                    case "success":
                        $output->writeln($this->license->sprintfArray("ws_success", [$wsResult->message]));
                        $this->license->setStoreConfig($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/extension_version", $wsResult->version);
                        $this->license->setStoreConfigCrypted($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/activation_key", $ak);
                        $this->license->setStoreConfigCrypted($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/activation_code", $wsResult->activation);

                        break;
                    case "error":
                        $this->license->setStoreConfig($prefix . str_ireplace("Wyomind_", "", $ext) . "/license/activation_code", "");
                        if ($wsResult->code == "016") {
                            $error = preg_replace("/<comment>.*<\/comment>/", "", $wsResult->message);
                            $output->writeln($this->license->sprintfArray("ws_success", [trim($error)]));

                            if ($autoRequest) {
                                $this->request($module, $output, $ak);
                            } else {
                                $questionPhrase = ("<comment>Would you like to add " . $domain . " to your license?</comment> <fg=green>yes</> / <fg=red>no</> : ");
                                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                                $question = $objectManager->create('\Symfony\Component\Console\Question\ConfirmationQuestion', ['question' => $questionPhrase, 'default' => FALSE]);
                                $helper = $objectManager->create("\Symfony\Component\Console\Helper\QuestionHelper");

                                $answer = $helper->ask($input, $output, $question);

                                if ($answer) {

                                    $this->request($module, $output, $ak);

                                } else {
                                    $output->writeln("<error>Your license has not been activated.</error>");
                                }
                            }

                        } else {

                            $output->writeln($this->license->sprintfArray("ws_success", [$wsResult->message]));
                        }


                        break;
                    default:
                        throw new \Exception(strip_tags($this->license->sprintfArray("ws_error", [''])));

                }
            } catch (\RuntimeException $e) {


                throw new \Exception(__("SOAP request not allowed. Please enable SOAP."));
            }
        }
    }


    /**
     * @param $module
     * @param $output
     * @param $ak
     * @throws \Exception
     */
    protected function request($module, $output, $ak)
    {

        $licensingMethod = 1;
        $licenseCode = "";

        $ext = strtolower($module);
        $prefix = $this->license->getPrefix($module);

        $currentVersion = $this->license->getStoreConfig($prefix . $ext . "/license/extension_version");

        $registeredVersion = $this->config->getDefaultValueByPath($prefix . $ext . "/license/extension_version");
        if ($registeredVersion == "") {
            $registeredVersion = $this->license->getStoreConfig($prefix . $ext . "/license/extension_version");
        }

        $domain = str_replace("{{unsecure_base_url}}", $this->config->getDefaultValueByPath("web/unsecure/base_url"), $this->config->getDefaultValueByPath("web/secure/base_url"));
        if ($domain == "") {
            $domain = str_replace("{{unsecure_base_url}}", $this->license->getStoreConfig("web/unsecure/base_url"), $this->license->getStoreConfig("web/secure/base_url"));
        }

        $soapParams = [
            "method" => "get",
            "rv" => ($registeredVersion != null) ? $registeredVersion : "",
            "cv" => ($currentVersion != null) ? $currentVersion : "",
            "namespace" => $ext,
            "activation_key" => $ak,
            "domain" => $domain,
            "magento" => $this->license->getMagentoVersion(),
            "licensemanager" => $this->frameworkVersion,
            "enterprise" => $this->license->moduleIsEnabled("Magento_Enterprise")
        ];

        // licence deleted because wrong ak or ac
        if ($registeredVersion != "" && $registeredVersion != $currentVersion && $licenseCode) { // Extension upgrade
            $this->license->getStoreConfig($prefix . $ext . "/license/activation_code", "");
            $this->license->getStoreConfig($prefix . $ext . "/license/extension_version", $currentVersion);
            $output->writeln($this->license->sprintfArray("upgrade", [$registeredVersion, $currentVersion]));
        } elseif ($ak && (!$licenseCode || empty($licenseCode)) && $licensingMethod) { // not yet activated --> automatic activation
            try {

                $options = ['location' => \Wyomind\Framework\Helper\License::SOAP_URL, 'uri' => \Wyomind\Framework\Helper\License::SOAP_URI];
                if (!class_exists("\SoapClient")) {
                    throw new RuntimeException();
                }
                $api = new \SoapClient(null, $options);
                $ws = $api->askNewLicense($soapParams);
                $wsResult = json_decode($ws);
                $this->logger->notice("---------------------------------");
                $this->logger->notice("Cli activation");
                $this->logger->notice(print_r($wsResult, true));

                switch ($wsResult->status) {
                    case "success":

                    case "error":

                        $output->writeln($this->license->sprintfArray("ws_success", [$wsResult->message]));
                        break;
                    default:
                        throw new \Exception(strip_tags($this->license->sprintfArray("ws_error", [''])));


                }
            } catch (\RuntimeException $e) {

                throw new \Exception(__("SOAP request not allowed. Please enable SOAP."));
            }
        }
    }
}