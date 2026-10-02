<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Framework\Exception\LocalizedException;

/**
 * The composite of the consumers' savers (di argument `savers`). A strategy asks supports() before
 * it charges, so a subject nobody can save is refused before any money moves.
 */
class SubjectSaver implements SubjectSaverInterface
{
    /** @var SubjectSaverInterface[] */
    private $savers;

    public function __construct(array $savers = [])
    {
        $this->savers = array_values(array_filter($savers, static function ($saver) {
            return $saver instanceof SubjectSaverInterface;
        }));
    }

    public function supports(ChargeSubjectInterface $subject): bool
    {
        return $this->saverFor($subject) !== null;
    }

    public function save(ChargeSubjectInterface $subject): void
    {
        $saver = $this->saverFor($subject);
        if ($saver === null) {
            throw new LocalizedException(__('Nothing can save the payment details of %1.', $subject->getOffSessionReference()));
        }
        $saver->save($subject);
    }

    private function saverFor(ChargeSubjectInterface $subject): ?SubjectSaverInterface
    {
        foreach ($this->savers as $saver) {
            if ($saver->supports($subject)) {
                return $saver;
            }
        }
        return null;
    }
}
