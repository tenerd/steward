<?php

namespace Lmc\Steward\Listener;

use Lmc\Steward\Test\AbstractTestCase;
use Lmc\Steward\ConfigProvider;
use Lmc\Steward\WebDriver\NullWebDriver;
use Lmc\Steward\WebDriver\RemoteWebDriver;
use Nette\Reflection\AnnotationsParser;

/**
 * Listener for initialization and destruction of WebDriver before and after each test.
 *
 * Note: This is done as a listener rather then in setUp() and tearDown(), as a workaround
 * for the sequence in which PHPUnit executes tearDown() of tests and addFailure() on listeners.
 * If taking screenshot using addFailure(), tearDown() would have already been called and the
 * browser would be closed.
 */
class WebDriverListener extends \PHPUnit_Framework_BaseTestListener
{
    const NO_BROWSER_ANNOTATION = 'noBrowser';

    public function startTest(\PHPUnit_Framework_Test $test)
    {
        if ($test instanceof \PHPUnit_Framework_Warning) {
            return;
        }

        if (!$test instanceof AbstractTestCase) {
            throw new \InvalidArgumentException('Test case must be descendant of Lmc\Steward\Test\AbstractTestCase');
        }

        $config = ConfigProvider::getInstance();

        // Initialize NullWebDriver if self::NO_BROWSER_ANNOTATION is used on testcase class or test method
        $testCaseAnnotations = AnnotationsParser::getAll(new \ReflectionClass($test));
        $testAnnotations = AnnotationsParser::getAll(new \ReflectionMethod($test, $test->getName(false)));

        if (isset($testCaseAnnotations[self::NO_BROWSER_ANNOTATION])
            || isset($testAnnotations[self::NO_BROWSER_ANNOTATION])
        ) {
            $test->wd = new NullWebDriver();
            $test->log(
                'Initializing Null WebDriver for "%s::%s" (@%s annotation used %s)',
                get_class($test),
                $test->getName(),
                self::NO_BROWSER_ANNOTATION,
                isset($testCaseAnnotations[self::NO_BROWSER_ANNOTATION]) ? 'on class' : 'on method'
            );

            return;
        }

        // Initialize real WebDriver otherwise
        $test->log(
            'Initializing "%s" WebDriver for "%s::%s"',
            $config->browserName,
            get_class($test),
            $test->getName()
        );

        $capabilities = new \DesiredCapabilities(
            [
                \WebDriverCapabilityType::BROWSER_NAME => $config->browserName,
                \WebDriverCapabilityType::PLATFORM => \WebDriverPlatform::ANY,
            ]
        );

        $this->createWebDriver(
            $test,
            $config->serverUrl . '/wd/hub',
            $this->setupCustomCapabilities($capabilities, $config->browserName),
            $connectTimeoutMs = 2*60*1000,
            // How long could request to Selenium take (eg. how long could we wait in hub's queue to available node)
            $requestTimeoutMs = 3*60*1000 // -1 hour- 3 minutes (same as timeout for the whole process)
        );
    }

    public function endTest(\PHPUnit_Framework_Test $test, $time)
    {
        if ($test instanceof \PHPUnit_Framework_Warning) {
            return;
        }

        if (!$test instanceof AbstractTestCase) {
            throw new \InvalidArgumentException('Test case must be descendant of Lmc\Steward\Test\AbstractTestCase');
        }

        if ($test->wd instanceof \RemoteWebDriver) {
            $test->log(
                'Destroying "%s" WebDriver for "%s::%s" (session %s)',
                ConfigProvider::getInstance()->browserName,
                get_class($test),
                $test->getName(),
                $test->wd->getSessionID()
            );

            // Workaround for PhantomJS 1.x - see https://github.com/detro/ghostdriver/issues/343
            // Should be removed with PhantomJS 2
            $test->wd->execute('deleteAllCookies');

            $test->wd->close();
            $test->wd->quit();
        }
    }

