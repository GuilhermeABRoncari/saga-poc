<?php

declare(strict_types=1);

namespace Mobilestock\Saga;

use PDO;

final class SagaStateRepository
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $this->pdo = new PDO("sqlite:{$sqlitePath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sagas (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                status TEXT NOT NULL,
                current_step INTEGER NOT NULL DEFAULT 0,
                payload TEXT NOT NULL,
                completed_steps TEXT NOT NULL DEFAULT '[]',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);
    }

    public function create(string $id, string $name, array $payload): void
    {
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO sagas (id, name, status, current_step, payload, completed_steps, created_at, updated_at)
            VALUES (:id, :name, 'RUNNING', 0, :payload, '[]', :now, :now)
        SQL);
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'now' => $now,
        ]);
    }

    public function get(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sagas WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $row['completed_steps'] = json_decode($row['completed_steps'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function advance(string $id, int $nextStep, array $completedStep): void
    {
        $current = $this->get($id);
        $completed = $current['completed_steps'];
        $completed[] = $completedStep;
        $stmt = $this->pdo->prepare(<<<SQL
            UPDATE sagas SET current_step = :step, completed_steps = :completed, updated_at = :now
            WHERE id = :id
        SQL);
        $stmt->execute([
            'step' => $nextStep,
            'completed' => json_encode($completed, JSON_THROW_ON_ERROR),
            'now' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'id' => $id,
        ]);
    }

    public function setStatus(string $id, string $status): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            UPDATE sagas SET status = :status, updated_at = :now WHERE id = :id
        SQL);
        $stmt->execute([
            'status' => $status,
            'now' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'id' => $id,
        ]);
    }
}
