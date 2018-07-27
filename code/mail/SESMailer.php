<?php

use Aws\Ses\SesClient;
use Zend\Mail;
use Zend\Mime;

/**
 * A mailer implementation which uses Amazon's Simple Email Service.
 *
 * This mailer uses the SendRawEmail endpoint, so it supports sending attachments. Note that this only sends sending
 * emails to up to 50 recipients at a time, and Amazon's standard SES usage limits apply.
 *
 * Does not support inline images.
 */
class SESMailer extends \Mailer {

	/**
	 * @var SesClient
	 */
	private $client;
    
    /**
     * Define an 'always from' address that will override the 'From' 
     * address for outbound emails, leaving the replyto as is. 
     *
     * @var string
     */
    public $alwaysFrom;
	
	public function __construct($config) {
        if (!empty($config) && !isset($config['credentials'])) {
            // try to load the credentials from the Silverstripe configuration file
            if (!defined('SS_AWS_KEY') || !defined('SS_AWS_SECRET')) {
                throw new Exception("Undefined SS_AWS_KEY or SS_AWS_SECRET, unable to construct the AWS mailer");
            }

            $config['credentials'] = array(
                'key' => defined('SS_AWS_KEY') ? SS_AWS_KEY : '',
                'secret' => defined('SS_AWS_SECRET') ? SS_AWS_SECRET : '',
            );
        }

		$this->client = SesClient::factory($config);
		parent::__construct();
	}

	public function sendPlain($to, $from, $subject, $content, $attachments = false, $headers = false) {
		$contentPart = new Mime\Part($content);
		$contentPart->type = Mime\Mime::TYPE_TEXT;
		$contentPart->charset = 'utf-8';
		$contentPart->encoding = Mime\Mime::ENCODING_QUOTEDPRINTABLE;

		$body = new Mime\Message();
		$body->addPart($contentPart);

		if($attachments) {
			$this->addAttachments($body, $attachments);
		}

		return $this->sendMessage($to, $from, $subject, $body, $headers);
	}

	public function sendHTML($to, $from, $subject, $html, $attachments = false, $headers = false, $plain = false, $inlineImages = false) {
		if($inlineImages) {
			user_error('The SES mailer does not support inlining images', E_USER_NOTICE);
		}

		$htmlPart = new Mime\Part($html);
		$htmlPart->type = Mime\Mime::TYPE_HTML;
		$htmlPart->charset = 'utf-8';
		$htmlPart->encoding = Mime\Mime::ENCODING_QUOTEDPRINTABLE;

		$plainPart = new Mime\Part($plain ?: \Convert::xml2raw($html));
		$plainPart->type = Mime\Mime::TYPE_TEXT;
		$plainPart->charset = 'utf-8';
		$plainPart->encoding = Mime\Mime::ENCODING_QUOTEDPRINTABLE;

		$alternatives = new Mime\Message();
		$alternatives->setParts(array($plainPart, $htmlPart));

		$alternativesPart = new Mime\Part($alternatives->generateMessage());
		$alternativesPart->type = Mime\Mime::MULTIPART_ALTERNATIVE;
		$alternativesPart->boundary = $alternatives->getMime()->boundary();

		$body = new Mime\Message();
		$body->addPart($alternativesPart);

		if($attachments) {
			$this->addAttachments($body, $attachments);
		}

		return $this->sendMessage($to, $from, $subject, $body, $headers);
	}