    /**
     * Subroutine to encapsulate creation of real WebDriver. Handles some exceptions that may occur etc.
     * The WebDriver instance is stored to $test->wd when created.
     *
     * @param AbstractTestCase $test
     * @param $remoteServerUrl
     * @param \DesiredCapabilities $capabilities
     * @param $connectTimeoutMs
     * @param $requestTimeoutMs
     */
    protected function createWebDriver(
        AbstractTestCase $test,
        $remoteServerUrl,
        \DesiredCapabilities $capabilities,
        $connectTimeoutMs,
        $requestTimeoutMs
    ) {
        $browserName = ConfigProvider::getInstance()->browserName;
        for ($startAttempts = 0; $startAttempts < 4; $startAttempts++) {
            try {
                $test->wd =
                    RemoteWebDriver::create($remoteServerUrl, $capabilities, $connectTimeoutMs, $requestTimeoutMs);
                return;
            } catch (\UnknownServerException $e) {
                if ($browserName == 'firefox' && strpos($e->getMessage(), 'Unable to bind to locking port') !== false) {
                    // As a consequence of Selenium issue #5172 (cannot change locking port), Firefox may on CI server
                    // collide with other FF instance. As a workaround, we try to start it again after a short delay.
                    $test->warn(
                        'Firefox locking port is occupied; beginning attempt #%d to start it ("%s")',
                        $startAttempts + 2,
                        $e->getMessage()
                    );
                    sleep(1);
                    continue;

                } elseif (strpos($e->getMessage(), 'Error forwarding the new session') !== false) {
                    $test->warn("Cannot execute test on the node. Maybe you started just the hub and not the node?");
                }
                throw $e;
            }
        }

        $test->warn('All %d attempts to instantiate Firefox WebDriver failed', $startAttempts + 1);
        throw $e;
    }

    /**
     * Setup browser-specific custom capabilities.
     * @param \DesiredCapabilities $capabilities
     * @param string $browser Browser name
     * @return \DesiredCapabilities
     */
    protected function setupCustomCapabilities(\DesiredCapabilities $capabilities, $browser)
    {
        switch ($browser) {
            case \WebDriverBrowserType::FIREFOX:
                $capabilities = $this->setupFirefoxCapabilities($capabilities);
                break;
            case \WebDriverBrowserType::CHROME:
                $capabilities = $this->setupChromeCapabilities($capabilities);
                break;
            case \WebDriverBrowserType::IE:
                $capabilities = $this->setupInternetExplorerCapabilities($capabilities);
                break;
            case \WebDriverBrowserType::SAFARI:
                $capabilities = $this->setupSafariCapabilities($capabilities);
                break;
            case \WebDriverBrowserType::PHANTOMJS:
                $capabilities = $this->setupPhantomjsCapabilities($capabilities);
                break;
        }

        return $capabilities;
    }

    /**
     * Set up Firefox-specific capabilities
     * @param \DesiredCapabilities $capabilities
     * @return \DesiredCapabilities
     */
    protected function setupFirefoxCapabilities(\DesiredCapabilities $capabilities)
    {
        // Firefox does not (as a intended feature) trigger "change" and "focus" events in javascript if not in active
        // (focused) window. This would be a problem for concurrent testing - solution is to use focusmanager.testmode.
        // See https://code.google.com/p/selenium/issues/detail?id=157
        $profile = new \FirefoxProfile(); // see https://github.com/facebook/php-webdriver/wiki/FirefoxProfile
        $profile->setPreference(
            'focusmanager.testmode',
            true
        );

        $capabilities->setCapability(\FirefoxDriver::PROFILE, $profile);

        return $capabilities;
    }

    /**
     * Set up Chrome/Chromium-specific capabilities
     * @param \DesiredCapabilities $capabilities
     * @return \DesiredCapabilities
     */
    protected function setupChromeCapabilities(\DesiredCapabilities $capabilities)
    {
        return $capabilities;
    }

    /**
     * Set up Internet Explorer-specific capabilities
     * @param \DesiredCapabilities $capabilities
     * @return \DesiredCapabilities
     */
    protected function setupInternetExplorerCapabilities(\DesiredCapabilities $capabilities)
    {
        // Clears cache, cookies, history, and saved form data of MSIE.
        $capabilities->setCapability('ie.ensureCleanSession', true);

        return $capabilities;
    }

    /**
     * Set up Safari-specific capabilities
     * @param \DesiredCapabilities $capabilities
     * @return \DesiredCapabilities
     */
    protected function setupSafariCapabilities(\DesiredCapabilities $capabilities)
    {
        return $capabilities;
    }

    /**
     * Set up PhantomJS-specific capabilities
     * @param \DesiredCapabilities $capabilities
     * @return \DesiredCapabilities
     */
    protected function setupPhantomjsCapabilities(\DesiredCapabilities $capabilities)
    {
        return $capabilities;
    }
}
