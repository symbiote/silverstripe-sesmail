<?php

namespace Symbiote\SilverStripeSESMailer\Mail;

use Aws\Ses\SesClient;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Core\Convert;
use LogicException;
use SilverStripe\Core\Injector\Injector;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTP;


/**
 * A mailer implementation which uses Amazon's Simple Email Service.
 *
 * This mailer uses the SendRawEmail endpoint, so it supports sending attachments. Note that this only sends sending
 * emails to up to 50 recipients at a time, and Amazon's standard SES usage limits apply.
 *
 * Does not support inline images.
 */
class SESMailer implements Mailer
{

	/**
	 * @var SesClient
	 */
	protected $client;

	/**
     * Uses QueuedJobs module when sending emails
     *
     * @var boolean
     */
	protected $useQueuedJobs = true;

	/**
	 * @var array|null
	 */
	protected $lastResponse = null;

	/**
	 * @param array $config
	 */
	public function __construct($config)
	{
		$this->client = SesClient::factory($config);
	}

	/**
	 * @param boolean $bool
	 *
	 * @return $this
	 */
	public function setUseQueuedJobs($bool)
	{
		$this->useQueuedJobs = $bool;

		return $this;
	}

	/**
	 * @return array|null
	 */
	public function getLastResponse()
	{
		return $this->lastResponse;
	}

	/**
	 * @param SilverStripe\Control\Email
	 */
	public function send($email)
	{
		$destinations = $email->getTo();

		if ($cc = $email->getCc()) {
			$destinations = array_merge($destinations, $cc);
		}

		if ($bcc = $email->getBcc()) {
			$destinations = array_merge($destinations, $bcc);
		}

		$destinations = array_keys($destinations);
		$subject = $email->getSubject();
		$rawMessageText = $email->render()->getSwiftMessage()->toString();

		if (class_exists(QueuedJobService::class) && $this->useQueuedJobs) {
			$job = Injector::inst()->createWithArgs(SESQueuedMail::class, array(
				$destinations,
				$subject,
				$rawMessageText
			));

			singleton(QueuedJobService::class)->queueJob($job);

			return true;
		}

		try {
			$response = $this->sendSESClient($destinations, $rawMessageText);

			$this->lastResponse = $response;
		} catch (\Aws\Ses\Exception\SesException $ex) {
			Injector::inst()->get(LoggerInterface::class)->warning($ex->getMessage());

			$this->lastResponse = false;
			return false;
		}

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
}
