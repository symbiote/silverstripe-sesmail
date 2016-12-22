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

	public function getJobType() {

		$this->totalSteps = '1';

		return QueuedJob::QUEUED;
	}

	/**
	 * Lets process a single node, and publish it if necessary
	 */
	public function process() {

		if ($this->isComplete) {

			return;
		}

		// we need to always increment! This is important, because if we don't then our container
		// that executes around us thinks that the job has died, and will stop it running.
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
	}

}
