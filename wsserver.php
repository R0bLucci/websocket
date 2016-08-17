<?php
//require_once "client.php";
//require_once "session.php";

class WsServer {

	// set some variables
	private $host,
		$port, 
		$master_socket,
		$sockets, // list of sockets 
		$clients, // list of clients objects
		$username,
		$verbose;

	public function __construct($username, $host = "192.168.1.97", $port = 9999, $verbose = true){
		// don't timeout!
		set_time_limit(0);
		$this->verbose = $verbose;
		// create socket
		$tmp_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if(!$tmp_socket){
			$this->throwException("Could not create socket");
		}	
		// set socket option		
		socket_set_option($tmp_socket, SOL_SOCKET, SO_REUSEADDR, 1);	

		// bind socket to port
		$result = @socket_bind($tmp_socket, $host, $port);
		if(!$result && !(socket_last_error() == 98)){	
			$this->throwException("Could not bind socket");
		}

		$result = socket_listen($tmp_socket, 10);
		if(!$result){
			$this->throwException("Could not listen socket");
		}	
		$this->master_socket = $tmp_socket;
		$this->host = $host;
		$this->port = $port;
		$this->clients = array();
		$this->sockets = array($this->master_socket);
		$this->username = $username;

		$this->logger("Master socket created: ".$this->master_socket);
		$this->logger("Wellcome to my custom socket");
	}
	
	public function setUsername($username){
		$this->username = $username;
	}
	
	public function start(){
		$pid = pcntl_fork();
		if($pid == -1){
			die("Could not initiate process");
		}else if($pid){
			//parent	
		}else{			
			$this->run();
		}
	}

	public function run(){
		$null = null;
		do{
			$read = $this->sockets;
			if($read){
				$this->logger("Sockets to read: ".count($read));
				$this->logger(print_r($read));
				socket_select($read, $null, $null, 1);
				
				$this->logger("Something happened to sockets");		
				foreach($read as $key => $socket){
					$this->logger("Socket with new event ".$socket);
					if($socket == $this->master_socket){
						$this->connectClient();
						$this->logger("Consuming socket: {$read[$key]}");
						unset($read[$key]);
					}else{	
						$client = $this->getClientBySocket($socket);		
						if(!$client){
							//Very corner case 
							// read socket to prevent socket_select() to return the same
							// socket again and again creating an infinite loop	
							while($bytes = socket_recv($socket, $tmp_data, 2048, MSG_DONTWAIT)){
								$msg .= $tmp_data;
							}
							$this->disconnectSocket($socket);
							unset($read[$key]);
							continue;
						}
							
						$this->logger("Found client");
						$results = $this->readClientSocket($client);		
						$msg = $results["msg"];
						$bytes = $results["bytes"];
						// establish first connection with client
						// upgrading HTTP protocol to ws protocol 
						if(!$client->getHandshake()){
							$this->logger("Initialize handshake");
							if(!$this->handshake($client, $msg)){
								$this->logger("Handshake incomplete ".
								"disconnecting client: {$client->getSocket()}");
								$this->disconnectClient($client);
								unset($read[$key]);
								
							}else{ //success	
								$this->logger("Client success : $socket");
								unset($read[$key]);	
							}

						}else if($bytes === 0){ // Client disconnect
							$this->logger("Disconnect client");
							$this->disconnectClient($client);
							unset($read[$key]);	
						}else{ 	
							// Client has a message to send 
							$this->send($msg, $client);	
						}
					}
				}	
			}	
		}while(true);
	}
	
	private function send($msg, $sender){
		foreach($this->clients as $client){
			if($client !== $sender){
				$this->logger($sender->getUsername().": ". $this->unmask($msg));
				socket_write($client->getSocket(), $msg."\r\n", strlen($msg));
				//socket_sendto($client->getSocket(), $msg, strlen($msg), 0, $this->host, $this->port);
				//socket_sendmsg($client->getSocket(), [$msg], 0);
				//socket_send($client->getSocket(), $msg, strlen($msg), 0);
			}	
		}
	}	

	private function getClientBySocket($client_socket){
		foreach($this->clients as $key => $client){
			if($client->isSocket($client_socket)){
				return $this->clients[$key];
			}
		}
		return null;
	}

	private function disconnectDuplicate($client){
		$cacheKeys = array();
		$limit = 0;
		$this->logger("Checking duplicate user clients");
		foreach($this->clients as $key => $c){
			if($c->getUsername() === $client->getUsername()){
				$limit++;
				$cacheKeys[] = $key;
				$this->logger("Username: {$c->getUsername()} occurences: $limit");
			}	
		}
		
		if($limit < 2){
			return;	
		}

		$index = min($cacheKeys);
		$client_to_delete = $this->clients[$index];
		$this->logger("Found duplicate client: {$client_to_delete->getUsername()} socket:{$client_to_delete->getSocket()}");
		$this->disconnectClient($client_to_delete);
	}

	private function connectClient(){
		$this->logger("Creating client socket");
		$client_socket = socket_accept($this->master_socket);		
		if(!$client_socket){
			$this->logger("Client socket error: $client_socket");
			return;
		}
		$client = new Client($this->username /*Session::get("username")*/, $client_socket);
		$this->sockets[] = $client_socket;
		$this->clients[] = $client;
	}		

