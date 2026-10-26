<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxComponent\Helper;

use Magento\Framework\Locale\ResolverInterface;

/**
 * Text of a `*_i18n` setting for the store being rendered: the locale's entry, else `default`, then `__()`
 */
class LocalizedText
{
    /**
     * Locale of the store being rendered
     *
     * @param ResolverInterface $localeResolver
     */
    public function __construct(
        private readonly ResolverInterface $localeResolver,
    ) {
    }

    /**
     * The text for the current locale
     *
     * @param mixed $value
     * @return string
     */
    public function get(mixed $value): string
    {
        if (\is_array($value)) {
            // Asked each time: store emulation changes the locale within one process.
            $value = $value[(string) $this->localeResolver->getLocale()] ?? $value['default'] ?? '';
        }
        if (!\is_string($value) || $value === '') {
            return '';
        }

        // Text the dictionary knows (Lynx or core CSV, language pack) comes out in the store's language
        return (string) __($value);
    }
}
