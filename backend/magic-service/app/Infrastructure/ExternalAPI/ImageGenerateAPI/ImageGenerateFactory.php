<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace App\Infrastructure\ExternalAPI\ImageGenerateAPI;

use App\Domain\ModelAdmin\Entity\ValueObject\ServiceProviderConfig;
use App\ErrorCode\ServiceProviderErrorCode;
use App\Infrastructure\Core\Exception\ExceptionBuilder;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\AzureOpenAI\AzureOpenAIImageEditModel;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\AzureOpenAI\AzureOpenAIImageGenerateModel;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\Flux\FluxModel;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\GPT\GPT4oModel;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\Midjourney\MidjourneyModel;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\MiracleVision\MiracleVisionModel;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\Volcengine\VolcengineImageGenerateV3Model;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Model\Volcengine\VolcengineModel;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\AzureOpenAIImageEditRequest;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\AzureOpenAIImageGenerateRequest;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\FluxModelRequest;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\GPT4oModelRequest;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\ImageGenerateRequest;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\MidjourneyModelRequest;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\VolcengineModelRequest;
use InvalidArgumentException;

class ImageGenerateFactory
{
    public static function create(ImageGenerateModelType $imageGenerateType, ServiceProviderConfig $serviceProviderConfig): ImageGenerate
    {
        return match ($imageGenerateType) {
            ImageGenerateModelType::Midjourney => new MidjourneyModel($serviceProviderConfig),
            ImageGenerateModelType::Volcengine => new VolcengineModel($serviceProviderConfig),
            ImageGenerateModelType::VolcengineImageGenerateV3 => new VolcengineImageGenerateV3Model($serviceProviderConfig),
            ImageGenerateModelType::Flux => new FluxModel($serviceProviderConfig),
            ImageGenerateModelType::MiracleVision => new MiracleVisionModel($serviceProviderConfig),
            ImageGenerateModelType::TTAPIGPT4o => new GPT4oModel($serviceProviderConfig),
            ImageGenerateModelType::AzureOpenAIImageGenerate => new AzureOpenAIImageGenerateModel($serviceProviderConfig),
            ImageGenerateModelType::AzureOpenAIImageEdit => new AzureOpenAIImageEditModel($serviceProviderConfig),
            default => throw new InvalidArgumentException('not support ' . $imageGenerateType->value),
        };
    }

    public static function createRequestType(ImageGenerateModelType $imageGenerateType, array $data): ImageGenerateRequest
    {
        return match ($imageGenerateType) {
            ImageGenerateModelType::Volcengine => self::createVolcengineRequest($data),
            ImageGenerateModelType::VolcengineImageGenerateV3 => self::createVolcengineRequest($data),
            ImageGenerateModelType::Midjourney => self::createMidjourneyRequest($data),
            ImageGenerateModelType::Flux => self::createFluxRequest($data),
            ImageGenerateModelType::TTAPIGPT4o => self::createGPT4oRequest($data),
            ImageGenerateModelType::AzureOpenAIImageGenerate => self::createAzureOpenAIImageGenerateRequest($data),
            ImageGenerateModelType::AzureOpenAIImageEdit => self::createAzureOpenAIImageEditRequest($data),
            default => throw new InvalidArgumentException('not support ' . $imageGenerateType->value),
        };
    }

    private static function createGPT4oRequest(array $data): GPT4oModelRequest
    {
        $request = new GPT4oModelRequest();
        $request->setReferImages($data['reference_images']);
        $request->setPrompt($data['user_prompt']);
        return $request;
    }

    private static function createVolcengineRequest(array $data): VolcengineModelRequest
    {
        $request = new VolcengineModelRequest(
            (string) $data['width'],
            (string) $data['height'],
            $data['user_prompt'],
            $data['negative_prompt'],
        );
        isset($data['generate_num']) && $request->setGenerateNum($data['generate_num']);
        $request->setUseSr((bool) $data['use_sr']);
        $request->setReferenceImage($data['reference_images']);
        $request->setModel($data['model']);
        return $request;
    }

