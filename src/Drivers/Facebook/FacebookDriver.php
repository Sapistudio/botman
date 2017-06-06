<?php

namespace Mpociot\BotMan\Drivers\Facebook;

use Mpociot\BotMan\Users\User;
use Mpociot\BotMan\Messages\Incoming\Answer;
use Mpociot\BotMan\Messages\Incoming\IncomingMessage;
use Mpociot\BotMan\Messages\Outgoing\Question;
use Illuminate\Support\Collection;
use Mpociot\BotMan\Drivers\HttpDriver;
use Mpociot\BotMan\Messages\Attachments\File;
use Mpociot\BotMan\Messages\Attachments\Audio;
use Mpociot\BotMan\Messages\Attachments\Image;
use Mpociot\BotMan\Messages\Attachments\Video;
use Mpociot\BotMan\Drivers\Facebook\Extensions\ListTemplate;
use Mpociot\BotMan\Drivers\Facebook\Extensions\ButtonTemplate;
use Mpociot\BotMan\Drivers\Facebook\Extensions\GenericTemplate;
use Mpociot\BotMan\Drivers\Facebook\Extensions\ReceiptTemplate;
use Mpociot\BotMan\Drivers\Events\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ParameterBag;
use Mpociot\BotMan\Interfaces\DriverEventInterface;
use Mpociot\BotMan\Messages\Outgoing\OutgoingMessage;
use Mpociot\BotMan\Drivers\Facebook\Events\MessagingReads;
use Mpociot\BotMan\Drivers\Facebook\Events\MessagingOptins;
use Mpociot\BotMan\Drivers\Facebook\Events\MessagingReferrals;
use Mpociot\BotMan\Drivers\Facebook\Events\MessagingDeliveries;

class FacebookDriver extends HttpDriver
{
    /** @var string */
    protected $signature;

    /** @var string */
    protected $content;

    /** @var array */
    protected $templates = [
        ButtonTemplate::class,
        GenericTemplate::class,
        ListTemplate::class,
        ReceiptTemplate::class,
    ];

    private $supportedAttachments = [
        Video::class,
        Audio::class,
        Image::class,
        File::class,
    ];

    /** @var DriverEventInterface */
    protected $driverEvent;

    protected $facebookProfileEndpoint = 'https://graph.facebook.com/v2.6/';

    const DRIVER_NAME = 'Facebook';

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
        $this->event = Collection::make((array) $this->payload->get('entry')[0]);
        $this->signature = $request->headers->get('X_HUB_SIGNATURE', '');
        $this->content = $request->getContent();
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        $validSignature = ! $this->config->has('facebook_app_secret') || $this->validateSignature();
        $messages = Collection::make($this->event->get('messaging'))->filter(function ($msg) {
            return isset($msg['message']) && isset($msg['message']['text']);
        });

