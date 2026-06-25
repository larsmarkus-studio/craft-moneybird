<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\services;

use Craft;
use craft\base\Component;
use larsmarkusstudio\moneybird\events\ReceiptCreatedEvent;
use larsmarkusstudio\moneybird\models\Receipt;
use larsmarkusstudio\moneybird\models\ReceiptLine;
use larsmarkusstudio\moneybird\Plugin;

/**
 * Create Moneybird receipts (documents) and attach PDFs.
 *
 * Receipts are created via `documents/receipts`; attachments are uploaded as
 * multipart to the receipt's `attachments` endpoint.
 */
class DocumentsService extends Component
{
    public const EVENT_RECEIPT_CREATED = 'receiptCreated';

    /**
     * Create a receipt with the given lines.
     *
     * @param ReceiptLine[] $lines
     */
    public function createReceipt(
        int $userId,
        string $contactId,
        string $date,
        string $reference,
        array $lines,
    ): Receipt {
        $data = Plugin::getInstance()->api->post($userId, 'documents/receipts.json', [
            'receipt' => [
                'contact_id' => $contactId,
                'date' => $date,
                'reference' => $reference,
                'details_attributes' => array_map(
                    static fn(ReceiptLine $line) => $line->toApi(),
                    $lines,
                ),
            ],
        ]);

        $receipt = Receipt::fromApi($data);

        if ($this->hasEventHandlers(self::EVENT_RECEIPT_CREATED)) {
            $event = new ReceiptCreatedEvent();
            $event->userId = $userId;
            $event->receipt = $receipt;
            $this->trigger(self::EVENT_RECEIPT_CREATED, $event);
        }

        return $receipt;
    }

    /**
     * Attach a PDF (raw binary) to an existing receipt.
     */
    public function attachPdf(int $userId, string $receiptId, string $pdfContent, string $filename): bool
    {
        $auth = Plugin::getInstance()->auth;
        $administrationId = $auth->getAdministrationId($userId);
        $accessToken = $auth->getValidAccessToken($userId);

        if ($administrationId === null || $accessToken === null) {
            return false;
        }

        $url = AuthService::API_BASE . $administrationId
            . "/documents/receipts/{$receiptId}/attachments.json";

        $response = Craft::createGuzzleClient()->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $pdfContent,
                    'filename' => $filename,
                    'headers' => ['Content-Type' => 'application/pdf'],
                ],
            ],
        ]);

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }
}