    private static function createMidjourneyRequest(array $data): MidjourneyModelRequest
    {
        $model = $data['model'];
        $mode = strtolower(explode('-', $model, limit: 2)[1] ?? 'fast');
        $request = new MidjourneyModelRequest($data['width'], $data['height'], $data['user_prompt'], $data['negative_prompt']);
        $request->setModel($mode);
        $request->setRatio($data['ratio']);
        isset($data['generate_num']) && $request->setGenerateNum($data['generate_num']);
        return $request;
    }

    private static function createFluxRequest(array $data): FluxModelRequest
    {
        $model = $data['model'];
        if (! in_array($model, ImageGenerateModelType::getFluxModes())) {
            ExceptionBuilder::throw(ServiceProviderErrorCode::ModelNotFound);
        }
        $model = strtolower($model);

        $width = (int) $data['width'];
        $height = (int) $data['height'];

        // todo xhy 先兜底，因为整个文生图还没有闭环
        if (
            ! ($width === 1024 && $height === 1024)
            && ! ($width === 1024 && $height === 1792)
            && ! ($width === 1792 && $height === 1024)
        ) {
            $width = 1024;
            $height = 1024;
        }

        $request = new FluxModelRequest((string) $width, (string) $height, $data['user_prompt'], $data['negative_prompt']);
        $request->setModel($model);
        isset($data['generate_num']) && $request->setGenerateNum($data['generate_num']);
        $request->setWidth((string) $width);
        $request->setHeight((string) $height);
        return $request;
    }

    private static function createAzureOpenAIImageGenerateRequest(array $data): AzureOpenAIImageGenerateRequest
    {
        $request = new AzureOpenAIImageGenerateRequest();
        $request->setPrompt($data['user_prompt']);

        // Set optional parameters
        if (isset($data['size'])) {
            $request->setSize($data['size']);
        }
        if (isset($data['quality'])) {
            $request->setQuality($data['quality']);
        }
        if (isset($data['generate_num'])) {
            $request->setN((int) $data['generate_num']);
        }
        // Handle image URLs from different sources
        if (isset($data['reference_images']) && is_array($data['reference_images'])) {
            $request->setReferenceImages($data['reference_images']);
        } elseif (isset($data['reference_images'])) {
            // Backward compatibility for single image
            $request->setReferenceImages([$data['reference_images']]);
        } else {
            // Default to empty array if no images provided
            $request->setReferenceImages([]);
        }

        return $request;
    }

    private static function createAzureOpenAIImageEditRequest(array $data): AzureOpenAIImageEditRequest
    {
        $request = new AzureOpenAIImageEditRequest();
        $request->setPrompt($data['user_prompt'] ?? $data['prompt'] ?? '');

        // Handle image URLs from different sources
        if (isset($data['reference_images']) && is_array($data['reference_images'])) {
            $request->setReferenceImages($data['reference_images']);
        } elseif (isset($data['reference_images'])) {
            // Backward compatibility for single image
            $request->setReferenceImages([$data['reference_images']]);
        } else {
            // Default to empty array if no images provided
            $request->setReferenceImages([]);
        }

        // Optional mask parameter
        if (isset($data['mask_url'])) {
            $request->setMaskUrl($data['mask_url']);
        }

        // Set size based on width and height if available, otherwise use default
        if (isset($data['width'], $data['height'])) {
            $size = $data['width'] . 'x' . $data['height'];
            $request->setSize($size);
        } elseif (isset($data['size'])) {
            $request->setSize($data['size']);
        }

        // Set number of images to generate
        if (isset($data['generate_num'])) {
            $request->setN((int) $data['generate_num']);
        } elseif (isset($data['n'])) {
            $request->setN((int) $data['n']);
        }

        return $request;
    }
}
