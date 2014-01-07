<?php

/**
 * Mollom Form Field.
 *
 * The actual form field which is inserted into your form fields via the 
 * spam protector class.
 *
 * @package spamprotection
 * @subpackage mollom
 */

class MollomField extends SpamProtectorField {

	static $always_show_captcha = false;
	
	static $force_check_on_members = false;

	private $Mollom;

	private function cachedMollom() {
		if(!$this->Mollom)
			$this->Mollom = new MollomServer();

		return $this->Mollom;
	}
	
	/**
	 * Initiate mollom service fields
	 */
	protected $mollomFields =  array(
		'session_id' => '',
		'post_title' => '',
		'post_body' => '',
		'author_name' => '', 
		'author_url' => '',
		'author_mail' => '',
		'author_openid' => '',
		'author_id' => ''
	);

	function Field($properties = array()) {
		$attributes = array(
			'type' => 'text',
			'class' => 'text' . ($this->extraClass() ? $this->extraClass() : ''),
			'id' => $this->id(),
			'name' => $this->getName(),
			'value' => $this->Value(),
			'title' => $this->Title(),
			'tabindex' => $this->getAttribute('tabindex'),
			'maxlength' => ($this->maxLength) ? $this->maxLength : null,
			'size' => ($this->maxLength) ? min( $this->maxLength, 30 ) : null 
		);
		
		$html = $this->createTag('input', $attributes);

		if($this->showCaptcha()) {
			
			$mollom_session_id = Session::get("mollom_session_id");

			$recaptcha = $this->cachedMollom()->createCaptcha(array('type' => 'image'));
			Session::set("mollom_session_id", $recaptcha['id']);
			$captchaHtml = '<div class="mollom-captcha">';
			$captchaHtml .= '<span class="mollom-image-captcha"><img src="'.$recaptcha['url'].'"/></span>';
			$captchaHtml .= '</div>';
			
			return $html . $captchaHtml;
		}
	}
	
	/**
	 * Return if we should show the captcha to the user. Checks for Molloms Request
	 * and if the user is currently logged in as then it can be assumed they are not spam
	 * 
	 * @return bool 
	 */
	private function showCaptcha() {
		if(Permission::check('ADMIN')) {
			return false; 
		}
		
		if ((Session::get('mollom_captcha_requested') || !$this->getFieldMapping()) && (!Member::currentUser() || self::$force_check_on_members)) {
			return true;
		} 
		
		return (bool)self::$always_show_captcha;
	}
	
	/**
	 * Return the Field Holder if Required
	 */
	function FieldHolder($properties = array()) {
		return ($this->showCaptcha()) ? parent::FieldHolder($properties) : null;
	}
	
	/**
	 * This function first gets values from mapped fields and then check these values against
	 * Mollom web service and then notify callback object with the spam checking result. 
	 * @return 	boolean		- true when Mollom confirms that the submission is ham (not spam)
	 *						- false when Mollom confirms that the submission is spam 
	 * 						- false when Mollom say 'unsure'. 
	 *						  In this case, 'mollom_captcha_requested' session is set to true 
	 *       				  so that Field() knows it's time to display captcha 			
	 */
	function validate($validator) {
		
		// If the user is ADMIN let them post comments without checking
		if(Permission::check('ADMIN')) {
			$this->clearMollomSession();
			return true;
		}	
		
		// if the user has logged and there's no force check on member
		if(Member::currentUser() && !self::$force_check_on_members) {
			return true;
		}
		
		// Info from the session
		$session_id = Session::get("mollom_session_id");
		
		// get fields to check
		$spamFields = $this->getFieldMapping();
		
		// Check validate the captcha answer if the captcha was displayed
		if($this->showCaptcha()) {
			if($this->cachedMollom()->captchaCheck($session_id, $this->Value())) {
				$this->clearMollomSession();
				return true;
			}
			else {
				$validator->validationError(
					$this->name, 
					_t(
						'MollomCaptchaField.INCORRECTSOLUTION', 
						"You didn't type in the correct captcha text. Please type it in again.",
						"Mollom Captcha provides words in an image, and expects a user to type them in a textfield"
					), 
					"validation", 
					false
				);
				Session::set('mollom_captcha_requested', true);
				return false;
			}
		}

		// populate mollom fields
		foreach($spamFields as $key => $field) {
			if(array_key_exists($field, $this->mollomFields)) {
				$this->mollomFields[$field] = (isset($_REQUEST[$key])) ? $_REQUEST[$key] : "";
			}
		}

		$this->mollomFields['session_id'] = $session_id;
		
		$response = $this->cachedMollom()->check(array(
		  'checks' => array('spam'),
		  'postTitle' => $this->mollomFields['post_title'],
		  'postBody' => $this->mollomFields['post_body'],
		  'authorName' => $this->mollomFields['author_name'],
		  'authorUrl' => $this->mollomFields['author_url'],
		  'authorIp' => $_SERVER['REMOTE_ADDR'],
		  'authorId' => $session_id, // If the author is logged in.
		));
		
		Session::set("mollom_session_id", $response['authorId']);
	 	Session::set("mollom_user_session_id", $response['authorId']);
		
		if($response['spamClassification'] == 'ham') {
			return true;
		} elseif($response['spamClassification'] == 'unsure') {
			$validator->validationError(
				$this->name, 
				_t(
					'MollomCaptchaField.CAPTCHAREQUESTED', 
					"Please answer the captcha question",
					"Mollom Captcha provides words in an image, and expects a user to type them in a textfield"
				), 
				"warning"
			);
			
			Session::set('mollom_captcha_requested', true);
			return false;
		}
		// Mollom has detected spam!
		elseif($response['spamClassification'] == 'spam') {
			$this->clearMollomSession();
			$validator->validationError(
				$this->name, 
				_t(
					'MollomCaptchaField.SPAM', 
					"Your submission has been rejected because it was treated as spam.",
					"Mollom Captcha provides words in an image, and expects a user to type them in a textfield"
				), 
				"error"
			);
			$this->clearMollomSession();
			return false;
		}
		
		return true;
	}
	
	/**
	 * Helper to quickly clear all the mollom session settings. For example after a successful post
	 */
	private function clearMollomSession() {
		Session::clear('mollom_session_id');
		Session::clear('mollom_captcha_requested');
	}
}
