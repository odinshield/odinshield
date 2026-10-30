<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Monolog;

use App\Monolog\InstanceProcessor;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Monolog\Handler\MailerHandler;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * Names the sending instance in prod error mails (#144).
 *
 * Q, Demo and the customer test run the same image against the same recipient;
 * without a marker every mail read `[OdinShield prod]`. Worker and console errors
 * carry no request, so the marker must not depend on one.
 */
final class InstanceProcessorTest extends TestCase
{
    #[DataProvider('defaultUris')]
    public function testInstanceIsDerivedFromTheDefaultUri(string $defaultUri, string $expected): void
    {
        $record = (new InstanceProcessor($defaultUri))($this->record(Level::Error, 'CSAF provider failed'));

        self::assertSame($expected, $record->extra['instance']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function defaultUris(): iterable
    {
        yield 'Traefik host' => ['https://q.example.org', 'q.example.org'];
        yield 'public URL with path' => ['https://odinshield.example.com/app/', 'odinshield.example.com'];
        yield 'non-default port' => ['https://vm07.example.net:8443', 'vm07.example.net:8443'];
        yield 'dev default' => ['http://localhost', 'localhost'];
        yield 'bare host without scheme' => ['q.example.org', 'q.example.org'];
        yield 'empty' => ['', 'unknown'];
    }

    public function testExistingExtraIsKept(): void
    {
        $record = $this->record(Level::Error, 'Forbidden')->with(extra: ['prefetch' => true]);

        $processed = (new InstanceProcessor('https://q.example.org'))($record);

        self::assertSame(['prefetch' => true, 'instance' => 'q.example.org'], $processed->extra);
    }

    public function testErrorMailNamesTheInstanceInSubjectAndBody(): void
    {
        $mailer = new class () implements MailerInterface {
            public ?RawMessage $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };
        $handler = new MailerHandler($mailer, (new Email())->subject($this->prodSubject()));
        $handler->setFormatter(new HtmlFormatter());
        $handler->pushProcessor(new InstanceProcessor('https://q.example.org'));

        $handler->handleBatch([
            $this->record(Level::Info, 'CSAF document skipped (rebuild only, no content change)'),
            $this->record(Level::Error, 'CSAF provider failed'),
        ]);

        self::assertInstanceOf(Email::class, $mailer->sent);
        self::assertSame('[OdinShield q.example.org] ERROR: CSAF provider failed', $mailer->sent->getSubject());
        $body = $mailer->sent->getHtmlBody();
        self::assertIsString($body);
        self::assertStringContainsString('q.example.org', $body);
    }

    /**
     * MonologBundle autoconfigures every ProcessorInterface onto every handler in
     * every environment, and `handler: symfony_mailer` cannot be declared on the
     * class itself: that handler exists only in prod, and the processor pass
     * aborts the dev/test container when it is missing.
     */
    public function testProcessorIsBoundToTheProdMailHandlerOnly(): void
    {
        self::assertFalse(
            (new \ReflectionClass(InstanceProcessor::class))->implementsInterface(ProcessorInterface::class),
            'Implementing ProcessorInterface would stamp every log line in every environment.',
        );

        $services = Yaml::parseFile(\dirname(__DIR__, 3).'/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $definition = \is_array($services) ? ($services['when@prod']['services'][InstanceProcessor::class] ?? null) : null;
        self::assertIsArray($definition, 'config/services.yaml must register the processor under when@prod.');
        self::assertSame([['name' => 'monolog.processor', 'handler' => 'symfony_mailer']], $definition['tags'] ?? null);
    }

    private function prodSubject(): string
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/monolog.yaml');
        $subject = \is_array($config) ? ($config['when@prod']['monolog']['handlers']['symfony_mailer']['subject'] ?? null) : null;
        self::assertIsString($subject, 'config/packages/monolog.yaml must define the prod mail subject.');

        // `%%` escapes a literal `%` from the container's parameter resolution.
        return str_replace('%%', '%', $subject);
    }

    private function record(Level $level, string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'csaf', $level, $message);
    }
}
