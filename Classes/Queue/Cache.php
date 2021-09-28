<?php
namespace Webandco\JobQueue\Cache\Queue;

use Flowpack\JobQueue\Common\Exception;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Cache\Backend\IterableBackendInterface;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Algorithms;
use Flowpack\JobQueue\Common\Exception as JobQueueException;

/**
 * A queue which uses FLOW cache to write/read jobs to/from.
 * The cache backend must implement IterableBackendInterface and
 * can thus be slow in case of many or large cache entries.
 * This queue is meant for smaller projects without the need for a database/redis or beanstalkd.
 */
class Cache implements QueueInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $messageCache;

    /**
     * Default timeout for message reserves, in seconds
     *
     * @var int
     */
    protected $defaultTimeout = 60;

    /**
     * Interval messages are looked up in waitAnd*(), in seconds
     *
     * @var int
     */
    protected $pollInterval = 1;

    /**
     * @param string $name
     * @param array $options
     */
    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        if (isset($options['defaultTimeout'])) {
            $this->defaultTimeout = (integer)$options['defaultTimeout'];
        }
        if (isset($options['pollInterval'])) {
            $this->pollInterval = (integer)$options['pollInterval'];
        }
        $this->options = $options;
    }

    /**
     * @inheritdoc
     */
    public function setUp(): void
    {
        $backend = $this->messageCache->getBackend();
        if(!($backend instanceof IterableBackendInterface)){
            throw new JobQueueException(sprintf('The cache backend %s does not implement IterableBackendInterface. You must use another backend like FileBackend.', \get_class($backend)), 1632813854843);
        }
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function submit($payload, array $options = []): string
    {
        $messageId = Algorithms::generateUUID();

        $this->writeMessageToCache($messageId, $payload);

        return $messageId;
    }

    /**
     * @return Message the message written in the cache
     */
    protected function writeMessageToCache($messageId, $payload, $numberOfReleases=0, $state=Message::STATE_READY, $creationDateTime=null) : Message{
        $message = new Message($messageId, $payload, $numberOfReleases, $state, $creationDateTime);

        $messageCacheIdentifier = $this->getCacheIdentifier($message);
        $this->messageCache->set($messageCacheIdentifier, $message);

        return $message;
    }

    protected function getCacheIdentifier(Message $message){
        return sha1(serialize($message));
    }

    /**
     * @inheritdoc
     */
    public function waitAndTake(int $timeout = null): ?Message
    {
        $message = $this->reserveMessage($timeout);
        if ($message === null) {
            return null;
        }

        $removed = $this->messageCache->remove($this->getCacheIdentifier($message));
        if (!$removed) {
            // TODO error handling
            return null;
        }

        return $message;
    }

    /**
     * @inheritdoc
     */
    public function waitAndReserve(int $timeout = null): ?Message
    {
        $message =  $this->reserveMessage($timeout);

        return $message;
    }

    /**
     * @param int $timeout
     * @return Message
     * @throws DBALException
     */
    protected function reserveMessage(?int $timeout = null): ?Message
    {
        if ($timeout === null) {
            $timeout = $this->defaultTimeout;
        }

        $startTime = time();
        do {
            /** @var Message $message */
            $message = $this->findNextMessage();

            if ($message) {
                $cacheIdentifier = $this->getCacheIdentifier($message);
                $this->messageCache->remove($cacheIdentifier);
                $message = $this->writeMessageToCache($message->getIdentifier(), $message->getPayload(), $message->getNumberOfReleases(), Message::STATE_RESERVED, $message->getCreationDateTime());

                return $message;
            }
            if (time() - $startTime >= $timeout) {
                return null;
            }
            sleep($this->pollInterval);
        } while (true);

        return null;
    }

    /**
     * @return null|Message
     */
    protected function findNextMessage() : ?Message
    {
        $firstMessage = null;

        $this->iterateSortedMessages(function($message) use(&$firstMessage){
            $firstMessage = $message;
            return false;
        });

        return $firstMessage;
    }

    /**
     * @param string $messageId
     * @return null|Message
     */
    protected function findByMessageId(string $messageId) : ?Message {
        $foundMessage = null;

        $this->iterateUnsortedMessages(function(Message $message) use(&$foundMessage, $messageId){
            if($message->getIdentifier() == $messageId){
                $foundMessage = $message;
                return false;
            }
        });

        return $foundMessage;
    }

    /**
     * @inheritdoc
     */
    public function release(string $messageId, array $options = []): void
    {
        /** @var Message $message */
        $message = $this->findByMessageId($messageId);
        if($message){
            $noReleases = $message->getNumberOfReleases();
            $payload = $message->getPayload();

            $delayedDateTime = null;
            if(isset($options['delay'])){
                $now = new \DateTime();
                $delayedDateTime = $now->add(new \DateInterval($options['delay'].' seconds'));
            }

            $this->writeMessageToCache($messageId, $payload, $noReleases+1, Message::STATE_READY, $delayedDateTime);
        }
    }

    /**
     * @inheritdoc
     */
    public function abort(string $messageId): void
    {
        /** @var Message $message */
        $message = $this->findByMessageId($messageId);
        if($message) {
            $noReleases = $message->getNumberOfReleases();
            $payload = $message->getPayload();

            $this->writeMessageToCache($messageId, $payload, $noReleases+1, Message::STATE_FAILED, $message->getCreationDateTime());
        }
    }

    /**
     * @inheritdoc
     */
    public function finish(string $messageId): bool
    {
        $message = $this->findByMessageId($messageId);
        $cacheIdentifier = $this->getCacheIdentifier($message);

        // The FakeQueue does not support message finishing
        $this->messageCache->remove($cacheIdentifier);
        return true;
    }

    /**
     * @inheritdoc
     */
    public function peek(int $limit = 1): array
    {
        $messages = [];

        $this->iterateUnsortedMessages(function($message) use(&$limit, &$messages){
            $messages[] = $message;

            if($limit > 0) {
                $limit--;
            }

            if($limit == 0){
                return false;
            }
        });

        return $messages;
    }

    /**
     * @param \Closure $callback
     */
    protected function iterateUnsortedMessages(\Closure $callback)
    {
        $backend = $this->messageCache->getBackend();
        $backend->rewind();
        while( $backend->valid() ){
            $cacheIdentifier = $backend->key();

            $message = $this->messageCache->get($cacheIdentifier);
            if($message) {
                $rval = $callback($message, $cacheIdentifier);
                if ($rval === false) {
                    $backend->rewind();
                    break;
                }
            }

            $backend->next();
        }
    }

    protected function iterateSortedMessages(\Closure $callback){
        $cacheIdentifierToDateTime = [];

        $this->iterateUnsortedMessages(function($message, $cacheIdentifier) use(&$cacheIdentifierToDateTime){
            $cacheIdentifierToDateTime[$cacheIdentifier] = $message->getCreationDateTime();
        });

        uasort($cacheIdentifierToDateTime, function(\DateTimeInterface $a,\DateTimeInterface $b){
            if($a->getTimestamp() == $b->getTimestamp()){
                return 0;
            }

            return $a->getTimestamp() < $b->getTimestamp() ? -1 : 1;
        });
        foreach($cacheIdentifierToDateTime as $cacheIdentifier => $creationDateTime){
            $message = $this->messageCache->get($cacheIdentifier);
            if($message){
                $rval = $callback($message, $cacheIdentifier);
                if($rval === false){
                    break;
                }
            }
        }
    }

    protected function countByState(string $state): int
    {
        $i = 0;

        $this->iterateUnsortedMessages(function(Message $message) use($state, &$i){
            if($message->getState() == $state){
                $i++;
            }
        });

        return $i;
    }

    /**
     * @inheritdoc
     */
    public function countReady(): int
    {
        return $this->countByState(Message::STATE_READY);
    }

    /**
     * @inheritdoc
     */
    public function countReserved(): int
    {
        return $this->countByState(Message::STATE_RESERVED);
    }

    /**
     * @inheritdoc
     */
    public function countFailed(): int
    {
        return $this->countByState(Message::STATE_FAILED);
    }

    /**
     * @inheritdoc
     */
    public function flush(): void
    {
        $this->messageCache->flush();
    }
}
