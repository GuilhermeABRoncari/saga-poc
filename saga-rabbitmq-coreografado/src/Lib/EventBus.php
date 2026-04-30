<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Event bus minimalista sobre RabbitMQ topic exchange, com reconnect
 * automático em caso de queda do broker.
 *
 * Coreografia pura: cada serviço publica eventos de domínio e
 * subscribe nos eventos que ele precisa reagir. Não há broker central
 * de saga; o RabbitMQ é o único acoplamento.
 */
final class EventBus
{
    public const EXCHANGE = 'saga.events';

    private AMQPStreamConnection $conn;
    private AMQPChannel $channel;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
    ) {
        $this->connect();
    }

    private function connect(): void
    {
        $this->conn = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass);
        $this->channel = $this->conn->channel();
        $this->channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
    }

    public function publish(string $eventType, string $sagaId, array $payload): void
    {
        $body = json_encode([
            'saga_id' => $sagaId,
            'event_type' => $eventType,
            'payload' => $payload,
            'published_at' => microtime(true),
        ], JSON_THROW_ON_ERROR);

        $msg = new AMQPMessage($body, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);
        $this->channel->basic_publish($msg, self::EXCHANGE, $eventType);
    }

    /**
     * @param string[] $routingKeys
     * @param callable(string $eventType, string $sagaId, array $payload): void $handler
     *        Lança exception se houver falha que justifique requeue (ex.: compensação que falhou).
     */
    public function subscribe(string $queue, array $routingKeys, callable $handler): void
    {
        $backoff = 1;
        while (true) {
            try {
                $this->declareAndConsume($queue, $routingKeys, $handler);
                $backoff = 1;
            } catch (AMQPConnectionClosedException | AMQPIOException | AMQPRuntimeException $e) {
                fwrite(STDERR, "[bus] connection lost ({$e->getMessage()}); reconnecting in {$backoff}s\n");
                $this->safeClose();
                sleep($backoff);
                $backoff = min($backoff * 2, 30);
                try {
                    $this->connect();
                } catch (\Throwable $reconnect) {
                    fwrite(STDERR, "[bus] reconnect failed: {$reconnect->getMessage()}\n");
                }
            }
        }
    }

    /** @param string[] $routingKeys */
    private function declareAndConsume(string $queue, array $routingKeys, callable $handler): void
    {
        $this->channel->queue_declare($queue, false, true, false, false);
        foreach ($routingKeys as $key) {
            $this->channel->queue_bind($queue, self::EXCHANGE, $key);
        }
        $this->channel->basic_qos(null, 1, null);

        $this->channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $msg) use ($handler): void {
            $data = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);
            try {
                $handler($data['event_type'], $data['saga_id'], $data['payload']);
                $msg->ack();
            } catch (\Throwable $e) {
                fwrite(STDERR, "[bus] handler error ({$e->getMessage()}); ack+republish for delayed retry\n");
                $msg->ack();
                sleep(2);
                $this->publish($data['event_type'], $data['saga_id'], $data['payload']);
            }
        });

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    private function safeClose(): void
    {
        try {
            $this->channel->close();
        } catch (\Throwable) {
        }
        try {
            $this->conn->close();
        } catch (\Throwable) {
        }
    }

    public function close(): void
    {
        $this->safeClose();
    }
}
