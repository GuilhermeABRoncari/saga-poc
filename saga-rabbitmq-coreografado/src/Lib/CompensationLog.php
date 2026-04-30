<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

use PDO;

/**
 * Dedup-key para compensações idempotentes.
 *
 * Cada serviço mantém SEU próprio log local. Coreografia: o estado
 * de "já compensei essa saga" vive distribuído junto com o serviço,
 * não numa tabela central de saga.
 */
final class CompensationLog
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        $this->db = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS compensation_log (
                saga_id TEXT NOT NULL,
                step TEXT NOT NULL,
                payload TEXT NOT NULL,
                applied_at REAL NOT NULL,
                PRIMARY KEY (saga_id, step)
            );
        SQL);
    }

    /**
     * Tenta marcar (saga_id, step) como compensado. Retorna true se foi
     * a primeira aplicação, false se já estava registrado (dedup).
     */
    public function tryClaim(string $sagaId, string $step, array $payload): bool
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO compensation_log (saga_id, step, payload, applied_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$sagaId, $step, json_encode($payload), microtime(true)]);
        return $stmt->rowCount() === 1;
    }

    public function wasCompensated(string $sagaId, string $step): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM compensation_log WHERE saga_id = ? AND step = ?');
        $stmt->execute([$sagaId, $step]);
        return (bool) $stmt->fetchColumn();
    }
}
