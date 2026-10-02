<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture;

use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;

/**
 * A plain charge subject for the off-session tests (a subscription or a booking balance in real
 * life): every getter returns what was given, every setter records.
 */
class Subject implements ChargeSubjectInterface
{
    /** @var array<string, mixed> */
    public $data;

    public function __construct(array $data = [])
    {
        $this->data = $data + [
            'reference' => 'test-1',
            'metadata' => ['test_subject' => '1'],
            'description' => 'Test charge 1',
            'customer_id' => 3,
            'store_id' => 1,
            'payment_method' => '',
            'vault_token_id' => null,
            'payment_data' => [],
        ];
    }

    public function getOffSessionReference(): string
    {
        return (string)$this->data['reference'];
    }

    public function getOffSessionMetadata(): array
    {
        return (array)$this->data['metadata'];
    }

    public function getOffSessionDescription(): string
    {
        return (string)$this->data['description'];
    }

    public function getCustomerId(): ?int
    {
        return $this->data['customer_id'] === null ? null : (int)$this->data['customer_id'];
    }

    public function getStoreId(): int
    {
        return (int)$this->data['store_id'];
    }

    public function getPaymentMethod(): string
    {
        return (string)$this->data['payment_method'];
    }

    public function getVaultTokenId(): ?int
    {
        return $this->data['vault_token_id'] === null ? null : (int)$this->data['vault_token_id'];
    }

    public function getPaymentData(): array
    {
        return (array)$this->data['payment_data'];
    }

    public function setPaymentMethod(string $method)
    {
        $this->data['payment_method'] = $method;
        return $this;
    }

    public function setVaultTokenId(?int $tokenId)
    {
        $this->data['vault_token_id'] = $tokenId;
        return $this;
    }

    public function setPaymentData(array $data)
    {
        $this->data['payment_data'] = $data;
        return $this;
    }
}
