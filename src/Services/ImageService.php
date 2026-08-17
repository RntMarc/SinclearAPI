<?php

namespace Sinclear\Api\Services;

use Psr\Log\LoggerInterface;

final readonly class ImageService
{
    private const int MAX_IMAGE_SIZE = 200 * 1024;
    private const int MAX_IMAGE_DIMENSION = 1000;
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Validates a base64-encoded image string.
     *
     * @param string $imageData Base64-encoded image data
     * @param int|null $maxSize Max decoded size in bytes (null = self::MAX_IMAGE_SIZE)
     * @param int|null $maxDimension Max width or height in pixels (null = self::MAX_IMAGE_DIMENSION)
     * @return string Original validated base64 data
     * @throws \InvalidArgumentException On validation failure
     */
    public function validate(string $imageData, ?int $maxSize = null, ?int $maxDimension = null): string
    {
        $maxSize ??= self::MAX_IMAGE_SIZE;
        $maxDimension ??= self::MAX_IMAGE_DIMENSION;

        $this->logger->debug('ImageService: validating image', [
            'base64_length' => strlen($imageData),
            'max_size' => $maxSize,
            'max_dimension' => $maxDimension,
        ]);

        if ($imageData === '') {
            throw new \InvalidArgumentException('invalid_image');
        }

        $decoded = base64_decode($imageData, true);
        if ($decoded === false) {
            $this->logger->warning('ImageService: base64 decode failed');
            throw new \InvalidArgumentException('invalid_image_encoding');
        }

        $decodedSize = strlen($decoded);
        $this->logger->debug('ImageService: decoded size', [
            'decoded_bytes' => $decodedSize,
            'max_allowed' => $maxSize,
        ]);

        if ($decodedSize > $maxSize) {
            $this->logger->debug('ImageService: image too large', [
                'decoded_bytes' => $decodedSize,
                'max_allowed' => $maxSize,
            ]);
            throw new \InvalidArgumentException('image_too_large');
        }

        $imageInfo = @getimagesizefromstring($decoded);
        if ($imageInfo === false) {
            $this->logger->debug('ImageService: not a valid image');
            throw new \InvalidArgumentException('invalid_image_format');
        }

        $mimeType = $imageInfo['mime'];
        $width = $imageInfo[0];
        $height = $imageInfo[1];

        $this->logger->debug('ImageService: image info', [
            'mime' => $mimeType,
            'width' => $width,
            'height' => $height,
        ]);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            $this->logger->debug('ImageService: unsupported mime type', [
                'mime' => $mimeType,
                'allowed' => self::ALLOWED_MIME_TYPES,
            ]);
            throw new \InvalidArgumentException('unsupported_image_format');
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            $this->logger->debug('ImageService: dimensions too large', [
                'width' => $width,
                'height' => $height,
                'max' => $maxDimension,
            ]);
            throw new \InvalidArgumentException('image_dimensions_too_large');
        }

        $this->logger->debug('ImageService: validation passed');
        return $imageData;
    }
}