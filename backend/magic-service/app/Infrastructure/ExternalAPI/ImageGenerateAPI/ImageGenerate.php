<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace App\Infrastructure\ExternalAPI\ImageGenerateAPI;

use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Request\ImageGenerateRequest;
use App\Infrastructure\ExternalAPI\ImageGenerateAPI\Response\ImageGenerateResponse;

interface ImageGenerate
{
    // 重试次数
    public const GENERATE_RETRY_COUNT = 3;

    // 重试时间
    public const GENERATE_RETRY_TIME = 1000;

    public const IMAGE_GENERATE_KEY_PREFIX = 'text2image:';

    public const IMAGE_GENERATE_SUBMIT_KEY_PREFIX = 'submit:';

    public const IMAGE_GENERATE_POLL_KEY_PREFIX = 'poll:';

    /**
     * 生成图像并返回标准格式的响应.
     */
    public function generateImage(ImageGenerateRequest $imageGenerateRequest): ImageGenerateResponse;

    /**
     * 生成图像并返回第三方原生格式的数据.
     */
    public function generateImageRaw(ImageGenerateRequest $imageGenerateRequest): array;

    public function setAK(string $ak);

    public function setSK(string $sk);

    public function setApiKey(string $apiKey);
}
