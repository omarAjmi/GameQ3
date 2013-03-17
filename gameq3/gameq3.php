<?php
/**
 * This file is part of GameQ3.
 *
 * GameQ3 is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * GameQ3 is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 */
 
/**
 * GameQ3
 *
 * This is a library to query gameservers and return their answer in universal well-formatted array.
 *
 * This library influenced by GameQv1 (author Tom Buskens <t.buskens@deviation.nl>) 
 * and GameQv2 (url https://github.com/Austinb/GameQ, author Austin Bischoff <austin@codebeard.com>)
 *
 * @author Kostya Esmukov <kostya.shift@gmail.com>
 */

namespace GameQ3;

// Autoload classes
spl_autoload_extensions(".php");

// https://bugs.php.net/bug.php?id=51991
if (version_compare(phpversion(), '5.3.3', '<')) {
	spl_autoload_register(
		function ($class) {
			spl_autoload(str_replace('\\', DIRECTORY_SEPARATOR, ltrim($class, '\\')));
		}
	);
} else {
	spl_autoload_register();
}


class GameQ3 {

	// Config
	private $servers_count = 2500;

	// Working vars
	private $sock = null;
	private $log = null;
	private $filters = array();
	private $servers_filters = array();
	private $servers = array();
	
	private $started = false;
	private $request_servers = array();

	public function __construct() {
		$this->log = new Log();
		$this->sock = new Sockets($this->log);
	}
	
	public function setLogLevel($error, $warning = true, $debug = false, $trace = false) {
		$this->log->setLogLevel($error, $warning, $debug, $trace);
	}

	public function setOption($key, $value) {
		if (!is_int($value))
			throw new UserException("Value for setOption must be int. Got value: " . var_export($value, true));

		switch($key) {
			case 'servers_count': $this->servers_count = $value; break;
			
			default:
				$this->sock->setVar($key, $value);
		}
	}
	
	public function setFilter($name, $args = array()) {
		if (!is_array($args))
			throw new UserException("Args must be an array in setFilter (name '" . $name . "')");

		$this->filters[$name] = $args;
	}

	public function unsetFilter($name) {
		unset($this->filters[$name]);
	}

	/**
	 * Add a server to be queried
	 *
	 * @param array $server_info
	 * @throws \GameQ3\UserException
	 */
	public function addServer($server_info) {
		if (!is_array($server_info))
			throw new UserException("Server_info must be an array");
			
		if (!isset($server_info['type']) || !is_string($server_info['type'])) {
			throw new UserException("Missing server info key 'type'");
		}

		if (!isset($server_info['id']) || (!is_string($server_info['id']) && !is_numeric($server_info['id']))) {
			throw new UserException("Missing server info key 'id'");
		}
		
		// already added
		if (isset($this->servers[ $server_info['id'] ]))
			return;

		if (!empty($server_info['filters'])) {
			if (!is_array($server_info['filters']))
				throw new UserException("Server info key 'filters' must be an array");
				
			$this->servers_filters[ $server_info['id'] ] = array();
			// check filters array
			foreach($server_info['filters'] as $filter => &$args) {
				if ($args !== false && !is_array($args))
					throw new UserException("Filter arguments must be an array or boolean false");
				$this->servers_filters[ $server_info['id'] ][ $filter ] = $args;
			}
			
			unset($server_info['filters']);
		}

		$protocol_class = "\\GameQ3\\Protocols\\".ucfirst($server_info['type']);

		try {
			if (!class_exists($protocol_class, true)) // PHP 5.3
				throw new UserException("Class " . $protocol_class . " could not be loaded");
			$this->servers[ $server_info['id'] ] = new $protocol_class($server_info, $this->log);
		}
		catch(\LogicException $e) { // Class not found PHP 5.4
			throw new UserException($e->getMessage());
		}
	}
	
	public function unsetServer($id) {
		unset($this->servers[$id]);
	}

	// addServers removed because you have to decide what to do when exception occurs. This function does not handle them. 
	
	private function _clear() {
		//$this->filters = array();
		//$this->servers_filters = array();
		//$this->servers = array();
		$this->started = false;
		$this->request_servers = array();
	}
	
