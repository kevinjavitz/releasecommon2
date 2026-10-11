<?php
/**
 * A throwaway admin user for config-search.e2e.js. Needs nothing but Magento itself (no rental module).
 *
 *   CS_ACTION=setup    CS_ADMIN_USER=<name> CS_ADMIN_PASS=<password> php config-search-fixtures.php
 *   CS_ACTION=teardown CS_ADMIN_USER=<name>                          php config-search-fixtures.php
 *
 * The password comes from the environment and is never printed. setup gives the user the role that holds
 * Magento_Backend::all (the store's full administrators role) and prints one JSON line: the user id, the rental
 * sections (tab `salesigniter`) and a few of their fields the test can search for. teardown deletes the user (its
 * sessions and saved group states go with it). Nothing else is written: no settings, no products.
 */
declare(strict_types=1);

$root = getenv('MAGE_ROOT') ?: '/var/www/html';
require $root . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$state = $om->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // already set
}
$om->configure(
    $om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load(\Magento\Framework\App\Area::AREA_ADMINHTML)
);

$out = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
};
$username = (string)getenv('CS_ADMIN_USER');
if ($username === '' || !preg_match('/^[a-z0-9_-]{6,40}$/i', $username)) {
    $out(['error' => 'CS_ADMIN_USER missing or not a plain name']);
    exit(2);
}

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$conn = $resource->getConnection();

switch ((string)getenv('CS_ACTION')) {
    case 'setup':
        $password = (string)getenv('CS_ADMIN_PASS');
        if (strlen($password) < 12) {
            $out(['error' => 'CS_ADMIN_PASS missing or short']);
            exit(2);
        }
        $old = $om->create(\Magento\User\Model\User::class)->loadByUsername($username);
        if ($old->getId()) {
            $old->delete();
        }
        $roleId = (int)$conn->fetchOne(
            $conn->select()->from($resource->getTableName('authorization_rule'), ['role_id'])
                ->where('resource_id = ?', 'Magento_Backend::all')->where('permission = ?', 'allow')
                ->order('role_id ASC')->limit(1)
        );
        if (!$roleId) {
            $out(['error' => 'no role holds Magento_Backend::all']);
            exit(2);
        }
        $user = $om->create(\Magento\User\Model\User::class);
        $user->setData([
            'username' => $username, 'firstname' => 'Settings', 'lastname' => 'Search',
            'email' => $username . '@example.com', 'password' => $password, 'is_active' => 1,
            'interface_locale' => 'en_US',
        ]);
        $user->setRoleId($roleId);
        $user->save();

        // what the test may expect: the rental sections this install has, in the order of the left column
        $structure = $om->get(\Magento\Config\Model\Config\Structure\Data::class)->get();
        $sections = [];
        foreach ($structure['sections'] ?? [] as $id => $section) {
            if (($section['tab'] ?? '') === 'salesigniter') {
                $sections[] = ['id' => $id, 'label' => (string)($section['label'] ?? ''),
                    'sort' => (int)($section['sortOrder'] ?? 0), 'groups' => count($section['children'] ?? [])];
            }
        }
        usort($sections, static fn ($a, $b) => $a['sort'] <=> $b['sort']);
        $out(['user_id' => (int)$user->getId(), 'role_id' => $roleId, 'sections' => $sections]);
        break;

    case 'teardown':
        $user = $om->create(\Magento\User\Model\User::class)->loadByUsername($username);
        $id = (int)$user->getId();
        if ($id) {
            $user->delete();
        }
        $out(['deleted' => $id]);
        break;

    default:
        $out(['error' => 'CS_ACTION must be setup or teardown']);
        exit(2);
}
