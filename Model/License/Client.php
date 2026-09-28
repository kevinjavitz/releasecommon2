<?php
namespace SalesIgniter\Common\Model\License;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * The EDD Software Licensing API on rentalbookingsoftware.com: `check_license`,
 * `activate_license` and `deactivate_license`, the same three calls the WooCommerce add-on
 * makes (sibooking Admin/License.php). POST, so the key stays out of access logs.
 */
class Client
{
    const TIMEOUT = 10;

    private $curlFactory;
    private $logger;

    public function __construct(CurlFactory $curlFactory, LoggerInterface $logger)
    {
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
    }

    /**
     * @param string $action check_license | activate_license | deactivate_license
     * @return array|null the decoded answer, or null when the store could not be reached or did
     *                    not answer JSON -- never an exception, a licence check must not break a page
     */
    public function call(string $action, string $key, int $itemId, string $siteUrl): ?array
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT);
            $curl->post(Products::STORE_URL, [
                'edd_action' => $action,
                'license' => $key,
                'item_id' => $itemId,
                'url' => $siteUrl,
            ]);
            if ($curl->getStatus() !== 200) {
                $this->logger->warning('Sales Igniter license ' . $action . ': HTTP ' . $curl->getStatus());
                return null;
            }
            $answer = json_decode($curl->getBody(), true);
            return is_array($answer) ? $answer : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Sales Igniter license ' . $action . ': ' . $e->getMessage());
            return null;
        }
    }
}
