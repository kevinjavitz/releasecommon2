<?php
namespace SalesIgniter\Common\Block\System\Config\Form\Field;

use Magento\Framework\Data\Form\Element\AbstractElement;
use SalesIgniter\Common\Model\License\AccountLink;
use SalesIgniter\Common\Model\License\Manager;
use SalesIgniter\Common\Model\License\Products;

/**
 * Rentals > Settings > License > Status: what rentalbookingsoftware.com says about the saved key.
 *
 * Active, with its expiry date and days left (or "Lifetime"); expiring within 30 days, with a
 * renew-early link; expired, with the renewal link EDD itself would give; and each way a key can
 * be refused, in words that say what to do about it. "Check now" re-asks immediately; otherwise
 * the answer is at most twelve hours old (Manager::STALE_AFTER).
 */
class LicenseStatus extends \Magento\Config\Block\System\Config\Form\Field
{
    const GREEN = ['#17804b', '#e8f5ee'];
    const AMBER = ['#965e00', '#fdf3e0'];
    const RED = ['#b3271f', '#fcedeb'];
    const GREY = ['#5b5f66', '#eef2f6'];

    private $licenseManager;

    /** @var AccountLink */
    private $accountLink;

    /** @var string admin route of the "Check now" link (each extension points it at its own manager) */
    private $refreshRoute;

    /** @var array route params of the "Check now" link */
    private $refreshParams;

