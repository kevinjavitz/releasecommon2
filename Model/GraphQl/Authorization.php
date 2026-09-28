<?php
/**
 * ACL checks for the back-office half of a Sales Igniter GraphQL schema (rental, RFQ, ...).
 *
 * Moved here from releaserental2 in 1.2.57; rental keeps a subclass with its own resource constants
 * and helper lists, so this class holds only the generic checks.
 *
 * The graphql area already wires Magento\Webapi\Model\Authorization\TokenUserContext and
 * Magento\Webapi\Model\WebapiRoleLocator (see Magento_GraphQl's etc/graphql/di.xml), so an
 * admin or integration bearer token arrives here with its role resolved and
 * AuthorizationInterface answers against the same ACL tree the admin menu uses. Nothing
 * extra has to be configured on the install: a token that can reach /rest/V1 can reach
 * these fields.
 *
 * A customer token, or no token at all, resolves to a role with none of these resources,
 * which is what we want — every caller is refused by the same code path.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\GraphQl;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\Phrase;

class Authorization
{
    /** @var AuthorizationInterface */
    private $authorization;

    public function __construct(AuthorizationInterface $authorization)
    {
        $this->authorization = $authorization;
    }

    /**
     * Refuse the field unless the caller's role holds $resource.
     *
     * @param string $resource an ACL resource id from etc/acl.xml
     * @param string $field the GraphQL field name, so the message says what was refused
     * @return void
     * @throws GraphQlAuthorizationException
     */
    public function assert(string $resource, string $field): void
    {
        if (!$this->isAllowed($resource)) {
            throw new GraphQlAuthorizationException(
                new Phrase(
                    'The current user is not authorized to use "%1". It needs the "%2" resource, '
                    . 'which is granted to an admin or integration token.',
                    [$field, $resource]
                )
            );
        }
    }

    /**
     * Refuse the field unless the caller holds at least one of $resources.
     *
     * This is for field resolvers on a type that more than one gated query can return.
     * RentalReservation is the case that forces it: rentalReservations gates on
     * ::manualedit, rentalOrders on ::rentalcal, and the send/return mutations on ::send
     * and ::return. A sub-field of that type that insisted on ::manualedit alone would
     * refuse a token that had legitimately reached the reservation through one of the
     * other three — the sub-field must not be a narrower gate than the field that
     * produced its parent.
     *
     * @param string[] $resources
     * @param string $field
     * @return void
     * @throws GraphQlAuthorizationException
     */
    public function assertAny(array $resources, string $field): void
    {
        if (!$this->isAllowedAny($resources)) {
            throw new GraphQlAuthorizationException(
                new Phrase(
                    'The current user is not authorized to use "%1". It needs one of the '
                    . '"%2" resources, which are granted to an admin or integration token.',
                    [$field, implode('", "', $resources)]
                )
            );
        }
    }

    /**
     * Whether the caller holds $resource, without throwing.
     *
     * @param string $resource
     * @return bool
     */
    public function isAllowed(string $resource): bool
    {
        try {
            return (bool)$this->authorization->isAllowed($resource);
        } catch (\Exception $e) {
            // A missing role locator (no token at all) surfaces as an exception rather
            // than false on some setups; either way the answer is no.
            return false;
        }
    }

    /**
     * Whether the caller holds any of $resources — used where one field serves two screens.
     *
     * @param string[] $resources
     * @return bool
     */
    public function isAllowedAny(array $resources): bool
    {
        foreach ($resources as $resource) {
            if ($this->isAllowed($resource)) {
                return true;
            }
        }

        return false;
    }
}
