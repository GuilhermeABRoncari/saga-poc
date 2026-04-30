<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

use PDO;

/**
 * Log local de saga, por serviço.
 *
 * - `step_log`: marca quais steps este serviço executou com sucesso.
 *   Usado pela compensação para decidir se há efeito a desfazer.
 *
 * - `compensation_log`: mantém estado da compensação com `status`
 *   ('in_progress' | 'done'). Permite retry sob re-entrega de saga.failed:
 *   compensation entra como 'in_progress', vira 'done' só após sucesso.
 *   Re-entrega vê 'in_progress' e tenta de novo. 'done' faz dedup.
 */
final class SagaLog
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        $this->db = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS step_log (
                saga_id TEXT NOT NULL,
                step TEXT NOT NULL,
                completed_at REAL NOT NULL,
                PRIMARY KEY (saga_id, step)
            );
            CREATE TABLE IF NOT EXISTS compensation_log (
                saga_id TEXT NOT NULL,
                step TEXT NOT NULL,
                status TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_attempt_at REAL NOT NULL,
                PRIMARY KEY (saga_id, step)
            );
        SQL);
    }

    public function markStepDone(string $sagaId, string $step): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO step_log (saga_id, step, completed_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$sagaId, $step, microtime(true)]);
    }

    public function wasStepDone(string $sagaId, string $step): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM step_log WHERE saga_id = ? AND step = ?');
        $stmt->execute([$sagaId, $step]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Reserva tentativa de compensação. Retorna 'done' se já foi compensada,
     * 'attempt' se este é o claim para tentar (novo ou retry).
     */
    public function startCompensation(string $sagaId, string $step, array $payload): string
    {
        $stmt = $this->db->prepare('SELECT status, attempts FROM compensation_log WHERE saga_id = ? AND step = ?');
        $stmt->execute([$sagaId, $step]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['status'] === 'done') {
            return 'done';
        }

        if ($row === false) {
            $insert = $this->db->prepare(
                "INSERT INTO compensation_log (saga_id, step, status, payload, attempts, last_attempt_at)
                 VALUES (?, ?, 'in_progress', ?, 1, ?)"
            );
            $insert->execute([$sagaId, $step, json_encode($payload), microtime(true)]);
        } else {
            $update = $this->db->prepare(
                'UPDATE compensation_log SET attempts = attempts + 1, last_attempt_at = ? WHERE saga_id = ? AND step = ?'
            );
            $update->execute([microtime(true), $sagaId, $step]);
        }

        return 'attempt';
    }

    public function markCompensationDone(string $sagaId, string $step): void
    {
        $stmt = $this->db->prepare(
            "UPDATE compensation_log SET status = 'done', last_attempt_at = ? WHERE saga_id = ? AND step = ?"
        );
        $stmt->execute([microtime(true), $sagaId, $step]);
    }

    public function compensationAttempts(string $sagaId, string $step): int
    {
        $stmt = $this->db->prepare('SELECT attempts FROM compensation_log WHERE saga_id = ? AND step = ?');
        $stmt->execute([$sagaId, $step]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
