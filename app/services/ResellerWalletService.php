<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/WalletService.php';

final class ResellerWalletService
{
    /**
     * Super Admin credits a reseller master wallet (no client involved).
     */
    public static function creditResellerWallet(
        PDO $pdo,
        int $resellerAdminId,
        int $amount,
        int $superAdminId,
        ?string $note = null
    ): ?int {
        $amount = max(0, $amount);
        if ($amount === 0) {
            return null;
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT id, reseller_wallet_balance FROM admins WHERE id = :id AND role = :r FOR UPDATE');
            $st->execute([':id' => $resellerAdminId, ':r' => 'reseller']);
            $row = $st->fetch();
            if (!$row) {
                $pdo->rollBack();
                return null;
            }
            $bal = (int)$row['reseller_wallet_balance'] + $amount;
            $pdo->prepare('UPDATE admins SET reseller_wallet_balance = :b, updated_at = NOW() WHERE id = :id')
                ->execute([':b' => $bal, ':id' => $resellerAdminId]);
            $pdo->prepare("
                INSERT INTO reseller_wallet_transactions
                  (reseller_admin_id, txn_type, amount, balance_after, source, description, related_super_admin_id, created_at)
                VALUES
                  (:rid, 'credit', :amt, :bal, 'super_admin_topup', :desc, :said, NOW())
            ")->execute([
                ':rid' => $resellerAdminId,
                ':amt' => $amount,
                ':bal' => $bal,
                ':desc' => $note ?? 'Super admin bulk credit',
                ':said' => $superAdminId,
            ]);
            $pdo->commit();
            return $bal;
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }

    /**
     * Reseller moves credits from master wallet into a sub-client wallet.
     */
    public static function transferToClientWallet(
        PDO $pdo,
        int $resellerAdminId,
        int $clientId,
        int $amount,
        ?string $note = null
    ): ?array {
        $amount = max(0, $amount);
        if ($amount === 0) {
            return null;
        }
        try {
            $pdo->beginTransaction();

            $c = $pdo->prepare('SELECT id, reseller_id FROM clients WHERE id = :id FOR UPDATE');
            $c->execute([':id' => $clientId]);
            $client = $c->fetch();
            if (!$client || (int)($client['reseller_id'] ?? 0) !== $resellerAdminId) {
                $pdo->rollBack();
                return null;
            }

            $st = $pdo->prepare('SELECT id, reseller_wallet_balance FROM admins WHERE id = :id AND role = :r FOR UPDATE');
            $st->execute([':id' => $resellerAdminId, ':r' => 'reseller']);
            $row = $st->fetch();
            if (!$row) {
                $pdo->rollBack();
                return null;
            }
            $cur = (int)$row['reseller_wallet_balance'];
            if ($cur < $amount) {
                $pdo->rollBack();
                return null;
            }
            $newRes = $cur - $amount;
            $pdo->prepare('UPDATE admins SET reseller_wallet_balance = :b, updated_at = NOW() WHERE id = :id')
                ->execute([':b' => $newRes, ':id' => $resellerAdminId]);

            $pdo->prepare("
                INSERT INTO reseller_wallet_transactions
                  (reseller_admin_id, txn_type, amount, balance_after, source, description, related_client_id, created_at)
                VALUES
                  (:rid, 'debit', :amt, :bal, 'client_transfer', :desc, :cid, NOW())
            ")->execute([
                ':rid' => $resellerAdminId,
                ':amt' => $amount,
                ':bal' => $newRes,
                ':desc' => $note ?? 'Transfer to client wallet',
                ':cid' => $clientId,
            ]);

            $wallet = new WalletService($pdo);
            $clientBal = $wallet->credit(
                $clientId,
                $amount,
                WalletService::SOURCE_RESELLER_CREDIT,
                $note ?? 'Credit from reseller master wallet',
                $resellerAdminId
            );
            if ($clientBal === null) {
                $pdo->rollBack();
                return null;
            }

            $pdo->commit();
            return ['reseller_balance' => $newRes, 'client_balance' => $clientBal];
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }
}
