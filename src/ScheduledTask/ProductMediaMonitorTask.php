<?php declare(strict_types=1);

namespace Compumess\ProductMediaMonitor\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ProductMediaMonitorTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'compumess.product_media_monitor_task';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 24 hours
    }
}
