<?php

/*
 * This file is part of the "headless" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

declare(strict_types=1);

namespace FriendsOfTYPO3\Headless\DataProcessing;

use FriendsOfTYPO3\Headless\Utility\FileUtility;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * @codeCoverageIgnore
 */
class GalleryProcessor extends \TYPO3\CMS\Frontend\DataProcessing\GalleryProcessor
{
    use DataProcessingTrait;

    /**
     * @var FileReference[]
     */
    protected $fileReferenceCache = [];

    /**
     * @var array<int, array<string, string|array>>
     */
    protected $fileObjects = [];

    /**
     * @inheritDoc
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ) {
        $processedData = parent::process(
            $cObj,
            $contentObjectConfiguration,
            $processorConfiguration,
            $processedData
        );

        return $this->removeDataIfnotAppendInConfiguration($processorConfiguration, $processedData);
    }

    /**
     * @inheritDoc
     *
     * replaced only calls to $this->getCroppedDimensionalPropertyFromProcessedFile()
     * because of already processed files by FilesProcessor
     */
    protected function calculateMediaWidthsAndHeights()
    {
        $columnSpacingTotal = ($this->galleryData['count']['columns'] - 1) * $this->columnSpacing;

        $galleryWidthMinusBorderAndSpacing = max($this->galleryData['width'] - $columnSpacingTotal, 1);

        if ($this->borderEnabled) {
            $borderPaddingTotal = ($this->galleryData['count']['columns'] * 2) * $this->borderPadding;
            $borderWidthTotal = ($this->galleryData['count']['columns'] * 2) * $this->borderWidth;
            $galleryWidthMinusBorderAndSpacing = $galleryWidthMinusBorderAndSpacing - $borderPaddingTotal - $borderWidthTotal;
        }

        if ($this->equalMediaHeight) {
            // User entered a predefined height

            $mediaScalingCorrection = 1;
            $maximumRowWidth = 0;

            // Calculate the scaling correction when the total of media elements is wider than the gallery width
            for ($row = 1; $row <= $this->galleryData['count']['rows']; $row++) {
                $totalRowWidth = 0;
                for ($column = 1; $column <= $this->galleryData['count']['columns']; $column++) {
                    $fileKey = (($row - 1) * $this->galleryData['count']['columns']) + $column - 1;
                    if ($fileKey > $this->galleryData['count']['files'] - 1) {
                        break 2;
                    }
                    $currentMediaScaling = $this->equalMediaHeight / max($this->getCroppedDimensionalPropertyFromProcessedFile($this->fileObjects[$fileKey], 'height'), 1);
                    $totalRowWidth += $this->getCroppedDimensionalPropertyFromProcessedFile($this->fileObjects[$fileKey], 'width') * $currentMediaScaling;
                }
                $maximumRowWidth = max($totalRowWidth, $maximumRowWidth);
                $mediaInRowScaling = $totalRowWidth / $galleryWidthMinusBorderAndSpacing;
                $mediaScalingCorrection = max($mediaInRowScaling, $mediaScalingCorrection);
            }

            // Set the corrected dimensions for each media element
            foreach ($this->fileObjects as $key => $fileObject) {
                $mediaHeight = floor($this->equalMediaHeight / $mediaScalingCorrection);
                $mediaWidth = floor(
                    $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width') * ($mediaHeight / max($this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'height'), 1))
                );
                $this->mediaDimensions[$key] = [
                    'width' => $mediaWidth,
                    'height' => $mediaHeight,
                ];
            }

            // Recalculate gallery width
            $this->galleryData['width'] = floor($maximumRowWidth / $mediaScalingCorrection);
        } elseif ($this->equalMediaWidth) {
            // User entered a predefined width

            $mediaScalingCorrection = 1;

            // Calculate the scaling correction when the total of media elements is wider than the gallery width
            $totalRowWidth = $this->galleryData['count']['columns'] * $this->equalMediaWidth;
            $mediaInRowScaling = $totalRowWidth / $galleryWidthMinusBorderAndSpacing;
            $mediaScalingCorrection = max($mediaInRowScaling, $mediaScalingCorrection);

            // Set the corrected dimensions for each media element
            foreach ($this->fileObjects as $key => $fileObject) {
                $mediaWidth = floor($this->equalMediaWidth / $mediaScalingCorrection);
                $mediaHeight = floor(
                    $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'height') * ($mediaWidth / max($this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width'), 1))
                );
                $this->mediaDimensions[$key] = [
                    'width' => $mediaWidth,
                    'height' => $mediaHeight,
                ];
            }

            // Recalculate gallery width
            $this->galleryData['width'] = floor($totalRowWidth / $mediaScalingCorrection);
        } else {
            // Automatic setting of width and height

            $maxMediaWidth = (int)($galleryWidthMinusBorderAndSpacing / $this->galleryData['count']['columns']);
            foreach ($this->fileObjects as $key => $fileObject) {
                $croppedWidth = $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width');
                $mediaWidth = $croppedWidth > 0 ? min($maxMediaWidth, $croppedWidth) : $maxMediaWidth;
                $mediaHeight = floor(
                    $this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'height') * ($mediaWidth / max($this->getCroppedDimensionalPropertyFromProcessedFile($fileObject, 'width'), 1))
                );
                $this->mediaDimensions[$key] = [
                    'width' => $mediaWidth,
                    'height' => $mediaHeight,
                ];
            }
        }
    }

