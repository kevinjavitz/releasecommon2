<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Quote\Api\Data\CartInterface;

/**
 * The composite of the consumers' SavedCardRequirementInterface providers (di argument `providers`):
 * true when any of them needs the card kept.
 */
class SavedCardRequirement implements SavedCardRequirementInterface
{
    /** @var SavedCardRequirementInterface[] */
    private $providers;

    public function __construct(array $providers = [])
    {
        $this->providers = array_values(array_filter($providers, static function ($provider) {
            return $provider instanceof SavedCardRequirementInterface;
        }));
    }

    public function requiresSavedCard(CartInterface $quote): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->requiresSavedCard($quote)) {
                return true;
            }
        }
        return false;
    }
}
