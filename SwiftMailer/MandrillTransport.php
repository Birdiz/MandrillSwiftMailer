<?php

namespace Accord\MandrillSwiftMailer\SwiftMailer;

use Mandrill;

use ReflectionClass;
use ReflectionException;
use \Swift_Events_EventDispatcher;
use \Swift_Events_EventListener;
use \Swift_Events_SendEvent;
use Swift_Image;
use Swift_Mime_Header;
use \Swift_Mime_SimpleMessage;
use Swift_SwiftException;
use \Swift_Transport;
use \Swift_Attachment;
use \Swift_MimePart;
use Swift_TransportException;

/**
 * Class MandrillTransport
 *
 * @package Accord\MandrillSwiftMailer\SwiftMailer
 */
class MandrillTransport implements Swift_Transport
{

    /** @type Swift_Events_EventDispatcher */
    protected $dispatcher;

    /** @var string|null */
    protected $apiKey;

    /** @var bool|null */
    protected $async;

    /** @var array|null */
    protected $resultApi;

    /** @var string|null */
    protected $subAccount;

    /**
     * @param Swift_Events_EventDispatcher $dispatcher
     */
    public function __construct(Swift_Events_EventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Not used
     */
    public function isStarted()
    {
        return false;
    }

    /**
     * Not used
     */
    public function start()
    {
    }

    /**
     * Not used
     */
    public function stop()
    {
    }

	/**
	 * Not used
	 */
    public function ping()
    {
    }

    /**
     * @param null|string $apiKey
     * @return $this
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * @param null|bool $async
     * @return $this
     */
    public function setAsync(?bool $async): self
    {
        $this->async = $async;

        return $this;
    }

    /**
     * @return null|bool
     */
    public function getAsync(): ?bool
    {
        return $this->async;
    }


    /**
     * @param null|string $subAccount
     * @return $this
     */
    public function setSubAccount(?string $subAccount): self
    {
        $this->subAccount = $subAccount;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSubAccount(): ?string
    {
        return $this->subAccount;
    }

    /**
     * @return Mandrill
     * @throws Swift_TransportException
     */
    protected function createMandrill(): Mandrill
    {
        if ($this->apiKey === null) {
            throw new Swift_TransportException('Cannot create instance of \Mandrill while API key is NULL');
        }

        return new Mandrill($this->apiKey);
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @param array|null $failedRecipients
     * @return int Number of messages sent
     * @throws ReflectionException
     */
    public function send(Swift_Mime_SimpleMessage $message, array &$failedRecipients = null)
    {
        $this->resultApi = null;

        if ($event = $this->dispatcher->createSendEvent($this, $message)) {
            $this->dispatcher->dispatchEvent($event, 'beforeSendPerformed');

            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;

        $mandrillMessage = $this->getMandrillMessage($message);

        $mandrill = $this->createMandrill();

        $this->resultApi = $mandrill->messages->send($mandrillMessage, $this->async);

        foreach ($this->resultApi as $item) {
            if ($item['status'] === 'sent' || $item['status'] === 'queued') {
                $sendCount++;
            } else {
                $failedRecipients[] = $item['email'];
            }
        }

        if ($event) {
            $event->setResult(
                $sendCount > 0 ? Swift_Events_SendEvent::RESULT_SUCCESS : Swift_Events_SendEvent::RESULT_FAILED
            );

            $this->dispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

    /**
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin): void
    {
        $this->dispatcher->bindEventListener($plugin);
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes(): array
    {
        return array(
            'text/plain',
            'text/html'
        );
    }

    /**
     * @param string $contentType
     * @return bool
     */
    protected function supportsContentType($contentType): bool
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return string
     * @throws ReflectionException
     */
    protected function getMessagePrimaryContentType(Swift_Mime_SimpleMessage $message)
    {
        $contentType = $message->getContentType();

        if ($this->supportsContentType($contentType)) {
            return $contentType;
        }

        // SwiftMailer hides the content type set in the constructor of Swift_Mime_SimpleMessage as soon
        // as you add another part to the message. We need to access the protected property
        // userContentType to get the original type.
        $messageRef = new ReflectionClass($message);
        if ($messageRef->hasProperty('userContentType')) {
            $propRef = $messageRef->getProperty('userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }

        return $contentType;
    }

    /**
     * https://mandrillapp.com/api/docs/messages.php.html#method-send
     *
     * @param Swift_Mime_SimpleMessage $message
     * @return array Mandrill Send Message
     * @throws Swift_SwiftException
     * @throws ReflectionException
     */
    public function getMandrillMessage(Swift_Mime_SimpleMessage $message)
    {
        $contentType = $this->getMessagePrimaryContentType($message);

        $toAddresses = $message->getTo();
        $ccAddresses = $message->getCc() ? $message->getCc() : [];
        $bccAddresses = $message->getBcc() ? $message->getBcc() : [];
        $replyToAddresses = $message->getReplyTo() ? $message->getReplyTo() : [];

        $to = $attachments = $images = $headers = $tags = $globalMergeVars = $mergeVars = [];

        foreach ($toAddresses as $toEmail => $toName) {
            $to[] = array(
                'email' => $toEmail,
                'name'  => $toName,
                'type'  => 'to'
            );
        }

        foreach ($replyToAddresses as $replyToEmail => $replyToName) {
            $headers['Reply-To'] = $replyToName ? sprintf('%s <%s>', $replyToEmail, $replyToName) : $replyToEmail;
        }

        foreach ($ccAddresses as $ccEmail => $ccName) {
            $to[] = array(
                'email' => $ccEmail,
                'name'  => $ccName,
                'type'  => 'cc'
            );
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            $to[] = array(
                'email' => $bccEmail,
                'name'  => $bccName,
                'type'  => 'bcc'
            );
        }

        $bodyHtml = $bodyText = null;

        switch ($contentType) {
            case 'text/plain':
                $bodyText = $message->getBody();
                break;
            case 'text/html':
                $bodyHtml = $message->getBody();
                break;
            default:
                $bodyHtml = $message->getBody();
        }

        foreach ($message->getChildren() as $child) {
            if ($child instanceof Swift_Image) {
                $images[] = array(
                    'type'    => $child->getContentType(),
                    'name'    => $child->getId(),
                    'content' => base64_encode($child->getBody()),
                );

                continue;
            }
            if ($child instanceof Swift_Attachment && ! ($child instanceof Swift_Image)) {
                $attachments[] = array(
                    'type'    => $child->getContentType(),
                    'name'    => $child->getFilename(),
                    'content' => base64_encode($child->getBody())
                );

                continue;
            }
            if ($child instanceof Swift_MimePart && $this->supportsContentType($child->getContentType())) {
                if ($child->getContentType() == 'text/html') {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == 'text/plain') {
                    $bodyText = $child->getBody();
                }

                continue;
            }
        }

        $fromAddresses = $message->getFrom();
        $fromEmails = array_keys($fromAddresses);

        $mandrillMessage = [
            'html'       => $bodyHtml,
            'text'       => $bodyText,
            'subject'    => $message->getSubject(),
            'from_email' => $fromEmails[0],
            'from_name'  => $fromAddresses[$fromEmails[0]],
            'to'         => $to,
            'headers'    => $headers,
            'tags'       => $tags,
            'inline_css' => null,
            'global_merge_vars' => $globalMergeVars,
            'merge_vars' => $mergeVars,
        ];

        if (count($attachments) > 0) {
            $mandrillMessage['attachments'] = $attachments;
        }

        if (count($images) > 0) {
            $mandrillMessage['images'] = $images;
        }

        foreach ($message->getHeaders()->getAll() as $header) {
            if ($header->getFieldType() === Swift_Mime_Header::TYPE_TEXT) {
                switch ($header->getFieldName()) {
                    case 'X-MC-GlobalMergeVars':
                        $mandrillMessage['global_merge_vars'] = $header->getValue();
                        break;
                    case 'X-MC-MergeVars':
                        $mandrillMessage['merge_vars'] = $header->getValue();
                        break;
                    case 'List-Unsubscribe':
                        $headers['List-Unsubscribe'] = $header->getValue();
                        $mandrillMessage['headers'] = $headers;
                        break;
                    case 'X-MC-InlineCSS':
                        $mandrillMessage['inline_css'] = $header->getValue();
                        break;
                    case 'X-MC-Tags':
                        $tags = $header->getValue();
                        if (!is_array($tags)) {
                            $tags = explode(',', $tags);
                        }
                        $mandrillMessage['tags'] = $tags;
                        break;
                    case 'X-MC-Autotext':
                        $autoText = $header->getValue();
                        if (in_array($autoText, array('true','on','yes','y', true), true)) {
                            $mandrillMessage['auto_text'] = true;
                        }
                        if (in_array($autoText, array('false','off','no','n', false), true)) {
                            $mandrillMessage['auto_text'] = false;
                        }
                        break;
                    case 'X-MC-GoogleAnalytics':
                        $analyticsDomains = explode(',', $header->getValue());
                        if(is_array($analyticsDomains)) {
                            $mandrillMessage['google_analytics_domains'] = $analyticsDomains;
                        }
                        break;
                    case 'X-MC-GoogleAnalyticsCampaign':
                        $mandrillMessage['google_analytics_campaign'] = $header->getValue();
                        break;
                    case 'X-MC-TrackingDomain':
                        $mandrillMessage['tracking_domain'] = $header->getValue();
                        break;
                    default:
                        if (strncmp($header->getFieldName(), 'X-', 2) === 0) {
                            $headers[$header->getFieldName()] = $header->getValue();
                            $mandrillMessage['headers'] = $headers;
                        }
                        break;
                }
            }
        }

        if ($this->getSubaccount()) {
            $mandrillMessage['subaccount'] = $this->getSubaccount();
        }

        return $mandrillMessage;
    }

    /**
     * @return null|array
     */
    public function getResultApi(): ?array
    {
        return $this->resultApi;
    }
}
