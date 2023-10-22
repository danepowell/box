<?php

declare(strict_types=1);

/*
 * This file is part of the box project.
 *
 * (c) Kevin Herrera <kevin@herrera.io>
 *     Théo Fidry <theo.fidry@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace KevinGH\Box\Console;

use Closure;
use DateTimeImmutable;
use Fidry\Console\Input\IO;
use KevinGH\Box\NotInstantiable;
use KevinGH\Box\Phar\CompressionAlgorithm;
use KevinGH\Box\Phar\PharInfo;
use KevinGH\Box\RequirementChecker\Requirement;
use KevinGH\Box\RequirementChecker\RequirementType;
use SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use function array_filter;
use function array_key_last;
use function array_map;
use function array_reduce;
use function array_sum;
use function array_values;
use function count;
use function implode;
use function is_array;
use function KevinGH\Box\format_size;
use function KevinGH\Box\format_size as format_size1;
use function KevinGH\Box\noop;
use function key;
use function round;
use function Safe\filesize;
use function sprintf;

/**
 * Utility to write to the console output various PHAR related pieces of information.
 *
 * @private
 */
final class PharInfoRenderer
{
    use NotInstantiable;

    private const BOX_REQUIREMENTS = '.box/.requirements.php';
    private const INDENT_SIZE = 2;

    public static function renderShortSummary(
        PharInfo $pharInfo,
        IO $io,
        ?Closure $separator = null,
    ): void {
        $separator ??= noop();

        $methods = [
            self::renderCompression(...),
            self::renderSignature(...),
            self::renderMetadata(...),
            self::renderTimestamp(...),
            self::renderRequirementChecker(...),
            self::renderContentsSummary(...),
        ];

        $lastIndex = count($methods) - 1;

        foreach ($methods as $index => $method) {
            $method($pharInfo, $io);

            if ($index !== $lastIndex) {
                $separator();
            }
        }
    }

    public static function renderVersion(PharInfo $pharInfo, IO $io): void
    {
        $io->writeln(
            sprintf(
                '<comment>API Version:</comment> %s',
                $pharInfo->getVersion(),
            ),
        );
    }

    public static function renderCompression(PharInfo $pharInfo, IO $io): void
    {
        $io->writeln(
            sprintf(
                '<comment>Archive Compression:</comment> %s',
                self::translateCompressionAlgorithm($pharInfo->getCompression()),
            ),
        );

        $count = $pharInfo->getFilesCompressionCount();
        // Rename "none" to "None"
        $count['None'] = $count[CompressionAlgorithm::NONE->name];
        unset($count[CompressionAlgorithm::NONE->name]);
        $count = array_filter($count);

        $totalCount = array_sum($count);

        if (1 === count($count)) {
            $io->writeln(
                sprintf(
                    '<comment>Files Compression:</comment> %s',
                    key($count),
                ),
            );

            return;
        }

        $io->writeln('<comment>Files Compression:</comment>');
        $lastAlgorithmName = array_key_last($count);

        $totalPercentage = 100;

        foreach ($count as $algorithmName => $nbrOfFiles) {
            if ($lastAlgorithmName === $algorithmName) {
                $percentage = $totalPercentage;
            } else {
                $percentage = round($nbrOfFiles * 100 / $totalCount, 2);

                $totalPercentage -= $percentage;
            }

            $io->writeln(
                sprintf(
                    '  - %s (%0.2f%%)',
                    $algorithmName,
                    $percentage,
                ),
            );
        }
    }

    public static function renderSignature(PharInfo $pharInfo, IO $io): void
    {
        $signature = $pharInfo->getSignature();

        if (null === $signature) {
            $io->writeln('<comment>Signature unreadable</comment>');

            return;
        }

        $io->writeln(
            sprintf(
                '<comment>Signature:</comment> %s',
                $signature['hash_type'],
            ),
        );
        $io->writeln(
            sprintf(
                '<comment>Signature Hash:</comment> %s',
                $signature['hash'],
            ),
        );
    }

    public static function renderMetadata(PharInfo $pharInfo, IO $io): void
    {
        $metadata = $pharInfo->getNormalizedMetadata();

        if (null === $metadata) {
            $io->writeln('<comment>Metadata:</comment> None');
        } else {
            $io->writeln('<comment>Metadata:</comment>');
            $io->writeln($metadata);
        }
    }

    public static function renderTimestamp(PharInfo $pharInfo, IO $io): void
    {
        $timestamp = $pharInfo->getTimestamp();
        $dateTime = (new DateTimeImmutable())->setTimestamp($timestamp);

        $io->writeln(
            sprintf(
                '<comment>Timestamp:</comment> %s (%s)',
                $timestamp,
                $dateTime->format(DateTimeImmutable::ATOM),
            ),
        );
    }

    public static function renderRequirementChecker(
        PharInfo $pharInfo,
        IO $io,
    ): void {
        $requirements = $pharInfo->getFiles()[self::BOX_REQUIREMENTS] ?? null;

        if (null === $requirements) {
            $io->writeln('<comment>RequirementChecker:</comment> Not found.');

            return;
        }

        $evaluatedRequirements = require $requirements->getPathname();

        if (!is_array($evaluatedRequirements)) {
            $io->writeln('<comment>RequirementChecker:</comment> Could not be checked.');

            return;
        }

        $io->write('<comment>RequirementChecker:</comment>');

        if (0 === count($evaluatedRequirements)) {
            $io->writeln(' No requirement found.');

            return;
        }
        $io->writeln('');

        [$required, $conflicting] = self::retrieveRequirements($evaluatedRequirements);

        self::renderRequiredSection($required, $io);
        self::renderConflictingSection($conflicting, $io);
    }

