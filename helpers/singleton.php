<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:58 PM
 */

trait Singleton {
	static private $instance = null;

	private function __construct() {
	}

	private function __clone() {
	}

	private function __wakeup() {
	}

	static public function getInstance() {
		return
			self::$instance === null
				? self::$instance = new static()//new self()
				: self::$instance;
	}
}