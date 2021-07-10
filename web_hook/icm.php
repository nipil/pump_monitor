<?php
/*
 ****************************************************************************
 */
function curl_post_json($url, $json) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$retries = 5;
	$wait_sec = 1;
	$result = curl_exec($ch);
	curl_close($ch);
	return $result !== false;
}

/*
 ****************************************************************************
 */
class IftttWebhook
{
	const WEBHOOK_KEY = "1234567890abcdefghijkl";
	const WEBHOOK_TRIGGER = "event_name";

	function execute($value1, $value2 = null, $value3 = null) {
		// build parameters
		$values = array("value1" => $value1);
		if ($value2 !== null)
			$values["value2"] = $value2;
		if ($value3 !== null)
			$values["value3"] = $value3;
		$json = json_encode($values);
		// actual request
		$url = "https://maker.ifttt.com/trigger/" . self::WEBHOOK_TRIGGER . "/with/key/" . self::WEBHOOK_KEY;
		return curl_post_json($url, $json);
	}
}

/*
 ****************************************************************************
 */
class LastRecord
{
	const LAST_SEEN_FILE = "lastseen.json";
	const WRITE_RETRIES = 5;
	const RETRY_WAIT_SECONDS = 1;
	const STATE_ONLINE = "connected";
	const STATE_OFFLINE = "disconnected";

	private $previous_ping;
	private $last_state;

	function __construct() {
		$this->previous_ping = null;
		$this->last_state = null;
	}
	
	public function load() {
		// try to load json
		$possible_json = file_get_contents(self::LAST_SEEN_FILE);
		$data = json_decode($possible_json, true, 2);
		// reset to default if necessary
		if ($data == null) {
			$data = array(
				"previous_ping" => null,
				"last_state" => null
			);
		}
		// apply
		$this->previous_ping = $data["previous_ping"];
		$this->last_state = $data["last_state"];
	}
	
	public function save() {
		$data = array(
			"previous_ping" => $this->previous_ping,
			"last_state" => $this->last_state,
		);
		$json = json_encode($data);
		// persist data with retries
		$tries = self::WRITE_RETRIES;
		while($tries > 0) {
			$result = file_put_contents(self::LAST_SEEN_FILE, $json, LOCK_EX);
			if ($result === false)
				$tries = $tries - 1;
			else
				break;
			sleep(1);
		}
	}
	
	public function get_previous_ping() {
		return $this->previous_ping;
	}
		
	public function update_ping() {
		$this->previous_ping = time();
	}
	
	public function is_last_state_online() {
		return $this->last_state === self::STATE_ONLINE;
	}

	public function is_last_state_offline() {
		return $this->last_state === self::STATE_OFFLINE;
	}

	public function is_last_state_undefined() {
		return $this->last_state === null;
	}

	private function set_last_state($new_state) {
		// notify only if state is actually changing
		if ($this->last_state !== $new_state) {
			$iw = new IftttWebhook();
			$result = $iw->execute($new_state);
			// do not change state if event notification was not successful
			if (!$result) {
				print_r("notification unsuccessful");
				return;
			}
		}
		// actually update state
		$this->last_state = $new_state;
	}

	public function set_last_state_online() {
		$this->set_last_state(self::STATE_ONLINE);
	}

	public function set_last_state_offline() {
		$this->set_last_state(self::STATE_OFFLINE);
	}
}

/*
 ****************************************************************************
 */
class UriHandler
{
	const DEVICE_URI_ON = "/icm/on";
	const DEVICE_URI_OFF = "/icm/off";
	const DEVICE_URI_PING = "/icm/ping";
	
	private $request_uri;
	
	function __construct($request_uri) {
		$this->request_uri = $request_uri;
	}

	function run() {
		if ($this->request_uri == self::DEVICE_URI_ON) {
			$iw = new IftttWebhook();
			$iw->execute("ON");
		} elseif ($this->request_uri == self::DEVICE_URI_OFF) {
			$iw = new IftttWebhook();
			$iw->execute("OFF");
		} elseif ($this->request_uri == self::DEVICE_URI_PING){
			$last_record = new LastRecord();
			$last_record->load();
			$last_record->update_ping();
			$last_record->save();
		}
	}
}

/*
 ****************************************************************************
 */
class CliHandler
{
	const PING_TIMEOUT_SECONDS = 5*60;
	
	private $script_path;
	
	function __construct($script_path) {
		$dirinfo = pathinfo($script_path);
		chdir($dirinfo["dirname"]);
	}

	function run() {
		$lr = new LastRecord();
		$lr->load();
		
		// if we have no previous ping
		if ($lr->get_previous_ping() === null) {

			// consider device offline
			$lr->set_last_state_offline();
			
		// if we have a previous ping, we can calculate its age
		} else {
			
			// if previous ping is old
			if (time() - $lr->get_previous_ping() > CliHandler::PING_TIMEOUT_SECONDS) {
				
				// consider offline
				$lr->set_last_state_offline();
				
			// if previous ping is recent
			} else {
				
				// consider online
				$lr->set_last_state_online();
			}
		}
		
		$lr->save();
	}
}

if (php_sapi_name() == "cli") {
	$handler = new CliHandler($_SERVER['PHP_SELF']);
	$handler->run();
} else {
	$handler = new UriHandler($_SERVER['REQUEST_URI']);
	$handler->run();
}
