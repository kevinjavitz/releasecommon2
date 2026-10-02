<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\TokenBase\Plugin;

use ParadoxLabs\Authnetcim\Model\Gateway;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * Authorize.Net CIM merchant-initiated charges (MitContext active).
 *
 * - Card-on-file flags: a cron charge runs inside an emulated frontend area, where the gateway would
 *   mark the charge customer-initiated (isStoredCredentials). It is merchant-initiated:
 *   isSubsequentAuth, plus recurringBilling from is_subscription_generated (set by TokenBaseStrategy).
 * - The parsed API answer is kept for the decline classifier: a refusal otherwise surfaces only as a
 *   CommandException message (thrown inside createTransaction, so the answer comes from getLastResponse()).
 */
class AuthnetcimMit
{
    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param Gateway $subject
     * @return null
     */
    public function beforeCreateTransaction(Gateway $subject)
    {
        if ($this->context->isActive()) {
            $subject->setParameter('isStoredCredentials', null);
            $subject->setParameter('isSubsequentAuth', 'true');
        }
        return null;
    }

    /**
     * A declined charge throws inside createTransaction() (the gateway's own error check), so the
     * answer is read from getLastResponse(), which holds it by then.
     *
     * @param Gateway $subject
     * @param callable $proceed
     * @return mixed the parsed createTransaction response
     */
    public function aroundCreateTransaction(Gateway $subject, callable $proceed)
    {
        if (!$this->context->isActive()) {
            return $proceed();
        }
        try {
            $result = $proceed();
        } catch (\Throwable $e) {
            $last = $subject->getLastResponse();
            if (is_array($last)) {
                $this->context->stashGatewayResult(self::summarise($last));
            }
            throw $e;
        }
        if (is_array($result)) {
            $this->context->stashGatewayResult(self::summarise($result));
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, string>
     */
    public static function summarise(array $result): array
    {
        $txn = (array)($result['transactionResponse'] ?? []);
        $errors = $txn['errors']['error'] ?? $txn['errors'] ?? [];
        if (is_array($errors) && isset($errors['errorCode'])) {
            $errors = [$errors];
        }
        $first = is_array($errors) && $errors ? (array)reset($errors) : [];
        $message = $result['messages']['message'] ?? [];
        if (is_array($message) && isset($message[0])) {
            $message = (array)$message[0];
        }
        return [
            'gateway' => 'authnetcim',
            'response_code' => (string)($txn['responseCode'] ?? ''),
            'code' => (string)($first['errorCode'] ?? ''),
            'text' => (string)($first['errorText'] ?? $message['text'] ?? ''),
            'api_code' => (string)($message['code'] ?? ''),
        ];
    }
}
