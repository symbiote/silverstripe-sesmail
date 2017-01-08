<?php

/**
 * SESQueuedMail
 *
 * @author Stephen McMahon <stephen@silverstripe.com.au>
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

		$this->currentStep++;

		try {
			$response = Injector::inst()->get('SESMailer')->sendSESClient($this->To, $this->RawMessageText);
		} catch (\Aws\Ses\Exception\SesException $ex) {
			$this->addMessage($ex, 'ERR');
			$this->JobStatus = QueuedJob::STATUS_BROKEN;

			return;
		}

		if (isset($response['MessageId']) && strlen($response['MessageId'])) {
			$this->RawMessageText = 'Email Sent Successfully. Message body deleted';
			$this->isComplete = true;

			return;
		}

		$this->addMessage(json_encode($response->toArray()), 'ERR');
		$this->JobStatus = QueuedJob::STATUS_BROKEN;
	}

}
