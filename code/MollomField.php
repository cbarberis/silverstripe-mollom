<?php
/**
 * @require 	PHP Mollom (http://mollom.crsolutions.be/)
 */
class MollomField extends SpamProtecterField {
	
	/* Map fields (by name) to Spam service's post fields for spam checking */
	protected $fieldToPostTitle = "";
	
	// it can be more than one fields mapped to post content
	protected $fieldsToPostBody = array();
	
	protected $fieldToAuthorName = "";
	
	protected $fieldToAuthorUrl = "";
	
	protected $fieldToAuthorEmail = "";
	
	protected $fieldToAuthorOpenId = "";
	
	function setFieldMapping($fieldToPostTitle, $fieldsToPostBody, $fieldToAuthorName=null, $fieldToAuthorUrl=null, $fieldToAuthorEmail=null, $fieldToAuthorOpenId=null)
	{
		$this->fieldToPostTitle = $fieldToPostTitle;
		$this->fieldsToPostBody = $fieldsToPostBody;
		$this->fieldToAuthorName = $fieldToAuthorName;
		$this->fieldToAuthorUrl = $fieldToAuthorUrl;
		$this->fieldToAuthorEmail = $fieldToAuthorEmail;
		$this->fieldToAuthorOpenId = $fieldToAuthorOpenId;
	}
	
	function __construct($name, $title = null, $value = null, $form = null, $rightTitle = null) {
		parent::__construct($name, $title = null, $value = null, $form = null, $rightTitle = null);
		
		Mollom::setServerList(MollomServer::getServerList());
	}
	
	function Field() {
		$attributes = array(
			'type' => 'text',
			'class' => 'text' . ($this->extraClass() ? $this->extraClass() : ''),
			'id' => $this->id(),
			'name' => $this->Name(),
			'value' => $this->Value(),
			'tabindex' => $this->getTabIndex(),
			'maxlength' => ($this->maxLength) ? $this->maxLength : null,
			'size' => ($this->maxLength) ? min( $this->maxLength, 30 ) : null 
		);
		
		$html = $this->createTag('input', $attributes);
		
		if (Session::get('mollom_captcha_requested')) {
			$mollom_session_id = Session::get("mollom_session_id") ? Session::get("mollom_session_id") : null;
			$imageCaptcha = Mollom::getImageCaptcha($mollom_session_id);
			$audioCaptcha = Mollom::getAudioCaptcha($imageCaptcha['session_id']);
				
			$captchaHtml = '<div class="mollom-captcha">';
			$captchaHtml .= '<span class="mollom-image-captcha">' . $imageCaptcha['html'] . '</span>';
			$captchaHtml .= '<span class="mollom-audio-captcha">' . $audioCaptcha['html'] . '</span>';
			$captchaHtml .= '</div>';
			
			return $html . $captchaHtml;
		}
		
		return null;
	}
	
	function FieldHolder() {
		if (Session::get('mollom_captcha_requested')) {
			return parent::FieldHolder();
		}
		return null;
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

		if (Permission::check('ADMIN')) {
			$validator->validationError($this->name,'','good'); 
			return true;
		}
	
		// Check captcha solution if user has submitted a solution
		if (Session::get('mollom_captcha_requested') && trim($this->Value()) != '') {
			$mollom_session_id = Session::get("mollom_session_id") ? Session::get("mollom_session_id") : null;
			if ($mollom_session_id && Mollom::checkCaptcha($mollom_session_id, $this->Value())) {
				$this->clearMollomSession();
				return true;
			}
			else {
				$validator->validationError(
					$this->name, 
					_t(
						'MollomCaptchaField.INCORRECTSOLUTION', 
						"You didn't type in the correct captcha text. Please type it in again.",
						PR_MEDIUM,
						"Mollom Captcha provides words in an image, and expects a user to type them in a textfield"
				), 
					"validation", 
					false
				);
				return false;
			}
		}
		
		$postTitle = null;
		$postBody = null;
		$authorName = null;
		$authorUrl = null;
		$authorEmail = null;
		$authorOpenId = null;
		
		/* Get form content */
		if (isset($_REQUEST[$this->fieldToPostTitle])) $postTitle = $_REQUEST[$this->fieldToPostTitle];
		
		if (!is_array($this->fieldsToPostBody)) {
			$fieldsToCheck = $this->fieldsToPostBody;
		}
		else {
			$fieldsToCheck = array_intersect( $this->fieldsToPostBody, array_keys($_REQUEST) );	
			foreach ($fieldsToCheck as $fieldName) {
				$postBody .= $_REQUEST[$fieldName] . " ";
			}
		}
		
		if (isset($_REQUEST[$this->fieldToAuthorName])) $authorName = $_REQUEST[$this->fieldToAuthorName];
		
		if (isset($_REQUEST[$this->fieldToAuthorUrl])) $authorUrl = $_REQUEST[$this->fieldToAuthorUrl];
		
		if (isset($_REQUEST[$this->fieldToAuthorEmail])) $authorEmail = $_REQUEST[$this->fieldToAuthorEmail];
		
		if (isset($_REQUEST[$this->fieldToAuthorOpenId])) $authorOpenId = $_REQUEST[$this->fieldToAuthorOpenId];
		
		$mollom_session_id = Session::get("mollom_session_id") ? Session::get("mollom_session_id") : null;
		
		// check the submitted content against Mollom web service
		$response = Mollom::checkContent($mollom_session_id, $postTitle, $postBody, $authorName, $authorUrl, $authorEmail, $authorOpenId);

		Session::set("mollom_session_id", $response['session_id']);
		
		/* notity spam control callback objectect */
		//$this->notifyCallbackObject($response);

		if ($response['spam'] == 'ham') {
			$this->clearMollomSession();
			$validator->validationError($this->name,'','good'); 
			return true;
		} 
		else if ($response['spam'] == 'unsure') {
			$validator->validationError(
				$this->name, 
				_t(
					'MollomCaptchaField.CAPTCHAREQUESTED', 
					"Please answer the captcha question",
					PR_MEDIUM,
					"Mollom Captcha provides words in an image, and expects a user to type them in a textfield"
			), 
				"validation", 
				false
			);
			
			Session::set('mollom_captcha_requested', true);
			
			return false;
		} 
		else {
			$this->clearMollomSession();
			// TODO: maybe there's a better way to hadle this 
			return false;
		}	
	}
	
	private function clearMollomSession() {
		Session::clear('mollom_session_id');
		Session::clear('mollom_captcha_requested');
	}
}
?>