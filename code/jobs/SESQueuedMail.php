<?php

/**
 * SESQueuedMail
 *
 * @author Stephen McMahon <stephen@symbiote.com.au>
 */
class SESQueuedMail extends AbstractQueuedJob implements QueuedJob {

	public function __construct($destinations, $subject, $rawMessageText) {
		$this->To = $destinations;
		$this->Subject = $subject;
		$this->RawMessageText = $rawMessageText;
	}

	public function getTitle() {

		return 'Email To: ' . implode(', ', $this->To) . ' Subject: ' . $this->Subject;
	}

	public function getSignature() {

		return md5($this->Subject) . ' ' . implode(', ', $this->To);
	}

	public function getJobType() {

		$this->totalSteps = '1';

		return QueuedJob::QUEUED;
	}

	/**
	 * Send this email. We try this only once and break as soon as something goes wrong to avoid sending multiple emails
	 * or DoSing our job queued with slow processing emails.
	 */
	public function process() {

		if ($this->isComplete) {

			return;
		}

		$this->currentStep = 1;

		$response = Injector::inst()->get('SESMailer')->sendSESClient($this->To, $this->RawMessageText);

		if (isset($response['MessageId']) && strlen($response['MessageId']) && 
			(isset($response['@metadata']['statusCode']) && $response['@metadata']['statusCode'] == 200)) {
			$this->addMessage('SES Response: '.print_r($response, true));
			$this->RawMessageText = 'Email Sent Successfully. Message body deleted';
			$this->isComplete = true;
			return;
		}
		throw new Exception(json_encode($response->toArray()), 'ERR');
	}

}
