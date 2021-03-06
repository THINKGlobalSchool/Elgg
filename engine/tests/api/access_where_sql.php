<?php
/**
 * Access SQL tests
 *
 * @package Elgg
 * @subpackage Test
 */
class ElggAccessSQLTest extends ElggCoreUnitTest {
	/**
	 * Called before each test object.
	 */
	public function __construct() {
		parent::__construct();

		$this->objects = array();
		$this->users = array();
		$this->groups = array();

		// Core access
		$this->access_array = array(
			ACCESS_PRIVATE,
			ACCESS_FRIENDS,
			ACCESS_LOGGED_IN,
			ACCESS_PUBLIC
		);

		// Create a couple users and create objects
		for ($i=0; $i<2; $i++) {
			$user = new ElggUser();
			$user->username = 'fake_user_' . rand();
			$user->email = 'fake_email@fake.com' . rand();
			$user->name = 'fake user ' . rand();
			$user->access_id = ACCESS_PUBLIC;
			$user->salt = generate_random_cleartext_password();
			$user->password = generate_user_password($user, rand());
			$user->owner_guid = 0;
			$user->container_guid = 0;
			$user->save();
			$this->users[] = $user;

			foreach($this->access_array as $access) {
				$object = new ElggObject();
				$object->access_id = $access;
				$object->owner_guid = $user->guid;
				$object->container_guid = $user->guid;
				$object->save();

				$this->objects[$user->guid][$access] = $object;
			}
		}

		// Placeholder for group ACL's
		define('ACCESS_GROUP_ACL', -1234567);

		// Available group access ids
		$this->group_access_array = array(
			ACCESS_PUBLIC,
			ACCESS_LOGGED_IN,
			ACCESS_GROUP_ACL,
		);

		// Open/closed group membership
		$this->group_membership_array = array(
			ACCESS_PRIVATE,
			ACCESS_PUBLIC
		);

		// Create groups, one for each group access id, and membership
		foreach ($this->group_access_array as $group_access) {
			foreach ($this->group_membership_array as $group_membership) {
				$group = new ElggGroup();
				$group->membership = $group_membership;

				if ($group_access == ACCESS_GROUP_ACL) {
					$group->save();
					$group->access_id = $group->group_acl;
				} else {
					$group->access_id = $group_access;
				}
				$group->save();

				$this->groups[] = $group;
			}
		}

		// Create access collection, owned by second test user
		$acl_id = create_access_collection('test acl');
		add_user_to_access_collection($this->users[1]->guid, $acl_id);
		$this->test_acl_id = $acl_id;

		// Create object with access_id set to above ACL, owned by user two
		$object = new ElggObject();
		$object->access_id = $acl_id;
		$object->owner_guid = $this->users[1]->guid;
		$object->container_guid = $this->users[1]->guid;
		$object->save();
		$this->test_acl_object = $object;

		// Create a dummy 'role' object
		$superuser = new ElggObject();
		$superuser->access_id = ACCESS_PUBLIC;
		$superuser->owner_guid = elgg_get_logged_in_user_guid();
		$superuser->container_guid = elgg_get_logged_in_user_guid();
		$superuser->title = "Superuser Role";
		$superuser->save();
		$this->superuser_role = $superuser;
	}

	/**
	 * Called before each test method.
	 */
	public function setUp() {}

	/**
	 * Called after each test method.
	 */
	public function tearDown() {}

	/**
	 * Called after each test object.
	 */
	public function __destruct() {
		// Delete users/objects
		foreach ($this->users as $user) {
			$user->delete();
		}

		// Delete groups
		foreach ($this->groups as $group) {
			$group->delete();
		}

		// Delete test acl and test acl object
		$this->test_acl_object->delete();
		delete_access_collection($this->test_acl_id);

		// Delete role entity
		$this->superuser_role->delete();

		// all __destruct() code should go above here
		parent::__destruct();
	}

	/**
	 * Test core access ids
	 */
	public function testAccessIDs() {
		$user_one = $this->users[0];
		$user_two = $this->users[1];

		// Log in test user
		$admin = elgg_get_logged_in_user_entity();
		$_SESSION['user'] = $user_one;

		foreach ($this->access_array as $access) {
			// User can access their own objects regardless of access level
			$this->assertTrue(has_access_to_entity($this->objects[$user_one->guid][$access], $user_one));

			// Test access to other user's objects
			switch ($access) {
				case ACCESS_PRIVATE:
					// Can't access other user's private object
					$this->assertFalse(has_access_to_entity($this->objects[$user_two->guid][$access], $user_one));
					break;
				case ACCESS_FRIENDS:
					// Not friend's, can't access
					$this->assertFalse(has_access_to_entity($this->objects[$user_two->guid][$access], $user_one));
					
					// Make friends
					add_entity_relationship($user_two->guid, "friend", $user_one->guid);

					// Friends relationship exists, user now has access
					$this->assertTrue(has_access_to_entity($this->objects[$user_two->guid][$access], $user_one));

					// Clean up
					remove_entity_relationship($user_two->guid, "friend", $user_one->guid);
					break;
				case ACCESS_LOGGED_IN:
				case ACCESS_PUBLIC:
					// Check access to logged in/public
					$this->assertTrue(has_access_to_entity($this->objects[$user_two->guid][$access], $user_one));
					break;
			}
		}

		$_SESSION['user'] = $admin;
	}

