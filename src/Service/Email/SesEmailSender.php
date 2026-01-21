<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Enum\EmailProvider;
use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Psr\Log\LoggerInterface;

/**
 * AWS SES email sender.
 */
class SesEmailSender extends AbstractEmailSender
{
    private ?SesClient $client = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $awsRegion,
        private readonly string $awsKey,
        private readonly string $awsSecret,
        string $defaultFromEmail,
        string $defaultFromName = '',
    ) {
        parent::__construct($defaultFromEmail, $defaultFromName);
    }

    public function getProvider(): EmailProvider
    {
        return EmailProvider::SES;
    }

    public function supports(EmailProvider $provider): bool
    {
        return $provider === EmailProvider::SES;
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        if (!$this->isConfigured()) {
            return EmailSendResult::failure('SES is not configured', [
                'provider' => 'ses',
            ]);
        }

        try {
            $client = $this->getClient();

            $fromEmail = $message->from ?? $this->defaultFromEmail;
            $fromName = $message->fromName ?? $this->defaultFromName;

            $source = $fromName !== ''
                ? sprintf('%s <%s>', $fromName, $fromEmail)
                : $fromEmail;

            $destination = $message->toName !== null && $message->toName !== ''
                ? sprintf('%s <%s>', $message->toName, $message->to)
                : $message->to;

            $params = [
                'Source' => $source,
                'Destination' => [
                    'ToAddresses' => [$destination],
                ],
                'Message' => [
                    'Subject' => [
                        'Data' => $message->subject,
                        'Charset' => 'UTF-8',
                    ],
                    'Body' => [
                        'Html' => [
                            'Data' => $message->htmlBody,
                            'Charset' => 'UTF-8',
                        ],
                    ],
                ],
            ];

            // Add text body if available
            if ($message->textBody !== null) {
                $params['Message']['Body']['Text'] = [
                    'Data' => $message->textBody,
                    'Charset' => 'UTF-8',
                ];
            }

            // Add reply-to
            if ($message->replyTo !== null) {
                $params['ReplyToAddresses'] = [$message->replyTo];
            }

            $result = $client->sendEmail($params);
            $messageId = $result->get('MessageId');

            $this->logger->info('Email sent via SES', [
                'to' => $message->to,
                'subject' => $message->subject,
                'message_id' => $messageId,
            ]);

            return EmailSendResult::success($messageId, [
                'provider' => 'ses',
                'request_id' => $result->get('@metadata')['requestId'] ?? null,
            ]);
        } catch (AwsException $e) {
            $this->logger->error('SES send failed', [
                'to' => $message->to,
                'error' => $e->getMessage(),
                'aws_code' => $e->getAwsErrorCode(),
            ]);

            return EmailSendResult::failure($e->getMessage(), [
                'provider' => 'ses',
                'aws_code' => $e->getAwsErrorCode(),
                'aws_type' => $e->getAwsErrorType(),
            ]);
        }
    }

    public function isConfigured(): bool
    {
        return $this->awsRegion !== ''
            && $this->awsKey !== ''
            && $this->awsSecret !== ''
            && $this->defaultFromEmail !== '';
    }

    private function getClient(): SesClient
    {
        if ($this->client === null) {
            $this->client = new SesClient([
                'version' => 'latest',
                'region' => $this->awsRegion,
                'credentials' => [
                    'key' => $this->awsKey,
                    'secret' => $this->awsSecret,
                ],
            ]);
        }

        return $this->client;
    }
}