        return ! $messages->isEmpty() && $validSignature;
    }

    /**
     * @return bool|DriverEventInterface
     */
    public function hasMatchingEvent()
    {
        $event = Collection::make($this->event->get('messaging'))->filter(function ($msg) {
            return Collection::make($msg)->except(['sender', 'recipient', 'timestamp', 'message'])->isEmpty() === false;
        })->transform(function ($msg) {
            return Collection::make($msg)->toArray();
        })->first();

        if (! is_null($event)) {
            $this->driverEvent = $this->getEventFromEventData($event);

            return $this->driverEvent;
        }

        return false;
    }

    /**
     * @param array $eventData
     * @return DriverEventInterface
     */
    protected function getEventFromEventData(array $eventData)
    {
        $name = Collection::make($eventData)->except(['sender', 'recipient', 'timestamp', 'message'])->keys()->first();
        switch ($name) {
            case 'postback':
                return new Events\MessagingPostbacks($eventData);
            break;
            case 'referral':
                return new MessagingReferrals($eventData);
            break;
            case 'optin':
                return new MessagingOptins($eventData);
            break;
            case 'delivery':
                return new MessagingDeliveries($eventData);
            break;
            case 'read':
                return new MessagingReads($eventData);
            break;
            case 'checkout_update':
                return new Events\MessagingCheckoutUpdates($eventData);
            break;
            default:
                $event = new GenericEvent($eventData);
                $event->setName($name);

                return $event;
            break;
        }
    }

    /**
     * @return bool
     */
    protected function validateSignature()
    {
        return hash_equals($this->signature,
            'sha1='.hash_hmac('sha1', $this->content, $this->config->get('facebook_app_secret')));
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function types(IncomingMessage $matchingMessage)
    {
        $parameters = [
            'recipient' => [
                'id' => $matchingMessage->getSender(),
            ],
            'access_token' => $this->config->get('facebook_token'),
            'sender_action' => 'typing_on',
        ];

        return $this->http->post('https://graph.facebook.com/v2.6/me/messages', [], $parameters);
    }

    /**
     * @param  IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        $payload = $message->getPayload();
        if (isset($payload['message']['quick_reply'])) {
            return Answer::create($message->getText())->setMessage($message)->setInteractiveReply(true)->setValue($payload['message']['quick_reply']['payload']);
        }

        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        $messages = Collection::make($this->event->get('messaging'));
        $messages = $messages->transform(function ($msg) {
            if (isset($msg['message']) && isset($msg['message']['text'])) {
                return new IncomingMessage($msg['message']['text'], $msg['sender']['id'], $msg['recipient']['id'], $msg);
            }

            return new IncomingMessage('', '', '');
        })->toArray();

        if (count($messages) === 0) {
            return [new IncomingMessage('', '', '')];
        }

        return $messages;
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        // Facebook bot replies don't get returned
        return false;
    }

    /**
     * Convert a Question object into a valid Facebook
     * quick reply response object.
     *
     * @param Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $questionData = $question->toArray();

        $replies = Collection::make($question->getButtons())->map(function ($button) {
            return array_merge([
                'content_type' => 'text',
                'title' => $button['text'],
                'payload' => $button['value'],
                'image_url' => $button['image_url'],
            ], $button['additional']);
        });

        return [
            'text' => $questionData['text'],
            'quick_replies' => $replies->toArray(),
        ];
    }

    /**
     * @param string|Question|IncomingMessage $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        if ($this->driverEvent) {
            $recipient = $this->driverEvent->getPayload()['sender']['id'];
        } else {
            $recipient = $matchingMessage->getSender();
        }
        $parameters = array_merge_recursive([
            'recipient' => [
                'id' => $recipient,
            ],
            'message' => [
                'text' => $message,
            ],
        ], $additionalParameters);
        /*
         * If we send a Question with buttons, ignore
         * the text and append the question.
         */
        if ($message instanceof Question) {
            $parameters['message'] = $this->convertQuestion($message);
        } elseif (is_object($message) && in_array(get_class($message), $this->templates)) {
            $parameters['message'] = $message->toArray();
        } elseif ($message instanceof OutgoingMessage) {
            $attachment = $message->getAttachment();
            if (in_array(get_class($attachment), $this->supportedAttachments)) {
                $attachmentType = strtolower(basename(str_replace('\\', '/', get_class($attachment))));
                unset($parameters['message']['text']);
                $parameters['message']['attachment'] = [
                    'type' => $attachmentType,
                    'payload' => [
                        'url' => $attachment->getUrl(),
                    ],
                ];
            } else {
                $parameters['message']['text'] = $message->getText();
            }
        }

        $parameters['access_token'] = $this->config->get('facebook_token');

        return $parameters;
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        return $this->http->post('https://graph.facebook.com/v2.6/me/messages', [], $payload);
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return ! empty($this->config->get('facebook_token'));
    }

    /**
     * Retrieve User information.
     *
     * @param IncomingMessage $matchingMessage
     * @return \Mpociot\BotMan\Users\User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        $profileData = $this->http->get($this->facebookProfileEndpoint.$matchingMessage->getSender().'?fields=first_name,last_name&access_token='.$this->config->get('facebook_token'));

        $profileData = json_decode($profileData->getContent());
        $firstName = isset($profileData->first_name) ? $profileData->first_name : null;
        $lastName = isset($profileData->last_name) ? $profileData->last_name : null;

        return new User($matchingMessage->getSender(), $firstName, $lastName);
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $parameters = array_replace_recursive([
            'access_token' => $this->config->get('facebook_token'),
        ], $parameters);

        return $this->http->post('https://graph.facebook.com/v2.6/'.$endpoint, [], $parameters);
    }

    /**
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }
}