    /**
     * Replaces original method (because of already processed files)
     *
     * @param array $processedFile
     * @param string $property
     * @return int
     */
    private function getCroppedDimensionalPropertyFromProcessedFile(array $processedFile, string $property): int
    {
        if (empty($processedFile['properties']['crop'])) {
            return (int)$processedFile['properties']['dimensions'][$property];
        }

        $croppingConfiguration = $processedFile['properties']['crop'];
        $cropVariantCollection = CropVariantCollection::create((string)$croppingConfiguration);

        return (int)$cropVariantCollection->getCropArea($this->cropVariant)
            ->makeAbsoluteBasedOnFile($this->createFileObject($processedFile))
            ->asArray()[$property];
    }

    /**
     * Prepare the gallery data
     *
     * Make an array for rows, columns and configuration
     */
    protected function prepareGalleryData()
    {
        $formats = $this->processorConfiguration['formats.'] ?? [];

        // Legacy workaround
        $autogenerateConfig = $this->processorConfiguration['autogenerate.'] ?? null;
        if ($autogenerateConfig) {
            if (($autogenerateConfig['retina2x'] ?? 0) == 1) {
                $formats['urlRetina'] = [
                    'factor' => FileUtility::RETINA_RATIO,
                ];
            }
            if (($autogenerateConfig['lqip'] ?? 0) == 1) {
                $formats['urlLqip'] = [
                    'factor' => FileUtility::LQIP_RATIO,
                ];
            }
        }

        for ($row = 1; $row <= $this->galleryData['count']['rows']; $row++) {
            for ($column = 1; $column <= $this->galleryData['count']['columns']; $column++) {
                $fileKey = (($row - 1) * $this->galleryData['count']['columns']) + $column - 1;
                $fileObj = $this->fileObjects[$fileKey] ?? null;

                if ($fileObj) {
                    $fileExtension = $this->processorConfiguration['fileExtension'] ?? null;

                    if ($fileObj['properties']['type'] === 'image') {
                        $image = $this->getImageService()->getImage((string)$fileObj['properties']['fileReferenceUid'], null, true);

                        // 1. render image as usual
                        $fileObj = $this->getFileUtility()->processFile(
                            $image,
                            array_merge(
                                ['fileExtension' => $fileExtension],
                                $this->mediaDimensions[$fileKey] ?? []
                            )
                        );

                        // 2. render additional formats
                        $originalWidth = $image->getProperty('width');
                        $originalHeight = $image->getProperty('height');
                        $targetWidth = $fileObj['properties']['dimensions']['width'];
                        $targetHeight = $fileObj['properties']['dimensions']['height'];
                        foreach ($formats ?? [] as $formatKey => $formatConf) {
                            $formatKey = rtrim($formatKey, '.');
                            $factor = (float)($formatConf['factor'] ?? 1.0);

                            $fileObj[$formatKey] = $this->getFileUtility()->processFile(
                                $image,
                                [
                                    'fileExtension' => $formatConf['fileExtension'] ?? null,
                                    // multiply width/height by factor,
                                    // but don't stretch image beyond its original dimensions!
                                    'width' => min($targetWidth * $factor, $originalWidth),
                                    'height' => min($targetHeight * $factor, $originalHeight),
                                ]
                            )['publicUrl'];
                        }
                    }

                    $this->galleryData['rows'][$row]['columns'][$column] = $fileObj;
                }
            }
        }

        $this->galleryData['columnSpacing'] = $this->columnSpacing;
        $this->galleryData['border']['enabled'] = $this->borderEnabled;
        $this->galleryData['border']['width'] = $this->borderWidth;
        $this->galleryData['border']['padding'] = $this->borderPadding;
    }

    /**
     * @return FileUtility
     */
    protected function getFileUtility(): FileUtility
    {
        return GeneralUtility::makeInstance(FileUtility::class);
    }

    /**
     * @return ImageService
     */
    protected function getImageService(): ImageService
    {
        return GeneralUtility::makeInstance(ImageService::class);
    }

    /**
     * small helper for handling cropping based on already processed file
     *
     * @param array $processedFile
     * @return FileInterface
     */
    private function createFileObject(array $processedFile): FileInterface
    {
        $uid = (int)$processedFile['properties']['uidLocal'];
        if (!isset($this->fileReferenceCache[$uid])) {
            $this->fileReferenceCache[$uid] = GeneralUtility::makeInstance(
                FileReference::class,
                array_merge(
                    $processedFile['properties'],
                    $processedFile['properties']['dimensions'],
                    ['uid_local' => $uid]
                )
            );
        }

        return $this->fileReferenceCache[$uid];
    }
}
