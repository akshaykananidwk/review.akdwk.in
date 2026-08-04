<?php
declare(strict_types=1);

/**
 * Central cron job registry.
 * -------------------------------------------------------------------
 * To add a new scheduled task:
 *   1. Create app/cron_jobs/<ClassName>.php with a
 *      `run(PDO $pdo, CronLogger $log): string` method (return a short
 *      summary; throw on failure).
 *   2. Add one entry here. Done — no new server cron needed. The master
 *      scheduler picks it up on the next tick and the admin panel shows
 *      it automatically.
 *
 * schedule_type / schedule_value:
 *   every_minutes  "N"        run every N minutes
 *   daily          "HH:MM"    run once a day at HH:MM (Asia/Kolkata)
 *   weekly         "D HH:MM"  run weekly, D = 0 (Sunday) … 6 (Saturday)
 *
 * The values below are only the FIRST-INSTALL defaults; after that the
 * database rows (managed from Admin → Cron Settings) are authoritative.
 */
return [
    'notification_queue' => [
        'class' => 'NotificationQueueJob',
        'name' => 'Notification Queue (WhatsApp / Email)',
        'description' => 'Delivers queued WhatsApp/email messages: reminders, follow-ups and scheduled notifications, with retries and backoff.',
        'schedule_type' => 'every_minutes',
        'schedule_value' => '1',
        'enabled_by_default' => true,
    ],
    'refill_buffer' => [
        'class' => 'RefillBufferJob',
        'name' => 'AI Review Buffer Refill',
        'description' => 'Tops up every active client\'s pre-generated AI review buffer to the target count.',
        'schedule_type' => 'every_minutes',
        'schedule_value' => '10',
        'enabled_by_default' => true,
    ],
    'daily_review_summary' => [
        'class' => 'DailyReviewSummaryJob',
        'name' => 'Daily Review Summary (Auto Report)',
        'description' => 'Sends each client their daily 5-star review count report on WhatsApp.',
        'schedule_type' => 'daily',
        'schedule_value' => '22:00',
        'enabled_by_default' => true,
    ],
    'subscription_expiry' => [
        'class' => 'SubscriptionExpiryJob',
        'name' => 'Subscription & Plan Expiry Check',
        'description' => 'Queues payment-due WhatsApp reminders before plan expiry (and on expiry day) using Global Settings reminder days.',
        'schedule_type' => 'daily',
        'schedule_value' => '10:00',
        'enabled_by_default' => true,
    ],
    'wallet_low_balance' => [
        'class' => 'WalletLowBalanceJob',
        'name' => 'Wallet Low Balance Reminder',
        'description' => 'Queues a recharge reminder for clients whose wallet balance dropped below the configured threshold.',
        'schedule_type' => 'daily',
        'schedule_value' => '10:30',
        'enabled_by_default' => true,
    ],
    'auto_backup' => [
        'class' => 'AutoBackupJob',
        'name' => 'Automatic Backup (Code + Database)',
        'description' => 'Creates a full code + database backup (same engine as the update system; old backups are pruned automatically).',
        'schedule_type' => 'weekly',
        'schedule_value' => '0 03:00',
        'enabled_by_default' => true,
    ],
    'cleanup' => [
        'class' => 'CleanupJob',
        'name' => 'Housekeeping & Log Cleanup',
        'description' => 'Purges expired password-reset tokens, stale rate-limit rows, old cron run history and delivered queue rows.',
        'schedule_type' => 'daily',
        'schedule_value' => '03:30',
        'enabled_by_default' => true,
    ],
];
