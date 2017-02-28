<?php
/**
 * Copyright (c) 2015 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test\Traits;

use OC\User\Account;
use OC\User\AccountMapper;
use OC\User\Backend;
use OC\User\User;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;

class MemoryAccountMapper extends AccountMapper {

	private static $accounts = [];
	private static $counter = 1000;

	public $testCaseName = '';

	public function insert(Entity $entity) {
		$entity->setId(self::$counter++);
		self::$accounts[$entity->getId()] = $entity;

		return $entity;
	}

	public function update(Entity $entity) {
		self::$accounts[$entity->getId()] = $entity;

		return $entity;
	}

	public function delete(Entity $entity) {
		unset(self::$accounts[$entity->getId()]);
	}

	public function getByEmail($email) {
		$match = array_filter(self::$accounts, function (Account $a) use ($email) {
			return $a->getEmail() === $email;
		});

		return $match;
	}

	public function getByUid($uid) {
		$match = array_filter(self::$accounts, function (Account $a) use ($uid) {
			return strtolower($a->getUserId()) === strtolower($uid);
		});
		if (empty($match)) {
			throw new DoesNotExistException('');
		}

		return array_values($match)[0];
	}

	public function getUserCount($hasLoggedIn) {
		return count(self::$accounts);
	}

	public function search($fieldName, $pattern, $limit, $offset) {
		$match = array_filter(self::$accounts, function (Account $a) use ($pattern) {
			return stripos($a->getUserId(), $pattern);
		});

		return $match;
	}

	public function callForAllUsers($callback, $search, $onlySeen) {
		foreach (self::$accounts as $account) {
			$return =$callback($account);
			if ($return === false) {
				return;
			}
		}
	}
}

class DummyUserBackend extends Backend implements \OCP\IUserBackend {
	private $users = [];
	private $displayNames = [];

	/**
	 * Create a new user
	 *
	 * @param string $uid The username of the user to create
	 * @param string $password The password of the new user
	 * @return bool
	 *
	 * Creates a new user. Basic checking of username is done in OC_User
	 * itself, not in its subclasses.
	 */
	public function createUser($uid, $password) {
		if (isset($this->users[$uid])) {
			return false;
		} else {
			$this->users[$uid] = $password;
			return true;
		}
	}

	/**
	 * delete a user
	 *
	 * @param string $uid The username of the user to delete
	 * @return bool
	 *
	 * Deletes a user
	 */
	public function deleteUser($uid) {
		if (isset($this->users[$uid])) {
			unset($this->users[$uid]);
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Set password
	 *
	 * @param string $uid The username
	 * @param string $password The new password
	 * @return bool
	 *
	 * Change the password of a user
	 */
	public function setPassword($uid, $password) {
		if (isset($this->users[$uid])) {
			$this->users[$uid] = $password;
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Check if the password is correct
	 *
	 * @param string $uid The username
	 * @param string $password The password
	 * @return string
	 *
	 * Check if the password is correct without logging in the user
	 * returns the user id or false
	 */
	public function checkPassword($uid, $password) {
		if (isset($this->users[$uid]) && $this->users[$uid] === $password) {
			return $uid;
		} else {
			return false;
		}
	}

	/**
	 * Get a list of all users
	 *
	 * @param string $search
	 * @param null|int $limit
	 * @param null|int $offset
	 * @return string[] an array of all uids
	 */
	public function getUsers($search = '', $limit = null, $offset = null) {
		if (empty($search)) {
			return array_keys($this->users);
		}
		$result = [];
		foreach (array_keys($this->users) as $user) {
			if (stripos($user, $search) !== false) {
				$result[] = $user;
			}
		}
		return $result;
	}

	/**
	 * check if a user exists
	 *
	 * @param string $uid the username
	 * @return boolean
	 */
	public function userExists($uid) {
		return isset($this->users[$uid]);
	}

	/**
	 * @return bool
	 */
	public function hasUserListings() {
		return true;
	}

	/**
	 * counts the users in the database
	 *
	 * @return int|bool
	 */
	public function countUsers() {
		return 0;
	}

	public function setDisplayName($uid, $displayName) {
		$this->displayNames[$uid] = $displayName;
	}

	public function getDisplayName($uid) {
		return isset($this->displayNames[$uid])? $this->displayNames[$uid]: $uid;
	}

	/**
	 * Backend name to be shown in user management
	 * @return string the name of the backend to be shown
	 */
	public function getBackendName(){
		return 'Dummy';
	}
}

/**
 * Allow creating users in a temporary backend
 */
trait UserTrait {

	/** @var User[] */
	private $users = [];

	private $previousUserManagerInternals;

	protected function createUser($name, $password = null) {
		if (is_null($password)) {
			$password = $name;
		}
		$userManager = \OC::$server->getUserManager();
		if ($userManager->userExists($name)) {
			$userManager->get($name)->delete();
		}
		$user = $userManager->createUser($name, $password);
		$this->users[] = $user;
		return $user;
	}

	protected function setUpUserTrait() {

		$db = \OC::$server->getDatabaseConnection();
		$accountMapper = new MemoryAccountMapper($db);
		$accountMapper->testCaseName = get_class($this);
		$this->previousUserManagerInternals = \OC::$server->getUserManager()
			->reset($accountMapper, [DummyUserBackend::class => new DummyUserBackend()]);

		if ($this->previousUserManagerInternals[0] instanceof MemoryAccountMapper) {
			throw new \Exception("Missing tearDown call in " . $this->previousUserManagerInternals[0]->testCaseName);
		}
	}

	protected function tearDownUserTrait() {
		foreach($this->users as $user) {
			$user->delete();
		}
		\OC::$server->getUserManager()
			->reset($this->previousUserManagerInternals[0], $this->previousUserManagerInternals[1]);
	}
}
