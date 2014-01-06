<?php


class MollomServer extends Mollom {

	private static $credentials = array();

	static function setAPICredential($credential) {
		foreach($credential as $c => $v) {
			self::$credentials[$c] = $v;
		}
	}

	public function captchaCheck($id, $value) {
		return $this->checkCaptcha(array('id' => $id, 'solution' => $value));
	}

	public function check($sessionId = null, $postTitle = null, $postBody = null, $authorName = null, $authorUrl = null, $authorEmail = null, $authorOpenId = null, $authorId = null) {
		$data = func_get_args();
		return $this->checkContent($data);
	}

	public function loadConfiguration($name) {
		return self::$credentials[$name];
	}

	/**
	* Implements Mollom::saveConfiguration().
	*/
	public function saveConfiguration($name, $value) {
		// Unused for hard-coded implementations.
	}

	/**
	* Implements Mollom::deleteConfiguration().
	*/
	public function deleteConfiguration($name) {
		// Unused for hard-coded implementations.
	}

	/**
	* Implements Mollom::getClientInformation().
	*
	* Note: Replace this with your actual client information.
	*/
	public function getClientInformation() {
		$data = array(
			'platformName' => 'PHP/SilverStripe',
			'platformVersion' => PHP_VERSION,
			'clientName' => 'Mollom PHP client for SilverStripe CMS > 3.1',
			'clientVersion' => '1.0',
		);
		return $data;
	}

	/**
	* Implements Mollom::request().
	*
	* Basic implementation leveraging PHP's cURL extension.
	*/
	protected function request($method, $server, $path, $query = NULL, array $headers = array()) {
		$ch = curl_init();
		if(!Director::isLive())
			$server = 'http://dev.mollom.com/v1/';
		else 
			$server = 'http://rest.mollom.com/v1/';
		// CURLOPT_HTTPHEADER expects all headers as values:
		// @see http://php.net/manual/function.curl-setopt.php
		foreach ($headers as $name => &$value) {
			$value = $name . ': ' . $value;
		}

		// Compose the Mollom endpoint URL.
		$url = $server . $path;
		if (isset($query) && $method == 'GET') {
			$url .= '?' . $query;
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		// Send OAuth + other request headers.
		// 
		// Prevent API calls from taking too long.
		// Under normal operations, API calls may time out for Mollom users without
		// a paid subscription.
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);

		if($method == 'POST') {
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
		}
		else {
			curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		//curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
		if(Director::isLive()) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_HEADER, TRUE);
		}

		// $raw_response = curl_exec($ch);
		// var_dump(explode("\r\n\r\n", $raw_response, 2)); die;
		// Execute the HTTP request.
		if($raw_response = curl_exec($ch)) {
			// Split the response headers from the response body.
			$vars = explode("\r\n\r\n", $raw_response, 2);

			if(count($vars) == 1) {
				$raw_response_headers = "\ncontent-type: application/xml";
				$response_body = $vars[0];
			} else {
				$raw_response_headers = $vars[0];
				$response_body = $vars[1];
			}

			// Parse HTTP response headers.
			// @see http_parse_headers()
			$raw_response_headers = str_replace("\r", '', $raw_response_headers);
			$raw_response_headers = explode("\n", $raw_response_headers);

			$message = array_shift($raw_response_headers);

			$response_headers = array();
			foreach ($raw_response_headers as $line) {
				list($name, $value) = explode(': ', $line, 2);
				// Mollom::handleRequest() expects response header names in lowercase.
				$response_headers[strtolower($name)] = $value;
			}

			$info = curl_getinfo($ch);
			$response = array(
				'code' => $info['http_code'],
				'message' => $message,
				'headers' => $response_headers,
				'body' => $response_body,
			);
		} else {
			$response = array(
				'code' => curl_errno($ch),
				'message' => curl_error($ch),
			);
		}
		curl_close($ch);

		$response = (object) $response;
		return $response;
	}

	/**
	* Retrieves GET/HEAD or POST/PUT parameters of an inbound request.
	*
	* @return array
	*   An array containing either GET/HEAD query string parameters or POST/PUT
	*   post body parameters. Parameter parsing accounts for multiple request
	*   parameters in non-PHP format; e.g., 'foo=one&foo=bar'.
	*
	* @todo Move into base class.
	*/
	public static function getServerParameters() {
		if ($_SERVER['REQUEST_METHOD'] == 'GET' || $_SERVER['REQUEST_METHOD'] == 'HEAD') {
			$data = self::httpParseQuery($_SERVER['QUERY_STRING']);
			// Remove $_GET['q'].
			unset($data['q']);
		} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' || $_SERVER['REQUEST_METHOD'] == 'PUT') {
			$data = self::httpParseQuery(file_get_contents('php://input'));
		}
		return $data;
	}

	/**
	* Retrieves the OAuth authorization header of an inbound request.
	*
	* @return array
	*   An array containing all key/value pairs extracted out of the
	*   'Authorization' HTTP header, if any.
	*
	* @todo Move into base class.
	*/
	public static function getServerAuthentication() {
		$header = array();
		if (function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
			if (isset($headers['Authorization'])) {
				$input = $headers['Authorization'];
			}
		} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
			$input = $_SERVER['HTTP_AUTHORIZATION'];
		}
		if (isset($input)) {
			preg_match_all('@([^, =]+)="([^"]*)"@', $input, $header);
			$header = array_combine($header[1], $header[2]);
		}
		return $header;
	}

}