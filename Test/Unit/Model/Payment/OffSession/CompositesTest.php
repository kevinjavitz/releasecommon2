<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirementInterface;
use SalesIgniter\Common\Model\Payment\OffSession\SubjectSaver;
use SalesIgniter\Common\Model\Payment\OffSession\SubjectSaverInterface;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * The two composites each consumer adds to: "this checkout keeps the card" and "save this subject".
 */
class CompositesTest extends TestCase
{
    private function provider(bool $answer): SavedCardRequirementInterface
    {
        $p = $this->createStub(SavedCardRequirementInterface::class);
        $p->method('requiresSavedCard')->willReturn($answer);
        return $p;
    }

    public function testAnyProviderCanRequireTheSavedCard(): void
    {
        $quote = $this->createStub(CartInterface::class);
        self::assertFalse((new SavedCardRequirement())->requiresSavedCard($quote), 'no consumer installed: never forced');
        self::assertFalse((new SavedCardRequirement([$this->provider(false), 'junk']))->requiresSavedCard($quote));
        self::assertTrue((new SavedCardRequirement([$this->provider(false), $this->provider(true)]))->requiresSavedCard($quote));
    }

    public function testTheSaverForTheSubjectSavesIt(): void
    {
        $saved = [];
        $mine = new class ($saved) implements SubjectSaverInterface {
            public $saved;
            public function __construct(array &$saved) { $this->saved = &$saved; }
            public function supports(ChargeSubjectInterface $subject): bool { return $subject->getOffSessionReference() === 'mine-1'; }
            public function save(ChargeSubjectInterface $subject): void { $this->saved[] = $subject->getOffSessionReference(); }
        };
        $saver = new SubjectSaver([$mine]);
        self::assertTrue($saver->supports(new Subject(['reference' => 'mine-1'])));
        self::assertFalse($saver->supports(new Subject(['reference' => 'other-1'])));
        $saver->save(new Subject(['reference' => 'mine-1']));
        self::assertSame(['mine-1'], $saved);
        $this->expectException(LocalizedException::class);
        $saver->save(new Subject(['reference' => 'other-1']));
    }
}