    public static function renderContentsSummary(PharInfo $pharInfo, IO $io): void
    {
        $count = array_filter($pharInfo->getFilesCompressionCount());
        $totalCount = array_sum($count);

        $io->writeln(
            sprintf(
                '<comment>Contents:</comment>%s (%s)',
                1 === $totalCount ? ' 1 file' : " {$totalCount} files",
                format_size(
                    filesize($pharInfo->getFile()),
                ),
            ),
        );
    }

    /**
     * @param false|positive-int|0 $maxDepth
     * @param false|int            $indent   Nbr of indent or `false`
     */
    public static function renderContent(
        OutputInterface $output,
        PharInfo $pharInfo,
        int|false $maxDepth,
        bool $indent,
    ): void {
        $depth = 0;
        $renderedDirectories = [];

        foreach ($pharInfo->getFiles() as $splFileInfo) {
            if (false !== $maxDepth && $depth > $maxDepth) {
                continue;
            }

            if ($indent) {
                self::renderParentDirectoriesIfNecessary(
                    $splFileInfo,
                    $output,
                    $depth,
                    $renderedDirectories,
                );
            }

            [
                'compression' => $compression,
                'compressedSize' => $compressionSize,
            ] = $pharInfo->getFileMeta($splFileInfo->getRelativePathname());

            $compressionLine = CompressionAlgorithm::NONE === $compression
                ? '<fg=red>[NONE]</fg=red>'
                : "<fg=cyan>[{$compression->name}]</fg=cyan>";

            self::print(
                $output,
                sprintf(
                    '%s %s - %s',
                    $indent
                        ? $splFileInfo->getFilename()
                        : $splFileInfo->getRelativePathname(),
                    $compressionLine,
                    format_size1($compressionSize),
                ),
                $depth,
                $indent,
            );
        }
    }

    /**
     * @return array{Requirement[], Requirement[]}
     */
    private static function retrieveRequirements(array $requirements): array
    {
        $evaluatedRequirements = array_map(
            Requirement::fromArray(...),
            $requirements,
        );

        [$required, $conflicting] = array_reduce(
            $evaluatedRequirements,
            static function ($carry, Requirement $requirement): array {
                $hash = implode(
                    ':',
                    [
                        $requirement->type->value,
                        $requirement->condition,
                        $requirement->source,
                    ],
                );

                if (RequirementType::EXTENSION_CONFLICT === $requirement->type) {
                    $carry[1][$hash] = $requirement;
                } else {
                    $carry[0][$hash] = $requirement;
                }

                return $carry;
            },
            [[], []],
        );

        return [
            array_values($required),
            array_values($conflicting),
        ];
    }

    /**
     * @param Requirement[] $required
     */
    private static function renderRequiredSection(
        array $required,
        IO $io,
    ): void {
        if (0 === count($required)) {
            return;
        }

        $io->writeln('  <comment>Required:</comment>');
        $io->writeln(
            array_map(
                static fn (Requirement $requirement) => match ($requirement->type) {
                    RequirementType::PHP => sprintf(
                        '  - PHP %s (%s)',
                        $requirement->condition,
                        $requirement->source ?? 'root',
                    ),
                    RequirementType::EXTENSION => sprintf(
                        '  - ext-%s (%s)',
                        $requirement->condition,
                        $requirement->source ?? 'root',
                    ),
                },
                $required,
            ),
        );
    }

    /**
     * @param Requirement[] $conflicting
     */
    private static function renderConflictingSection(
        array $conflicting,
        IO $io,
    ): void {
        if (0 === count($conflicting)) {
            return;
        }

        $io->writeln('  <comment>Conflict:</comment>');
        $io->writeln(
            array_map(
                static fn (Requirement $requirement) => sprintf(
                    '  - ext-%s (%s)',
                    $requirement->condition,
                    $requirement->source ?? 'root',
                ),
                $conflicting,
            ),
        );
    }

    private static function renderParentDirectoriesIfNecessary(
        SplFileInfo $fileInfo,
        OutputInterface $output,
        int &$depth,
        array &$renderedDirectories,
    ): void {
        $depth = 0;
        $relativePath = $fileInfo->getRelativePath();

        if ('' === $relativePath) {
            // No parent directory: there is nothing to do.
            return;
        }

        $parentDirectories = explode(
            '/',
            Path::normalize($relativePath),
        );

        foreach ($parentDirectories as $index => $parentDirectory) {
            if (array_key_exists($parentDirectory, $renderedDirectories)) {
                ++$depth;

                continue;
            }

            self::print(
                $output,
                "<info>{$parentDirectory}/</info>",
                $index,
                true,
            );

            $renderedDirectories[$parentDirectory] = true;
            ++$depth;
        }

        $depth = count($parentDirectories);
    }

    private static function print(
        OutputInterface $output,
        string $message,
        int $depth,
        bool $indent,
    ): void {
        if ($indent) {
            $output->write(str_repeat(' ', $depth * self::INDENT_SIZE));
        }

        $output->writeln($message);
    }

    private static function translateCompressionAlgorithm(CompressionAlgorithm $algorithm): string
    {
        return CompressionAlgorithm::NONE === $algorithm ? 'None' : $algorithm->name;
    }
}
