<?php
/**
 * pageSize / currentPage handling shared by every paged rental field.
 *
 * Magento's own list fields validate these two the same way and it is worth matching, so
 * that a client written against products behaves the same against serials: pageSize is
 * bounded (an unbounded one is a cheap way to ask for the whole table), currentPage is
 * 1-based, and asking for a page past the end is an error rather than a silent empty list
 * — otherwise a paging loop with an off-by-one never terminates visibly.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\GraphQl;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\Phrase;

class Pagination
{
    public const MAX_PAGE_SIZE = 200;

    /**
     * Validate the pair and hand back [pageSize, currentPage].
     *
     * @param array $args the resolver's raw args
     * @param int $defaultPageSize
     * @return int[] [pageSize, currentPage]
     * @throws GraphQlInputException
     */
    public function fromArgs(array $args, int $defaultPageSize = 20): array
    {
        $pageSize = isset($args['pageSize']) ? (int)$args['pageSize'] : $defaultPageSize;
        $currentPage = isset($args['currentPage']) ? (int)$args['currentPage'] : 1;

        if ($pageSize < 1) {
            throw new GraphQlInputException(
                new Phrase('pageSize value must be greater than 0.')
            );
        }
        if ($pageSize > self::MAX_PAGE_SIZE) {
            throw new GraphQlInputException(
                new Phrase('pageSize value must not be greater than %1.', [self::MAX_PAGE_SIZE])
            );
        }
        if ($currentPage < 1) {
            throw new GraphQlInputException(
                new Phrase('currentPage value must be greater than 0.')
            );
        }

        return [$pageSize, $currentPage];
    }

    /**
     * The page_info block, after checking the page asked for exists.
     *
     * @param int $totalCount rows matching the filter
     * @param int $pageSize
     * @param int $currentPage
     * @return array
     * @throws GraphQlInputException currentPage is past the last page
     */
    public function pageInfo(int $totalCount, int $pageSize, int $currentPage): array
    {
        $totalPages = $totalCount === 0 ? 0 : (int)ceil($totalCount / $pageSize);

        if ($currentPage > $totalPages && $totalCount > 0) {
            throw new GraphQlInputException(
                new Phrase(
                    'currentPage value %1 specified is greater than the number of pages available (%2).',
                    [$currentPage, $totalPages]
                )
            );
        }

        return [
            'page_size' => $pageSize,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
        ];
    }
}
