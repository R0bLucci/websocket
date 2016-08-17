<?php

class Client {

	private $socket,
		$username,
		$handshake;

	public function __construct($socket){
		$this->socket = $socket;
		$this->handshake = false;
		$this->username = null;
	}
	
	public function getHandshake(){
		return $this->handshake;
	}

	public function setUsername($username){
		$this->username = $username;
	}

	public function getUsername(){
		return $this->username;
	}

	public function getSocket(){
		return $this->socket;
	}
		
	public function isSocket($socket){
		if($this->socket === $socket){
			return true;
		}
		return false;
	}

	public function setHandshake($complete){
		$this->handshake = $complete;
	}
}