	public function testGetAccessSqlSuffixHookCoreAccess() {
		$user_one = $this->users[0];
		$user_two = $this->users[1];

		global $superuser_role;
		$superuser_role = $this->superuser_role;

		// test hook
		function access_get_sql_suffix_test_core_hook($hook, $type, $value, $params) {
			global $superuser_role;

			$dbprefix = elgg_get_config('dbprefix');

			$table_alias = $params['table_alias'];
			$owner_guid_column = $params['owner_guid_column'];
			$user_guid = $params['user_guid'];
			$role_guid = $superuser_role->guid;

			$value['ors'][] = "{$user_guid} IN (
				SELECT guid_one FROM {$dbprefix}entity_relationships
				WHERE relationship='belongs_to' AND guid_two={$role_guid}
			)";

			return $value;
		}

		// Make test user a member of the superuser role
		add_entity_relationship($user_one->guid, "belongs_to", $this->superuser_role->guid);

		// Register hook handler
		elgg_register_plugin_hook_handler('get_sql', 'access', 'access_get_sql_suffix_test_core_hook');

		// Log in test user
		$admin = elgg_get_logged_in_user_entity();
		$_SESSION['user'] = $user_one;

		foreach ($this->access_array as $access) {
			// Can access other user's entities regardless of access_id
			$this->assertTrue(has_access_to_entity($this->objects[$user_two->guid][$access], $user_one));
		}

		$_SESSION['user'] = $admin;

		// Remove superuser role
		remove_entity_relationship($user_one->guid, "belongs_to", $this->superuser_role->guid);

