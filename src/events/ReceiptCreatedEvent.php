<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\events;

use larsmarkusstudio\moneybird\models\Receipt;
use yii\base\Event;

/**
 * Fired after a receipt/document is successfully created in Moneybird.
 */
class ReceiptCreatedEvent extends Event
{
    public int $userId;
    public Receipt $receipt;
}