    /**
     * $accountLink is optional and last so an interceptor generated for the old constructor keeps
     * working until the next setup:di:compile.
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        Manager $licenseManager,
        array $data = [],
        ?AccountLink $accountLink = null,
        string $refreshRoute = 'salesigniter_rental/license/refresh',
        array $refreshParams = []
    ) {
        parent::__construct($context, $data);
        $this->refreshRoute = $refreshRoute;
        $this->refreshParams = $refreshParams;
        $this->licenseManager = $licenseManager;
        $this->accountLink = $accountLink
            ?: \Magento\Framework\App\ObjectManager::getInstance()->get(AccountLink::class);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return '<div class="sirent-license" data-role="sirent-license-status">' . $this->panel() . '</div>';
    }

    /** No "use default" checkbox or scope label: this is a read-out, not a setting. */
    protected function _renderInheritCheckbox(AbstractElement $element)
    {
        return '';
    }

    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }

    private function panel(): string
    {
        $status = $this->licenseManager->status();
        if ($status === null) {
            return $this->line(self::GREY, __('No license key'),
                $this->accountLink->sentence(__(
                    'Enter your license key above and save. You can find your keys in %1 on rentalbookingsoftware.com. Updates for Sales Igniter extensions are downloaded with this key.',
                    AccountLink::TOKEN
                )));
        }

        $product = $this->escapeHtml($status['item_name'] ?? '');
        $key = '&hellip;' . $this->escapeHtml($status['key_tail'] ?? '');
        $renew = $this->licenseManager->renewalUrl($status);
        $days = $this->licenseManager->daysLeft($status);
        $sites = isset($status['license_limit'])
            ? ((int)$status['license_limit'] > 0
                ? __('Active on %1 of %2 sites', (int)($status['site_count'] ?? 0), (int)$status['license_limit'])
                : __('Active on %1 sites (unlimited)', (int)($status['site_count'] ?? 0)))
            : '';

        switch ($status['license']) {
            case Manager::VALID:
                if ($days === null) {
                    $html = $this->line(self::GREEN, __('Active'), $product . ' &middot; ' . __('Lifetime license'));
                } elseif ($days <= Manager::EXPIRING_SOON_DAYS) {
                    $html = $this->line(self::AMBER, __('Expires soon'),
                        $product . ' &middot; ' . __('Expires %1 (%2)', $this->date($status['expires']), $this->daysText($days)))
                        . $this->renewLink($renew, __('Renew early'));
                } else {
                    $html = $this->line(self::GREEN, __('Active'),
                        $product . ' &middot; ' . __('Expires %1 (%2)', $this->date($status['expires']), $this->daysText($days)));
                }
                break;
            case Manager::EXPIRED:
                $html = $this->line(self::RED, __('Expired'),
                    $product . ' &middot; ' . __('Expired on %1. Updates and support stop until it is renewed.', $this->date($status['expires'] ?? '')))
                    . $this->renewLink($renew, __('Renew license'));
                break;
            case Manager::INACTIVE:
            case Manager::SITE_INACTIVE:
                $html = $this->line(self::AMBER, __('Not active on this store'),
                    $product . ' &middot; ' . __('Re-enter the key and save to activate it here.'));
                break;
            case 'no_activations_left':
                $html = $this->line(self::RED, __('No activations left'),
                    $product . ' &middot; ' . __('This license is already active on as many sites as it allows. Deactivate an old site from your account on rentalbookingsoftware.com, then save the key again.'));
                break;
            case Manager::DISABLED:
                $html = $this->line(self::RED, __('Disabled'),
                    __('This license has been disabled. Contact Sales Igniter support.'));
                break;
            case Manager::WRONG_PRODUCT:
                $html = $this->line(self::RED, __('Not a Magento license'),
                    $this->accountLink->sentence(__(
                        'That key is valid, but for a different Sales Igniter product. Use the key for your Magento extension from %1.',
                        AccountLink::TOKEN
                    )));
                break;
            case Manager::UNREACHABLE:
                $html = $this->line(self::GREY, __('Not checked'),
                    __('rentalbookingsoftware.com could not be reached. The key is saved; try "Check now" in a moment.'));
                break;
            default:
                // the status code goes in raw: sentence() escapes the whole text once
                $html = $this->line(self::RED, __('Not recognised'),
                    $this->accountLink->sentence(__(
                        'rentalbookingsoftware.com does not recognise this key (%1). Check it against your keys in %2.',
                        (string)$status['license'],
                        AccountLink::TOKEN
                    )));
        }

        $meta = array_filter([
            $sites ? $this->escapeHtml($sites) : '',
            __('Key %1', $key),
            !empty($status['unreachable_at'])
                ? $this->escapeHtml(__('Could not re-check at %1; showing the last answer', $this->time((int)$status['unreachable_at'])))
                : $this->escapeHtml(__('Checked %1', $this->time((int)($status['checked_at'] ?? 0)))),
            '<a href="' . $this->escapeUrl($this->getUrl($this->refreshRoute ?: 'salesigniter_rental/license/refresh', $this->refreshParams ?: [])) . '">' . $this->escapeHtml(__('Check now')) . '</a>',
        ]);

        return $html . '<div style="margin-top:6px;font-size:12px;color:#5b5f66">' . implode(' &middot; ', $meta) . '</div>';
    }

    private function line(array $tone, $label, $text): string
    {
        return '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;line-height:1.6">'
            . '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-weight:600;font-size:12px;color:' . $tone[0] . ';background:' . $tone[1] . '">'
            . $this->escapeHtml($label) . '</span>'
            . '<span>' . $text . '</span></div>';
    }

    private function renewLink(string $url, $label): string
    {
        if ($url === '') {
            return '';
        }
        return '<div style="margin-top:8px"><a class="action-secondary" style="display:inline-block;padding:6px 14px;text-decoration:none"'
            . ' href="' . $this->escapeUrl($url) . '" target="_blank" rel="noopener">' . $this->escapeHtml($label) . '</a></div>';
    }

    private function daysText(int $days): string
    {
        return $days <= 0 ? (string)__('today') : (string)__('%1 days left', $days);
    }

    /**
     * EDD's expiry as EDD states it: its UTC calendar date. Converting to the store's timezone
     * turned "28 Apr 2027 23:59" into 29 Apr on a store east of UTC, a day later than the
     * customer's account on rentalbookingsoftware.com says.
     */
    private function date(string $value): string
    {
        $ts = strtotime($value . ' UTC');
        return $ts === false ? $this->escapeHtml($value)
            : $this->escapeHtml($this->_localeDate->formatDateTime(
                new \DateTime('@' . $ts), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, null, 'UTC'
            ));
    }

    private function time(int $ts): string
    {
        return $ts > 0 ? $this->formatDate(new \DateTime('@' . $ts), \IntlDateFormatter::MEDIUM, true) : '';
    }
}
