<?php

declare(strict_types=1);

namespace Mobilestock\Saga;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class AmqpTransport
{
    private AMQPStreamConnection $conn;
    private AMQPChannel $channel;
    private bool $useQuorum;

    public function __construct(string $host, int $port, string $user, string $pass)
    {
        $this->conn = new AMQPStreamConnection($host, $port, $user, $pass);
        $this->channel = $this->conn->channel();
        // Quorum queues a partir de RabbitMQ 4.x — em 3.x classic mirrored era opção,
        // mas a partir de 4.0 mirrored foi removido e quorum é o caminho oficial para HA.
        $this->useQuorum = ($_ENV['QUEUE_TYPE'] ?? 'classic') === 'quorum';
    }

    public function declareQueue(string $queue): void
    {
        if ($this->useQuorum) {
            $args = new AMQPTable(['x-queue-type' => 'quorum']);
            $this->channel->queue_declare($queue, false, true, false, false, false, $args);
            return;
        }
        $this->channel->queue_declare($queue, false, true, false, false);
    }

    public function publish(string $queue, array $payload): void
    {
        $this->declareQueue($queue);
        $msg = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, 'content_type' => 'application/json'],
        );
        $this->channel->basic_publish($msg, '', $queue);
    }

    public function consume(string $queue, callable $handler): void
    {
        $this->declareQueue($queue);
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) use ($handler): void {
                $payload = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);
                try {
                    $handler($payload);
                    $msg->ack();
                } catch (\Throwable $e) {
                    fwrite(STDERR, "[consume error] {$e->getMessage()}\n");
                    $msg->nack(false);
                }
            },
        );
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
