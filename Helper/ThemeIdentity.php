<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Design\Theme\FlyweightFactory;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Whether a theme or a store view's theme (parent chain included) is Lynx, and the page component Lynx renders through.
 * Here because both OOCS_LynxTheme and the PageBuilder editor ask, from opposite sides of the module graph.
 */
class ThemeIdentity
{
    /**
     * Every Lynx theme code starts with this
     */
    public const THEME_CODE = 'OOCS/Lynx';

    /**
     * The page component every layout-driven page renders through (ResolveLayoutPage, PreviewLayoutPage)
     */
    public const PAGE_COMPONENT = 'OOCS_LynxTheme::Default';

    /**
     * @var array<int, ThemeInterface|null>
     */
    private array $storeThemes = [];

    /**
     * Store config for the theme id, theme factory to load it
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param FlyweightFactory $themeFactory
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly FlyweightFactory $themeFactory,
    ) {
    }

    /**
     * Whether the theme itself or one it inherits from is a Lynx theme
     *
     * @param ThemeInterface|null $theme
     * @return bool
     */
    public function isLynx(?ThemeInterface $theme): bool
    {
        while ($theme !== null) {
            if (str_starts_with((string) $theme->getCode(), self::THEME_CODE)) {
                return true;
            }

            $theme = $theme->getParentTheme();
        }

        return false;
    }

    /**
     * Whether a store view runs a Lynx theme
     *
     * @param int $storeId
     * @return bool
     */
    public function isLynxForStore(int $storeId): bool
    {
        return $this->isLynx($this->getStoreTheme($storeId));
    }

    /**
     * The code of the theme a store view runs ('Magento/luma'), '' when none can be loaded
     *
     * @param int $storeId
     * @return string
     */
    public function getCodeForStore(int $storeId): string
    {
        return (string) ($this->getStoreTheme($storeId)?->getCode() ?? '');
    }

    /**
     * The frontend theme configured for a store view, at store scope
     *
     * @param int $storeId
     * @return ThemeInterface|null
     */
    private function getStoreTheme(int $storeId): ?ThemeInterface
    {
        if (\array_key_exists($storeId, $this->storeThemes)) {
            return $this->storeThemes[$storeId];
        }

        $themeId = (string) $this->scopeConfig->getValue(
            DesignInterface::XML_PATH_THEME_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $this->storeThemes[$storeId] = $themeId ? $this->load($themeId) : null;
    }

    /**
     * Null for a theme id that no longer resolves (taken off disk)
     *
     * @param string $themeId
     * @return ThemeInterface|null
     */
    private function load(string $themeId): ?ThemeInterface
    {
        try {
            return $this->themeFactory->create($themeId, DesignInterface::DEFAULT_AREA);
        } catch (\InvalidArgumentException | \LogicException) {
            return null;
        }
    }
}
