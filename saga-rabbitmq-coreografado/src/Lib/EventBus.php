<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Event bus minimalista sobre RabbitMQ topic exchange.
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

    public function __construct(string $host, int $port, string $user, string $pass)
    {
        $this->conn = new AMQPStreamConnection($host, $port, $user, $pass);
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
     */
    public function subscribe(string $queue, array $routingKeys, callable $handler): void
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
                fwrite(STDERR, "[bus error] {$e->getMessage()}\n");
                $msg->nack(false, false);
            }
        });

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function close(): void
    {
        $this->channel->close();
        $this->conn->close();
    }
}