	public function requestAllData() {
		$result = array();
		while (true) {
			$res = $this->_request();
			if ($res === false || !is_array($res))
				break;
			
			// I hate array_merge. It's like a blackbox.
			foreach($res as $key => $val) {
				$result[$key] = $val;
				unset($res[$key]);
			}
		} 
		return $result;
	}
	
	// returns array until we have servers to reqest. otherwise returns false
	public function requestPartData() {
		return $this->_request();
	}

	private function _applyFilters($key, &$result) {
		$sf = (isset($this->servers_filters[$key]) ? $this->servers_filters[$key] : array());
		foreach($this->filters as $name => $args) {
			if (isset($sf[$name])) {
				$args = $sf[$name];
				unset($sf[$name]);
				if ($args === false) continue;
			}
			$filt = "\\GameQ3\\Filters\\".ucfirst($name);
			
			try {
				class_exists($filt, true); // try to load class
				call_user_func_array($filt . "::filter", array( &$result, $args ));
			}
			catch(\Exception $e) {
				$this->log->warning($e);
			}
		}
		
		foreach($sf as $name => $args) {
			if ($args === false) continue;
			
			$filt = "\\GameQ3\\Filters\\".ucfirst($name);
			
			try {
				class_exists($filt, true); // try to load class
				call_user_func_array($filt . "::filter", array( &$result, $args ));
			}
			catch(\Exception $e) {
				$this->log->warning($e);
			}
		}
	}

	private function _request() {
		if (!$this->started) {
			$this->started = true;
// \/
			foreach($this->servers as &$instance) {
				try {
					$instance->protocolInit();
				}
				catch (\Exception $e) {
					$this->log->warning($e);
				}
			}
// /\ memory allocated 14649/5000=3 kb, 152/50=3 kb

			$this->request_servers = $this->servers;
		}

		if (empty($this->request_servers)) {
			$this->started = false;
			$this->_clear();
			return false;
		}

		$servers_left = array();
		$servers_queried = array();

		$s_cnt = 1;
		foreach($this->request_servers as $server_id => &$instance) {
			$servers_left[$server_id] = $instance;
			$servers_queried[$server_id] = $instance;
			unset($this->request_servers[$server_id]);
			
			$s_cnt++;
			if ($s_cnt > $this->servers_count)
				break;
		}
		
		$process = array();

		while (true) {
			if (empty($servers_left)) break;
			
			$final_process = true;

			foreach($servers_left as $server_id => &$instance) {
				try {
					$instance_queue = $instance->popRequests();

					if (empty($instance_queue)) {
						unset($servers_left[$server_id]);
						continue;
					}
					
					$final_process = false;

					foreach($instance_queue as $queue_id => &$queue_qopts) {
						$sid = $this->sock->allocateSocket($server_id, $queue_id, $queue_qopts);
						$process[$sid] = array(
							'id' => $queue_id,
							'i' => $instance
						);
					}
				}
				catch (SocketsException $e) { // not resolvable hostname, etc
					$this->log->debug($e);
				}
				catch (\Exception $e) { // wrong input data
					$this->log->warning($e);
				}
			}
			
			if ($final_process) {
				$response = $this->sock->finalProcess();
				if (empty($response)) break;
			} else {
				$response = $this->sock->process();
			}

			foreach($response as $sid => $ra) {
				if (empty($ra['p']) || !isset($process[$sid])) continue;

				try { // Protocols should handle exceptions by themselves
					$process[$sid]['i']->startRequestProcessing(
						$process[$sid]['id'],
						array(
							'ping' => $ra['pg'],
							'retry_cnt' => ($ra['t']-1),
							'responses' => $ra['p'],
							'socket_recreated' => $ra['sr'],
							'info' => $ra['i'],
						)
					);
				}
				catch(\Exception $e) {
					$this->log->debug($e);
				}
				unset($response[$sid]);
				unset($process[$sid]);
			}
		}

		$this->sock->cleanUp();
		
		$result = array();
		foreach($servers_queried as $key => &$instance) {
			try {
				$instance->startPreFetch();
			}
			catch(\Exception $e) {
				$this->log->debug($e);
			}
			$result[$key] = $instance->resultFetch();
			$this->_applyFilters($key, $result[$key]);
		}

		return $result;
	}
}

class UserException extends \Exception {}