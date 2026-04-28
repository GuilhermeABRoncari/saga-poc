<?php

declare(strict_types=1);

namespace Mobilestock\Saga;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class AmqpTransport
{
    private AMQPStreamConnection $conn;
    private AMQPChannel $channel;

    public function __construct(string $host, int $port, string $user, string $pass)
    {
        $this->conn = new AMQPStreamConnection($host, $port, $user, $pass);
        $this->channel = $this->conn->channel();
    }

    public function declareQueue(string $queue): void
    {
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