		// Unregister hook
		elgg_unregister_plugin_hook_handler('get_sql', 'access', 'access_get_sql_suffix_test_core_hook');
	}

	public function testGetAccessSqlSuffixHookAccessCollections() {
		$user_one = $this->users[0];		
		$user_two = $this->users[1];

		// User can't access entity while not a member of the ACL
		$this->assertFalse(has_access_to_entity($this->test_acl_object, $user_one));
		
		// Add user_one to ACL 
		add_user_to_access_collection($user_one->guid, $this->test_acl_id);

		// Flush access cache
		$cache = _elgg_get_access_cache();
		$cache->clear();

		// Is a member, now has access
		$this->assertTrue(has_access_to_entity($this->test_acl_object, $user_one));

		// Remove user from ACL
		remove_user_from_access_collection($user_one->guid, $this->test_acl_id);

		// Flush access cache
		$cache = _elgg_get_access_cache();
		$cache->clear();

		// No longer a member, no access
		$this->assertFalse(has_access_to_entity($this->test_acl_object, $user_one));

		// Flush access cache
		$cache = _elgg_get_access_cache();
		$cache->clear();

		// Add superuser relationship
		add_entity_relationship($user_one->guid, "belongs_to", $this->superuser_role->guid);

		global $superuser_role;
		$superuser_role = $this->superuser_role;

		// test hook
		function access_get_sql_suffix_test_acl_hook($hook, $type, $value, $params) {
			global $superuser_role;

			$dbprefix = elgg_get_config('dbprefix');

			$table_alias = $params['table_alias'];
			$owner_guid_column = $params['owner_guid_column'];
			$user_guid = $params['user_guid'];
			$role_guid = $superuser_role->guid;

			$value['ors'][] = "{$user_guid} IN (
				SELECT guid_one FROM {$dbprefix}entity_relationships
				WHERE relationship='belongs_to' AND guid_two={$role_guid}
			)";

			return $value;
		}

		// Register hook handler
		elgg_register_plugin_hook_handler('get_sql', 'access', 'access_get_sql_suffix_test_acl_hook');

		// Log in test user
		$admin = elgg_get_logged_in_user_entity();
		$_SESSION['user'] = $user_one;

		// Can access other user's entities without ACL membership
		$this->assertTrue(has_access_to_entity($this->test_acl_object, $user_one));

		$_SESSION['user'] = $admin;

		// Remove superuser relationship
		remove_entity_relationship($user_one->guid, "belongs_to", $this->superuser_role->guid);

		// Unregister hook
		elgg_unregister_plugin_hook_handler('get_sql', 'access', 'access_get_sql_suffix_test_acl_hook');
	}

	/**
	 * Check group access based on each combination of membership/access_id
	 */
	public function assertGroupAccess($group, $register_hook = false) {
		// Get admin user to switch out sessions
		$admin = elgg_get_logged_in_user_entity();

		$membership = $group->membership;
		$access = $group->access_id;

		if (!in_array($access, $this->group_access_array)) {
			$access = ACCESS_GROUP_ACL;
		}

		// Log in test user
		$_SESSION['user'] = $this->users[0];

		$logged_in_user = elgg_get_logged_in_user_entity();

		// If register_hook is set, register the test hook
		if ($register_hook) {
			// Register hook
			elgg_register_plugin_hook_handler('get_sql', 'access', 'access_get_sql_suffix_test_groups_hook');

			// User will have access the group regardless of it's access_id
			$this->assertTrue(has_access_to_entity($group, $logged_in_user));

			// canWriteToContainer will still return false if the user isn't a member.
			// To modify that behaviour you'd want to use the permissions_check hook
			if ($group->isMember()) {
				$this->assertTrue($group->canWriteToContainer($logged_in_user->guid));
			} else {
				$this->assertFalse($group->canWriteToContainer($logged_in_user->guid));
			}

			// Unregister
			elgg_unregister_plugin_hook_handler('get_sql', 'access', 'access_get_sql_suffix_test_groups_hook');

		} else if ($membership == ACCESS_PUBLIC && $access == ACCESS_PUBLIC) {

			// Public membership & public access
			$this->assertTrue(has_access_to_entity($group, $logged_in_user));

			if ($group->isMember($logged_in_user))  {
				$this->assertTrue($group->canWriteToContainer($logged_in_user->guid));
			} else {
				$this->assertFalse($group->canWriteToContainer($logged_in_user->guid));
			}

		} else if ($membership == ACCESS_PRIVATE && $access == ACCESS_PUBLIC) {

			// Private membership & public access
			$this->assertTrue(has_access_to_entity($group, $logged_in_user));

			if ($group->isMember($logged_in_user))  {
				$this->assertTrue($group->canWriteToContainer($logged_in_user->guid));
			} else {
				$this->assertFalse($group->canWriteToContainer($logged_in_user->guid));
			}

		} else if ($membership == ACCESS_PUBLIC && $access == ACCESS_LOGGED_IN) {

			// Public membership & logged in access
			$this->assertTrue(has_access_to_entity($group, $logged_in_user));

			if ($group->isMember($logged_in_user))  {
				$this->assertTrue($group->canWriteToContainer($logged_in_user->guid));
			} else {
				$this->assertFalse($group->canWriteToContainer($logged_in_user->guid));
			}

		} else if ($membership == ACCESS_PRIVATE && $access == ACCESS_LOGGED_IN) {

			// Private membership & logged in access
			$this->assertTrue(has_access_to_entity($group, $logged_in_user));

			if ($group->isMember($logged_in_user))  {
				$this->assertTrue($group->canWriteToContainer($logged_in_user->guid));
			} else {
				$this->assertFalse($group->canWriteToContainer($logged_in_user->guid));
			}

		} else if ($membership == ACCESS_PUBLIC && $access == ACCESS_GROUP_ACL) {

			// Public membership & group acl access
			if ($group->isMember($logged_in_user))  {
				$this->assertTrue(has_access_to_entity($group, $logged_in_user));
				$this->assertTrue($group->canWriteToContainer($logged_in_user->guid));
			} else {
				$this->assertFalse(has_access_to_entity($group, $logged_in_user));
				$this->assertFalse($group->canWriteToContainer($logged_in_user->guid));
			}

		} else if ($membership == ACCESS_PRIVATE && $access == ACCESS_GROUP_ACL) {

			// Private membership & group acl access
			if ($group->isMember($logged_in_user))  {
				$this->assertTrue(has_access_to_entity($group, $logged_in_user));
				$this->assertTrue($group->canWriteToContainer($logged_in_user->guid));
			} else {
				$this->assertFalse(has_access_to_entity($group, $logged_in_user));
				$this->assertFalse($group->canWriteToContainer($logged_in_user->guid));
			}

		}

		// Log in admin
		$_SESSION['user'] = $admin;
	}

	public function testGetAccessSqlSuffixHookGroups() {
		$user_one = $this->users[0];

		// Add superuser relationship
		add_entity_relationship($user_one->guid, "belongs_to", $this->superuser_role->guid);

		global $superuser_role;
		$superuser_role = $this->superuser_role;

		// Declare test hook
		function access_get_sql_suffix_test_groups_hook($hook, $type, $value, $params) {
			global $superuser_role;

			$dbprefix = elgg_get_config('dbprefix');

			$table_alias = $params['table_alias'];
			$owner_guid_column = $params['owner_guid_column'];
			$user_guid = $params['user_guid'];
			$role_guid = $superuser_role->guid;

			$value['ors'][] = "{$user_guid} IN (
				SELECT guid_one FROM {$dbprefix}entity_relationships
				WHERE relationship='belongs_to' AND guid_two={$role_guid}
			)";

			return $value;
		}

		foreach ($this->groups as $group) {
			// Test without hook
			$this->assertGroupAccess($group);

			// Test with hook
			$this->assertGroupAccess($group, true);

			// Join group
			$group->join($user_one);

			// Flush access cache
			$cache = _elgg_get_access_cache();
			$cache->clear();

			// Test without hook
			$this->assertGroupAccess($group);

			// Test with hook
			$this->assertGroupAccess($group, true);

			// Remove user from group for good measure
			$group->leave($user_one);

			// Flush access cache
			$cache = _elgg_get_access_cache();
			$cache->clear();
		}

		// Remove superuser relationship
		remove_entity_relationship($user_one->guid, "belongs_to", $this->superuser_role->guid);
	}
}