<?php

/*
 * This file is part of the "headless" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

declare(strict_types=1);

namespace FriendsOfTYPO3\Headless\XClass\Routing;

use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Override of generateUri method to check if there is frontendHost set and replace host with frontendHost
 * in order to correctly generate cross-domain headless links
 */
class PageRouter extends \TYPO3\CMS\Core\Routing\PageRouter
{
    /**
     * @inheritDoc
     */
    public function generateUri($route, array $parameters = [], string $fragment = '', string $type = ''): UriInterface
    {
        if (version_compare(ExtensionManagementUtility::getExtensionVersion('headless'), '3.0.0', '>=')) {
            // Headless 3.x
            $urlUtility = GeneralUtility::makeInstance(\FriendsOfTYPO3\Headless\Utility\UrlUtility::class)->withSite($this->site);
            $frontendBaseUrl = $urlUtility->getFrontendUrl();
        } else {
            // Headless 2.x
            $frontendBase = GeneralUtility::makeInstance(\FriendsOfTYPO3\Headless\Utility\FrontendBaseUtility::class);
            $siteConf = $this->site->getConfiguration();
            $frontendBaseUrl = $frontendBase->resolveWithVariants('', $siteConf['baseVariants'] ?? []);
        }

        if ($frontendBaseUrl !== '') {
            $parsedFrontendBase = parse_url($frontendBaseUrl);
            $parameters['_frontendHost'] = $parsedFrontendBase['host'] ?? '';
            $parameters['_frontendPort'] = $parsedFrontendBase['port'] ?? null;
        }

        $frontendHost = $parameters['_frontendHost'] ?? null;
        $frontendPort = $parameters['_frontendPort'] ?? null;

        if ($frontendHost) {
            $language = $this->resolveLanguage($parameters);
            $base = $language->getBase()->withHost($frontendHost)->withPort($frontendPort);
            $parameters['_language'] = new SiteLanguage(
                $language->getLanguageId(),
                $language->getLocale(),
                $base,
                $language->toArray()
            );

            unset($parameters['_frontendHost'], $parameters['_frontendPort']);
        }

        return parent::generateUri($route, $parameters, $fragment, $type);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return SiteLanguage
     */
    private function resolveLanguage(array $parameters): SiteLanguage
    {
        $languageOption = $parameters['_language'] ?? null;
        unset($parameters['_language']);

        if ($languageOption instanceof SiteLanguage) {
            $language = $languageOption;
        } elseif ($languageOption !== null) {
            $language = $this->site->getLanguageById((int)$languageOption);
        }
        if ($language === null) {
            $language = $this->site->getDefaultLanguage();
        }

        return $language;
    }
}
