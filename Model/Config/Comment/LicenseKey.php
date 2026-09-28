<?php
namespace SalesIgniter\Common\Model\Config\Comment;

use Magento\Config\Model\Config\CommentInterface;
use SalesIgniter\Common\Model\License\AccountLink;

/**
 * The note under Rentals > Settings > License > License key, with My Account > Licenses as a link.
 * A comment model rather than a <comment> string so the URL is not part of the translated text
 * (see AccountLink).
 */
class LicenseKey implements CommentInterface
{
    private $accountLink;

    public function __construct(AccountLink $accountLink)
    {
        $this->accountLink = $accountLink;
    }

    /**
     * @param string $elementValue the saved (encrypted) key; not used
     * @return string HTML
     */
    public function getCommentText($elementValue)
    {
        return $this->accountLink->sentence(__(
            'You can find your keys in %1 on rentalbookingsoftware.com. Saving activates the key on this store; your Sales Igniter updates are downloaded with the same key.',
            AccountLink::TOKEN
        ));
    }
}
