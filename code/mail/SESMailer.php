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
class SESMailer extends \Mailer
{

    /**
     * @var SesClient
     */
    private $client;
    
    public function __construct($config)
    {
        $this->client = SesClient::factory($config);
        parent::__construct();
    }

    public function sendPlain($to, $from, $subject, $content, $attachments = false, $headers = false)
    {
        $contentPart = new Mime\Part($content);
        $contentPart->type = Mime\Mime::TYPE_TEXT;
        $contentPart->charset = 'utf-8';
        $contentPart->encoding = Mime\Mime::ENCODING_QUOTEDPRINTABLE;

        $body = new Mime\Message();
        $body->addPart($contentPart);

        if ($attachments) {
            $this->addAttachments($body, $attachments);
        }

        $result = $this->sendMessage($to, $from, $subject, $body, $headers);
    }

    public function sendHTML($to, $from, $subject, $html, $attachments = false, $headers = false, $plain = false, $inlineImages = false)
    {
        if ($inlineImages) {
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

        if ($attachments) {
            $this->addAttachments($body, $attachments);
        }

        $this->sendMessage($to, $from, $subject, $body, $headers);
    }

    protected function sendMessage($to, $from, $subject, Mime\Message $body, $headers = false)
    {
        $message = new Mail\Message();
        $message->setTo($to);
        $message->setFrom($from);
        $message->setSubject($subject);
        $message->setBody($body);

        if ($headers) {
            $message->getHeaders()->addHeaders($headers);
        }

        return $this->client->sendRawEmail(array(
            'Source' => $from,
            'Destinations' => array($to),
            'RawMessage' => array('Data' => $this->getMessageText($message))
        ));
    }

    /**
     * @param Mime\Message $message
     * @param $attachments
     */
    private function addAttachments(Mime\Message $message, $attachments)
    {
        if ($attachments) {
            foreach ($attachments as $attachment) {
                if (is_array($attachment) && isset($attachment['tmp_name']) && isset($attachment['name'])) {
                    $this->addAttachment($message, $attachment['tmp_name'], $attachment['name']);
                } else {
                    $this->addAttachment($message, $attachment);
                }
            }
        }
    }

    /**
     * @param Mime\Message $message
     * @param $file
     * @param string|null $as
     */
    private function addAttachment(Mime\Message $message, $file, $as = null)
    {
        if (is_string($file)) {
            $file = array(
                'filename' => $file,
                'contents' => file_get_contents($file)
            );
        }

        if (!isset($file['mimetype'])) {
            $file['mimetype'] = \HTTP::get_mime_type($file['filename']) ?: 'application/unknown';
        }

        $attachment = new Mime\Part($file['contents']);
        $attachment->type = $file['mimetype'];
        $attachment->filename = $as ?: basename($file['filename']);
        $attachment->disposition = Mime\Mime::DISPOSITION_ATTACHMENT;

        $message->addPart($attachment);
    }

    /**
     * Gets the raw MIME message text.
     *
     * @param Mail\Message $message
     * @return string
     */
    private function getMessageText(Mail\Message $message)
    {
        return base64_encode($message->getHeaders()->toString() . Mail\Headers::EOL . $message->getBodyText());
    }
}
