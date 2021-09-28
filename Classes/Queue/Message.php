<?php
namespace Webandco\JobQueue\Cache\Queue;

use Neos\Flow\Annotations as Flow;

/**
 * A DTO object to save the current state of the message and creation date time
 */
class Message extends \Flowpack\JobQueue\Common\Queue\Message
{
    const STATE_READY = 'ready';
    const STATE_FAILED = 'failed';
    const STATE_RESERVED = 'reserved';

    /**
     *
     * @var string Identifier of the message
     */
    protected $state;

    /**
     *
     * @var \DateTimeInterface Date created
     */
    protected $creationDateTime;

    /**
     * @param string $identifier
     * @param mixed $payload
     * @param integer $numberOfReleases
     */
    public function __construct(string $identifier, $payload, int $numberOfReleases = 0, string $state=self::STATE_READY, \DateTimeInterface $creationDateTime = null)
    {
        parent::__construct($identifier, $payload, $numberOfReleases);
        $this->state = $state;
        if(!$creationDateTime){
            $creationDateTime = new \DateTime();
        }
        $this->creationDateTime = $creationDateTime;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @return string
     */
    public function getCreationDateTime(): \DateTimeInterface
    {
        return $this->creationDateTime;
    }
}
