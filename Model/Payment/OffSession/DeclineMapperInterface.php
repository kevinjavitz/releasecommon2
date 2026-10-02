<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

/**
 * Turns one gateway's raw result into a Decline. Gateway sub-modules register one in the `mappers`
 * argument of DeclineClassifier and stash the gateway's raw response in MitContext with an
 * after-plugin on their HTTP client, because gateway modules usually re-throw a generic message.
 */
interface DeclineMapperInterface
{
    /**
     * @param array<string, mixed> $gatewayResult what the gateway plugin stashed (may be empty)
     * @return Decline|null null when this mapper does not recognise the failure
     */
    public function map(array $gatewayResult, \Throwable $error): ?Decline;
}