	private function handshake($client, $header){
		$version = "";
		if(preg_match("/Sec-WebSocket-Version: (.*)\r\n/", $header, $match_version)){
			$version = $match_version[1];
		}else{
			// client does not support web socket
			$this->logger("Client does not suppoer web socket protocol [Version]");
			return false;
		}
	

		if($version !== "13"){		
			$this->logger("Version does not match 13. Client version: $version");
			// version is not equal to 13
			return false;
		}

		$key = "";
		if(preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $header, $match_key)){
			$key = $match_key[1];
		}else{
			// client does not support web socket
			$this->logger("Client does not suppoer web socket protocol [Key]");
			return false;
		}
		

		if($key === ""){
			$this->logger("Client key not set.");
			return false;
		}

		$successKey = $key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

		$successKey = base64_encode(sha1($successKey, true));
			
		$upgrade = "HTTP/1.1 101 Switching Protocols\r\n".
				"Upgrade: websocket\r\n".
				"Connection: Upgrade\r\n".
				"Sec-WebSocket-Accept: $successKey\r\n".
				"\r\n"; // end header with blank line 

		$client_socket = $client->getSocket();
		$result = socket_write($client_socket, $upgrade);

		if($result === false || $result < 1){
			$this->logger("Write to socket: $client_socket, unsuccessful");
			return false;
		}

		$client->setHandshake(true);
		return true;	
	}

	private function readClientSocket($client){
		$msg = null;
		$client_socket = $client->getSocket();

		while($bytes = socket_recv($client_socket, $tmp_data, 2048, MSG_DONTWAIT)){
			$this->logger("Bytes in loop: $bytes");
			$msg .= $tmp_data;
		}
		$this->logger("Bytes out loop: $bytes");

		$this->logger("Client msg: $client_socket");
	
		return ["msg" => $msg, "bytes" => $bytes];
	}
	
	private function disconnectClient($client){
		$i = array_search($client, $this->clients);
		$client_socket = $client->getSocket();
		$this->disconnectSocket($client_socket);

		if($i >= 0){
			array_splice($this->clients, $i, 1);
		}
	}	
	
	private function disconnectSocket($socket){
		$i = array_search($socket, $this->sockets);	
		if($i >= 0){
			array_splice($this->sockets, $i, 1);
			socket_shutdown($socket, 2);
			socket_close($socket);
		}
	}

	public function close(){
		$this->logger("Server closing");
		foreach($this->clients as $client){
			socket_shutdown($client->getSocket(), 2);
			socket_close($client->getSocket());
		}
		unset($this->sockets);
		unset($this->clients);
		socket_shutdown($this->master_socket, 2);
		socket_close($this->master_socket);
	}

	private function displayClientInfo($client_socket){
		if(socket_getpeername($client_socket, $address, $port)){
			echo "Client addr: $address Client port: $port \r\n";
		}
	}

	private function throwException($msg =""){
		throw new Exception($msg . " " .socket_strerror(socket_last_error()));
	}

	private function encode($message, $messageType='text') {
		switch ($messageType) {
			case 'continuous':
				$b1 = 0;
				break;
			case 'text':
				$b1 = 1;
				break;
			case 'binary':
				$b1 = 2;
				break;
			case 'close':
				$b1 = 8;
				break;
			case 'ping':
				$b1 = 9;
				break;
			case 'pong':
				$b1 = 10;
				break;
		}

		$b1 += 128;
		$length = strlen($message);
		$lengthField = "";
		if($length < 126) {
			$b2 = $length;
		} elseif($length <= 65536) {
			$b2 = 126;
			$hexLength = dechex($length);
			//$this->stdout("Hex Length: $hexLength");
			if(strlen($hexLength)%2 == 1) {
				$hexLength = '0' . $hexLength;
			}
			$n = strlen($hexLength) - 2;
			for($i = $n; $i >= 0; $i=$i-2) {
				$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
			}
			while(strlen($lengthField) < 2) {
				$lengthField = chr(0) . $lengthField;
			}
		} else {
			$b2 = 127;
			$hexLength = dechex($length);
			if(strlen($hexLength) % 2 == 1) {
				$hexLength = '0' . $hexLength;
			}
			$n = strlen($hexLength) - 2;
			for($i = $n; $i >= 0; $i = $i - 2) {
				$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
			}
			while(strlen($lengthField) < 8) {
				$lengthField = chr(0) . $lengthField;
			}
		}
		return chr($b1) . chr($b2) . $lengthField . $message;
	}

 
	private function unmask($payload) {
		$length = ord($payload[1]) & 127;
		if($length == 126) {
			$masks = substr($payload, 4, 4);
			$data = substr($payload, 8);
		}
		elseif($length == 127) {
			$masks = substr($payload, 10, 4);
			$data = substr($payload, 14);
		}
		else {
			$masks = substr($payload, 2, 4);
			$data = substr($payload, 6);
		}
		$text = '';
		for($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i%4];
		}
		return $text;
	}

	private function logger($msg){
		if(!$this->verbose){
			return;
		}	
		echo "$msg \r\n";
	}	
}
//$ws = new WsServer();
//$ws->run();
//$ws->stop();
