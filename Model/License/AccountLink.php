<?php
namespace SalesIgniter\Common\Model\License;

use Magento\Framework\Escaper;

/**
 * Where a customer finds their license keys: My Account > Licenses on rentalbookingsoftware.com.
 * (The help text used to say "from your order email", which is where a key first arrives but not
 * where anyone can find it again.)
 *
 * The URL is never inside a translatable string. A sentence is translated with TOKEN standing
 * where the link goes, escaped as a whole, and only then is the link markup put in the token's
 * place -- so a translation can neither break the link nor inject markup of its own.
 *
 *   $link->sentence(__('You can find your keys in %1 on rentalbookingsoftware.com.', AccountLink::TOKEN))
 *
 * Admin message-manager messages are escaped, so they carry the URL as plain text instead
 * (Model\Config\Backend\LicenseKey).
 */
class AccountLink
{
    const URL = 'https://rentalbookingsoftware.com/my-account/licenses/';

    const TOKEN = '{{licenses_link}}';

    private $escaper;

    public function __construct(Escaper $escaper)
    {
        $this->escaper = $escaper;
    }

    /** The link itself, opening in a new tab. */
    public function html(): string
    {
        return '<a href="' . $this->escaper->escapeUrl(self::URL) . '" target="_blank" rel="noopener">'
            . $this->escaper->escapeHtml((string)__('My Account > Licenses')) . '</a>';
    }

    /**
     * A translated sentence as safe HTML, with TOKEN replaced by the link.
     *
     * @param \Magento\Framework\Phrase|string $phrase
     */
    public function sentence($phrase): string
    {
        return str_replace(
            $this->escaper->escapeHtml(self::TOKEN),
            $this->html(),
            $this->escaper->escapeHtml((string)$phrase)
        );
    }
}