	protected function sendMessage($destinations, $from, $subject, Mime\Message $body, $headers = false) {
		$message = new Mail\Message();
		$message->setFrom($this->alwaysFrom ? $this->alwaysFrom : $from);
		$message->setSubject($subject);
		$message->setBody($body);
		$message->setReplyTo(trim($from));

		if(isset($destinations) && $destinations) {
			$destinations = is_array($destinations) ? $destinations : explode(',', $destinations);
		} else {
			$destinations = array();
		}

		//Set our headers. If we find CC or BCC emails add them to the Destinations array
		if(!isset($headers['To'])) $headers['To'] = implode (',', $destinations);
		if(isset($headers['Cc']))  $destinations = array_merge($destinations, explode(',', $headers['Cc']));
		if(isset($headers['Bcc'])) $destinations = array_merge($destinations, explode(',', $headers['Bcc']));
        
        // if a custom 'reply-to' address has been set via headers
        if(isset($headers['Reply-To'])) {
            $message->setReplyTo($headers['Reply-To']);
			unset($headers['Reply-To']);
        }

		if($headers) {
            $message->getHeaders()->addHeaders($headers);
		}

		//if no Destinations address is set SES will reject the email.
		if(!array_filter($destinations)) {
			throw new LogicException('No Destinations (To, Cc, Bcc) for email set.');
		}

		$rawMessageText = $this->getMessageText($message);
		
		if (class_exists('QueuedJobService')) {
			singleton('QueuedJobService')->queueJob(Injector::inst()->createWithArgs('SESQueuedMail', array(
				$destinations,
				$subject,
				$rawMessageText
			)));
			unset($rawMessageText);
			return true;
		}

		try {
			$response = $this->sendSESClient($destinations, $rawMessageText);
		} catch (\Aws\Ses\Exception\SesException $ex) {
			SS_Log::log($ex, SS_Log::ERR);
			unset($rawMessageText);
			return false;
		}

		unset($rawMessageText);

        /* @var $response Aws\Result */
        if (isset($response['MessageId']) && strlen($response['MessageId']) && 
			(isset($response['@metadata']['statusCode']) && $response['@metadata']['statusCode'] == 200)) {
            return true;
        }

		return false;
	}

	/**
	 * Send an email via SES. Expects an array of valid emails and a raw email body that is valid.
	 *
	 * @param array $destinations array of emails addresses this email will be sent to
	 * @param string $rawMessageText Raw email message text must contain headers; and otherwise be a valid email body
	 * @return Array Amazon SDK response
	 */
	public function sendSESClient ($destinations, $rawMessageText) {

		try {
			$response = $this->client->sendRawEmail(array(
				'Destinations' => $destinations,
				'RawMessage' => array('Data' => $rawMessageText)
			));
		} catch (Exception $ex) {
			/*
			 * Amazon SES has intermittent issues with SSL connections being dropped before response is full received
			 * and decoded we're catching it here and trying to send again, the exception doesn't have an error code or
			 * similar to check on so we have to relie on magic strings in the error message. The error we're catching
			 * here is normally:
			 * 
			 * AWS HTTP error: cURL error 56: SSL read: error:00000000:lib(0):func(0):reason(0), errno 104
			 * (see http://curl.haxx.se/libcurl/c/libcurl-errors.html) (server): 100 Continue
			 * 
			 * Without the line break, so we check for the 'cURL error 56' as it seems likely to be consistent across
			 * systems/sites
			 */
			if(strpos($ex->getMessage(), "cURL error 56")) {
				$response = $this->client->sendRawEmail(array(
					'Destinations' => $destinations,
					'RawMessage' => array('Data' => $rawMessageText)
				));
			} else {
				throw $ex;
			}
		}

		return $response;
	}


	/**
	 * @param Mime\Message $message
	 * @param $attachments
	 */
	private function addAttachments(Mime\Message $message, $attachments) {
		if($attachments) foreach($attachments as $attachment) {
			if(is_array($attachment) && isset($attachment['tmp_name']) && isset($attachment['name'])) {
				$this->addAttachment($message, $attachment['tmp_name'], $attachment['name']);
			} else {
				$this->addAttachment($message, $attachment);
			}
		}
	}

	/**
	 * @param Mime\Message $message
	 * @param $file
	 * @param string|null $as
	 */
	private function addAttachment(Mime\Message $message, $file, $as = null) {
		if(is_string($file)) {
			$file = array(
				'filename' => $file,
				'contents' => file_get_contents($file)
			);
		}

		if(!isset($file['mimetype'])) {
			$file['mimetype'] = \HTTP::get_mime_type($file['filename']) ?: 'application/unknown';
		}

		$attachment = new Mime\Part($file['contents']);
		$attachment->type = $file['mimetype'];
		$attachment->filename = $as ?: basename($file['filename']);
		$attachment->disposition = Mime\Mime::DISPOSITION_ATTACHMENT;
		$attachment->encoding = Mime\Mime::ENCODING_BASE64;

		$message->addPart($attachment);
	}

	/**
	 * Gets the raw MIME message text.
	 *
	 * @param Mail\Message $message
	 * @return string
	 */
	private function getMessageText(Mail\Message $message) {
        $raw = $message->getHeaders()->toString() . Mail\Headers::EOL . $message->getBodyText();
        return $raw;
	}

}
