<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * WalletService
 * -------------------------------------------------------------------
 * Single source of truth for every wallet movement. Every credit /
 * debit creates a row in `wallet_transactions` so the audit trail is
 * complete and balance never drifts from the ledger.
 *
 * All public mutating methods are atomic (FOR UPDATE row lock + ledger
 * insert in the same DB transaction).
 */
final class WalletService
{
    public const SOURCE_SIGNUP_BONUS    = 'signup_bonus';
    public const SOURCE_ADMIN_TOPUP     = 'admin_topup';
    public const SOURCE_ADMIN_DEDUCT    = 'admin_deduct';
    public const SOURCE_PAYMENT_GATEWAY = 'payment_gateway';
    public const SOURCE_REVIEW_DEDUCT   = 'review_deduction';
    public const SOURCE_REFUND          = 'refund';
    public const SOURCE_RESELLER_CREDIT = 'reseller_credit';

    public function __construct(private PDO $pdo) {}

    /** Read-only balance lookup (no lock). */
    public function getBalance(int $clientId): int
    {
        $s = $this->pdo->prepare("SELECT wallet_balance FROM clients WHERE id = :id LIMIT 1");
        $s->execute([':id' => $clientId]);
        return (int)($s->fetch()['wallet_balance'] ?? 0);
    }

    /**
     * Atomically credit `$amount` to a client wallet and write a ledger row.
     * Returns the new balance (or null on failure).
     */
    public function credit(
        int $clientId,
        int $amount,
        string $source,
        ?string $description = null,
        ?int $adminId = null,
        ?int $reviewSessionId = null
    ): ?int {
        $amount = max(0, $amount);
        if ($amount === 0) {
            return $this->getBalance($clientId);
        }

        $owns = !$this->pdo->inTransaction();
        try {
            if ($owns) { $this->pdo->beginTransaction(); }

            $row = $this->lockClient($clientId);
            if ($row === null) {
                if ($owns) { $this->pdo->rollBack(); }
                return null;
            }

            $newBalance = (int)$row['wallet_balance'] + $amount;
            $this->updateBalance($clientId, $newBalance);
            $this->writeLedger($clientId, 'credit', $amount, $newBalance, $source, $description, $adminId, $reviewSessionId);

            if ($owns) { $this->pdo->commit(); }
            return $newBalance;
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return null;
        }
    }

    /**
     * Atomically debit `$amount` from a client wallet (only if sufficient).
     * Returns the new balance, or null if balance was insufficient / failed.
     */
    public function debit(
        int $clientId,
        int $amount,
        string $source,
        ?string $description = null,
        ?int $relatedReviewSessionId = null,
        ?int $adminId = null
    ): ?int {
        $amount = max(0, $amount);
        if ($amount === 0) {
            return $this->getBalance($clientId);
        }

        $owns = !$this->pdo->inTransaction();
        try {
            if ($owns) { $this->pdo->beginTransaction(); }

            $row = $this->lockClient($clientId);
            if ($row === null) {
                if ($owns) { $this->pdo->rollBack(); }
                return null;
            }

            $current = (int)$row['wallet_balance'];
            if ($current < $amount) {
                if ($owns) { $this->pdo->rollBack(); }
                return null;
            }

            $newBalance = $current - $amount;
            $this->updateBalance($clientId, $newBalance);
            $this->writeLedger($clientId, 'debit', $amount, $newBalance, $source, $description, $adminId, $relatedReviewSessionId);

            if ($owns) { $this->pdo->commit(); }
            return $newBalance;
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return null;
        }
    }

    /**
     * Fetch ledger entries for a client, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getHistory(int $clientId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT wt.id, wt.txn_type, wt.amount, wt.balance_after, wt.source,
                   wt.description, wt.related_admin_id, wt.related_review_session_id,
                   wt.created_at, a.email AS admin_email
            FROM wallet_transactions wt
            LEFT JOIN admins a ON a.id = wt.related_admin_id
            WHERE wt.client_id = :id
            ORDER BY wt.id DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':id', $clientId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countHistory(int $clientId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) c FROM wallet_transactions WHERE client_id = :id");
        $stmt->execute([':id' => $clientId]);
        return (int)($stmt->fetch()['c'] ?? 0);
    }

    /**
     * Friendly human label for a ledger source code.
     */
    public static function describeSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_SIGNUP_BONUS    => 'Sign-up Bonus',
            self::SOURCE_ADMIN_TOPUP     => 'Added by Admin',
            self::SOURCE_ADMIN_DEDUCT    => 'Adjusted by Admin',
            self::SOURCE_PAYMENT_GATEWAY => 'Payment Gateway',
            self::SOURCE_REVIEW_DEDUCT   => 'Review Deduction',
            self::SOURCE_REFUND          => 'Refund',
            default => ucwords(str_replace('_', ' ', $source)),
        };
    }

    // ---------------------- private helpers ----------------------

    private function lockClient(int $clientId): ?array
    {
        $s = $this->pdo->prepare("SELECT id, wallet_balance FROM clients WHERE id = :id LIMIT 1 FOR UPDATE");
        $s->execute([':id' => $clientId]);
        $row = $s->fetch();
        return $row ?: null;
    }

    private function updateBalance(int $clientId, int $newBalance): void
    {
        $u = $this->pdo->prepare("UPDATE clients SET wallet_balance = :b, updated_at = NOW() WHERE id = :id");
        $u->execute([':b' => $newBalance, ':id' => $clientId]);
    }

    private function writeLedger(
        int $clientId,
        string $type,
        int $amount,
        int $balanceAfter,
        string $source,
        ?string $description,
        ?int $adminId,
        ?int $relatedReviewSessionId
    ): void {
        $i = $this->pdo->prepare("
            INSERT INTO wallet_transactions
              (client_id, txn_type, amount, balance_after, source, description, related_admin_id, related_review_session_id, created_at)
            VALUES
              (:client_id, :txn_type, :amount, :balance_after, :source, :description, :admin_id, :session_id, NOW())
        ");
        $i->execute([
            ':client_id' => $clientId,
            ':txn_type' => $type,
            ':amount' => $amount,
            ':balance_after' => $balanceAfter,
            ':source' => $source,
            ':description' => $description,
            ':admin_id' => $adminId,
            ':session_id' => $relatedReviewSessionId,
        ]);
    }
}
