<?php

declare(strict_types=1);

namespace App\Kafka;

use LogicException;
use RdKafka\Conf;
use RdKafka\Exception as KafkaException;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class KafkaTransport implements TransportInterface
{
    private const int POLL_TIMEOUT_MS = 1000;
    private const int DELIVERY_TIMEOUT_MS = 5000;
    private const int FLUSH_TIMEOUT_MS = 10000;
    private const int MAX_WAIT_MS = 30000;

    private ?KafkaConsumer $consumer = null;
    private ?Producer $producer = null;
    private readonly KafkaDeliveryReport $deliveryReport;

    public function __construct(
        private readonly string $brokers,
        private readonly string $topic,
        private readonly ?string $groupId,
        private readonly SerializerInterface $serializer,
        private readonly ClockInterface $clock,
    ) {
        $this->deliveryReport = new KafkaDeliveryReport();
    }

    public function get(int $fetchSize = 1): iterable
    {
        $message = $this->consumer()->consume(self::POLL_TIMEOUT_MS);

        if ($message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
            return [];
        }

        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new TransportException($message->errstr(), $message->err);
        }

        $envelope = $this->serializer->decode(['body' => (string) $message->payload, 'headers' => $message->headers]);

        return [$envelope->with(new KafkaMessageStamp($message))];
    }

    public function ack(Envelope $envelope): void
    {
        $this->commit($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->commit($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        $delay = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;

        if ($delay > 0) {
            // TODO: пока повтор приема ждет, воркер не читает свои партиции, при потоке ошибок нужны retry-топики
            $this->clock->sleep(min($delay, self::MAX_WAIT_MS) / 1000);
        }

        $encoded = $this->serializer->encode($envelope);

        try {
            $this->producer()->newTopic($this->topic)->producev(
                $envelope->last(KafkaMessageStamp::class)?->message->partition ?? RD_KAFKA_PARTITION_UA,
                0,
                $encoded['body'],
                $this->key($envelope),
                $encoded['headers'] ?? [],
            );
        } catch (KafkaException $exception) {
            $this->producer = null;

            throw new TransportException($exception->getMessage(), $exception->getCode(), $exception);
        }

        $flushed = $this->producer()->flush(self::FLUSH_TIMEOUT_MS);
        $deliveryError = $this->deliveryReport->error;
        $this->deliveryReport->error = null;

        if ($flushed !== RD_KAFKA_RESP_ERR_NO_ERROR || $deliveryError !== null) {
            throw new TransportException(sprintf(
                'Could not publish to the "%s" topic: %s',
                $this->topic,
                $deliveryError ?? rd_kafka_err2str($flushed),
            ));
        }

        return $envelope;
    }

    private function key(Envelope $envelope): ?string
    {
        return $envelope->last(KafkaKeyStamp::class)->key ?? $envelope->last(KafkaMessageStamp::class)?->message->key;
    }

    private function commit(Envelope $envelope): void
    {
        $stamp = $envelope->last(KafkaMessageStamp::class) ?? throw new LogicException('It is not a Kafka message.');

        $this->consumer()->commit($stamp->message);
    }

    private function consumer(): KafkaConsumer
    {
        if ($this->consumer === null) {
            $conf = new Conf();
            $conf->set('bootstrap.servers', $this->brokers);
            $conf->set('log.connection.close', 'false');
            $conf->set('group.id', $this->groupId ?? throw new LogicException(
                sprintf('The "%s" topic is only published to.', $this->topic),
            ));
            $conf->set('enable.auto.commit', 'false');
            $conf->set('auto.offset.reset', 'earliest');

            $this->consumer = new KafkaConsumer($conf);
            $this->consumer->subscribe([$this->topic]);
        }

        return $this->consumer;
    }

    private function producer(): Producer
    {
        if ($this->producer === null) {
            $conf = new Conf();
            $conf->set('bootstrap.servers', $this->brokers);
            $conf->set('log.connection.close', 'false');
            $conf->set('allow.auto.create.topics', 'false');
            $conf->set('enable.idempotence', 'true');
            $conf->set('partitioner', 'murmur2_random');
            $conf->set('message.timeout.ms', (string) self::DELIVERY_TIMEOUT_MS);
            $deliveryReport = $this->deliveryReport;
            $conf->setDrMsgCb(static function (Producer $producer, Message $message) use ($deliveryReport): void {
                $deliveryReport->error = $message->err === RD_KAFKA_RESP_ERR_NO_ERROR ? null : $message->errstr();
            });

            $this->producer = new Producer($conf);
        }

        return $this->producer;
    }
}
