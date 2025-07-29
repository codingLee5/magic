<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Domain\SuperAgent\Service;

use App\Application\Chat\Service\MagicUserInfoAppService;
use App\Application\File\Service\FileAppService;
use App\Domain\Chat\DTO\Message\Common\MessageExtra\SuperAgent\Mention\MentionType;
use App\Domain\Contact\Entity\ValueObject\DataIsolation;
use App\Infrastructure\Core\ValueObject\StorageBucketType;
use App\Infrastructure\Util\IdGenerator\IdGenerator;
use App\Interfaces\Agent\Assembler\FileAssembler;
use App\Interfaces\Authorization\Web\MagicUserAuthorization;
use Dtyq\SuperMagic\Application\SuperAgent\Service\FileProcessAppService;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\MessageMetadata;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\MessageType;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TaskContext;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\UserInfoValueObject;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Agent\Constant\WorkspaceStatus;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Agent\Request\ChatMessageRequest;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Agent\Request\InitAgentRequest;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Agent\Request\InterruptRequest;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Agent\Response\AgentResponse;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Agent\SandboxAgentInterface;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Exception\SandboxOperationException;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Gateway\Constant\ResponseCode;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Gateway\Result\BatchStatusResult;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Gateway\Result\SandboxStatusResult;
use Dtyq\SuperMagic\Infrastructure\ExternalAPI\SandboxOS\Gateway\SandboxGatewayInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Agent消息应用服务
 * 提供高级Agent通信功能，包括自动初始化和状态管理.
 */
class AgentDomainService
{
    private LoggerInterface $logger;

    public function __construct(
        LoggerFactory $loggerFactory,
        private SandboxGatewayInterface $gateway,
        private SandboxAgentInterface $agent,
        private readonly FileProcessAppService $fileProcessAppService,
        private readonly FileAppService $fileAppService,
        private readonly MagicUserInfoAppService $userInfoAppService,
    ) {
        $this->logger = $loggerFactory->get('sandbox');
    }

    /**
     * 调用沙箱网关，创建沙箱容器，如果 sandboxId 不存在，系统会默认创建一个.
     */
    public function createSandbox(string $projectId, string $sandboxID): string
    {
        $this->logger->info('[Sandbox][App] Creating sandbox', [
            'project_id' => $projectId,
            'sandbox_id' => $sandboxID,
        ]);

        $result = $this->gateway->createSandbox(['project_id' => $projectId, 'sandbox_id' => $sandboxID]);

        if (! $result->isSuccess()) {
            $this->logger->error('[Sandbox][App] Failed to create sandbox', [
                'project_id' => $projectId,
                'sandbox_id' => $sandboxID,
                'error' => $result->getMessage(),
                'code' => $result->getCode(),
            ]);
            throw new SandboxOperationException('Create sandbox', $result->getMessage(), $result->getCode());
        }

        return $result->getData()['sandbox_id'];
    }

    /**
     * 获取沙箱状态
     *
     * @param string $sandboxId 沙箱ID
     * @return SandboxStatusResult 沙箱状态结果
     */
    public function getSandboxStatus(string $sandboxId): SandboxStatusResult
    {
        $this->logger->info('[Sandbox][App] Getting sandbox status', [
            'sandbox_id' => $sandboxId,
        ]);

        $result = $this->gateway->getSandboxStatus($sandboxId);

        if (! $result->isSuccess() && $result->getCode() !== ResponseCode::NOT_FOUND) {
            $this->logger->error('[Sandbox][App] Failed to get sandbox status', [
                'sandbox_id' => $sandboxId,
                'error' => $result->getMessage(),
                'code' => $result->getCode(),
            ]);
            throw new SandboxOperationException('Get sandbox status', $result->getMessage(), $result->getCode());
        }

        $this->logger->info('[Sandbox][App] Sandbox status retrieved', [
            'sandbox_id' => $sandboxId,
            'status' => $result->getStatus(),
        ]);

        return $result;
    }

    /**
     * 批量获取沙箱状态
     *
     * @param array $sandboxIds 沙箱ID数组
     * @return BatchStatusResult 批量沙箱状态结果
     */
    public function getBatchSandboxStatus(array $sandboxIds): BatchStatusResult
    {
        $this->logger->info('[Sandbox][App] Getting batch sandbox status', [
            'sandbox_ids' => $sandboxIds,
            'count' => count($sandboxIds),
        ]);

        $result = $this->gateway->getBatchSandboxStatus($sandboxIds);

        if (! $result->isSuccess() && $result->getCode() !== ResponseCode::NOT_FOUND) {
            $this->logger->error('[Sandbox][App] Failed to get batch sandbox status', [
                'sandbox_ids' => $sandboxIds,
                'error' => $result->getMessage(),
                'code' => $result->getCode(),
            ]);
            throw new SandboxOperationException('Get batch sandbox status', $result->getMessage(), $result->getCode());
        }

        $this->logger->info('[Sandbox][App] Batch sandbox status retrieved', [
            'requested_count' => count($sandboxIds),
            'returned_count' => $result->getTotalCount(),
            'running_count' => $result->getRunningCount(),
        ]);

        return $result;
    }

    public function initializeAgent(DataIsolation $dataIsolation, TaskContext $taskContext): void
    {
        $this->logger->info('[Sandbox][App] Initializing agent', [
            'sandbox_id' => $taskContext->getSandboxId(),
        ]);

        // 1. 构建初始化信息
        $config = $this->generateInitializationInfo($dataIsolation, $taskContext);

        // 2. 调用初始化接口
        $result = $this->agent->initAgent($taskContext->getSandboxId(), InitAgentRequest::fromArray($config));

        if (! $result->isSuccess()) {
            $this->logger->error('[Sandbox][App] Failed to initialize agent', [
                'sandbox_id' => $taskContext->getSandboxId(),
                'error' => $result->getMessage(),
                'code' => $result->getCode(),
            ]);
            throw new SandboxOperationException('Initialize agent', $result->getMessage(), $result->getCode());
        }
    }

    /**
     * 发送消息给 agent.
     */
    public function sendChatMessage(DataIsolation $dataIsolation, TaskContext $taskContext): void
    {
        $this->logger->info('[Sandbox][App] Sending chat message to agent', [
            'sandbox_id' => $taskContext->getSandboxId(),
        ]);

        $mentionsJsonStruct = $this->buildMentionsJsonStruct($dataIsolation, $taskContext->getTask()->getMentions(), $taskContext->getTask()->getProjectId());

        $attachmentUrls = [];
        if (! empty($mentionsJsonStruct)) {
            $attachmentUrls = $this->fileProcessAppService->getFilesWithMentions($dataIsolation, $mentionsJsonStruct);
        }

        // 构建参数
        $chatMessage = ChatMessageRequest::create(
            messageId: (string) IdGenerator::getSnowId(),
            userId: $dataIsolation->getCurrentUserId(),
            taskId: (string) $taskContext->getTask()->getId(),
            prompt: $taskContext->getTask()->getPrompt(),
            taskMode: $taskContext->getTask()->getTaskMode(),
            agentMode: $taskContext->getAgentMode(),
            attachments: $attachmentUrls,
            mentions: $mentionsJsonStruct,
            mcpConfig: $taskContext->getMcpConfig(),
        );

        $result = $this->agent->sendChatMessage($taskContext->getSandboxId(), $chatMessage);

        if (! $result->isSuccess()) {
            $this->logger->error('[Sandbox][App] Failed to send chat message to agent', [
                'sandbox_id' => $taskContext->getSandboxId(),
                'error' => $result->getMessage(),
                'code' => $result->getCode(),
            ]);
            throw new SandboxOperationException('Send chat message', $result->getMessage(), $result->getCode());
        }
    }

    /**
     * 发送中断消息给Agent.
     *
     * @param DataIsolation $dataIsolation 数据隔离上下文
     * @param string $sandboxId 沙箱ID
     * @param string $taskId 任务ID
     * @param string $reason 中断原因
     * @return AgentResponse 中断响应
     */
    public function sendInterruptMessage(
        DataIsolation $dataIsolation,
        string $sandboxId,
        string $taskId,
        string $reason,
    ): AgentResponse {
        $this->logger->info('[Sandbox][App] Sending interrupt message to agent', [
            'sandbox_id' => $sandboxId,
            'task_id' => $taskId,
            'user_id' => $dataIsolation->getCurrentUserId(),
            'reason' => $reason,
        ]);

        // 发送中断消息
        $messageId = (string) IdGenerator::getSnowId();
        $interruptRequest = InterruptRequest::create(
            $messageId,
            $dataIsolation->getCurrentUserId(),
            $taskId,
            $reason,
        );

        $response = $this->agent->sendInterruptMessage($sandboxId, $interruptRequest);

        if (! $response->isSuccess()) {
            $this->logger->error('[Sandbox][App] Failed to send interrupt message to agent', [
                'sandbox_id' => $sandboxId,
                'task_id' => $taskId,
                'user_id' => $dataIsolation->getCurrentUserId(),
                'reason' => $reason,
                'error' => $response->getMessage(),
                'code' => $response->getCode(),
            ]);
            throw new SandboxOperationException('Send interrupt message', $response->getMessage(), $response->getCode());
        }

        $this->logger->info('[Sandbox][App] Interrupt message sent to agent successfully', [
            'sandbox_id' => $sandboxId,
            'task_id' => $taskId,
            'user_id' => $dataIsolation->getCurrentUserId(),
            'reason' => $reason,
        ]);

        return $response;
    }

    /**
     * 获取工作区状态.
     *
     * @param string $sandboxId 沙箱ID
     * @return AgentResponse 工作区状态响应
     */
    public function getWorkspaceStatus(string $sandboxId): AgentResponse
    {
        $this->logger->debug('[Sandbox][App] Getting workspace status', [
            'sandbox_id' => $sandboxId,
        ]);

        $result = $this->agent->getWorkspaceStatus($sandboxId);

        if (! $result->isSuccess()) {
            $this->logger->error('[Sandbox][App] Failed to get workspace status', [
                'sandbox_id' => $sandboxId,
                'error' => $result->getMessage(),
                'code' => $result->getCode(),
            ]);
            throw new SandboxOperationException('Get workspace status', $result->getMessage(), $result->getCode());
        }

        $this->logger->debug('[Sandbox][App] Workspace status retrieved', [
            'sandbox_id' => $sandboxId,
            'status' => $result->getDataValue('status'),
        ]);

        return $result;
    }

    /**
     * 等待工作区就绪.
     * 轮询工作区状态，直到初始化完成、失败或超时.
     *
     * @param string $sandboxId 沙箱ID
     * @param int $timeoutSeconds 超时时间（秒），默认10分钟
     * @param int $intervalSeconds 轮询间隔（秒），默认2秒
     * @throws SandboxOperationException 当初始化失败或超时时抛出异常
     */
    public function waitForWorkspaceReady(string $sandboxId, int $timeoutSeconds = 600, int $intervalSeconds = 2): void
    {
        $this->logger->info('[Sandbox][App] Waiting for workspace to be ready', [
            'sandbox_id' => $sandboxId,
            'timeout_seconds' => $timeoutSeconds,
            'interval_seconds' => $intervalSeconds,
        ]);

        $startTime = time();
        $endTime = $startTime + $timeoutSeconds;

        while (time() < $endTime) {
            try {
                $response = $this->getWorkspaceStatus($sandboxId);

                $status = $response->getDataValue('status');

                $this->logger->debug('[Sandbox][App] Workspace status check', [
                    'sandbox_id' => $sandboxId,
                    'status' => $status,
                    'status_description' => WorkspaceStatus::getDescription($status),
                    'elapsed_seconds' => time() - $startTime,
                ]);

                // 状态为就绪时退出
                if (WorkspaceStatus::isReady($status)) {
                    $this->logger->info('[Sandbox][App] Workspace is ready', [
                        'sandbox_id' => $sandboxId,
                        'elapsed_seconds' => time() - $startTime,
                    ]);
                    return;
                }

                // 状态为错误时抛出异常
                if (WorkspaceStatus::isError($status)) {
                    $this->logger->error('[Sandbox][App] Workspace initialization failed', [
                        'sandbox_id' => $sandboxId,
                        'status' => $status,
                        'status_description' => WorkspaceStatus::getDescription($status),
                        'elapsed_seconds' => time() - $startTime,
                    ]);
                    throw new SandboxOperationException('Wait for workspace ready', 'Workspace initialization failed with status: ' . WorkspaceStatus::getDescription($status), 3001);
                }

                // 等待下一次轮询
                sleep($intervalSeconds);
            } catch (SandboxOperationException $e) {
                // 重新抛出沙箱操作异常
                throw $e;
            } catch (Throwable $e) {
                $this->logger->error('[Sandbox][App] Error while checking workspace status', [
                    'sandbox_id' => $sandboxId,
                    'error' => $e->getMessage(),
                    'elapsed_seconds' => time() - $startTime,
                ]);
                throw new SandboxOperationException('Wait for workspace ready', 'Error checking workspace status: ' . $e->getMessage(), 3002);
            }
        }

        // 超时
        $this->logger->error('[Sandbox][App] Workspace ready timeout', [
            'sandbox_id' => $sandboxId,
            'timeout_seconds' => $timeoutSeconds,
        ]);
        throw new SandboxOperationException('Wait for workspace ready', 'Workspace ready timeout after ' . $timeoutSeconds . ' seconds', 3003);
    }

    /**
     * 构建初始化消息.
     */
    private function generateInitializationInfo(DataIsolation $dataIsolation, TaskContext $taskContext): array
    {
        // 1. 获取上传配置信息
        $storageType = StorageBucketType::Private->value;
        $expires = 3600; // Credential valid for 1 hour
        // Create user authorization object
        $userAuthorization = new MagicUserAuthorization();
        $userAuthorization->setOrganizationCode($dataIsolation->getCurrentOrganizationCode());
        // Use unified FileAppService to get STS Token
        $stsConfig = $this->fileAppService->getStsTemporaryCredential($userAuthorization, $storageType, $taskContext->getTask()->getWorkDir(), $expires);

        // 2. 构建元数据
        $userInfoArray = $this->userInfoAppService->getUserInfo($dataIsolation->getCurrentUserId(), $dataIsolation);
        $userInfo = UserInfoValueObject::fromArray($userInfoArray);
        $messageMetadata = new MessageMetadata(
            agentUserId: $taskContext->getAgentUserId(),
            userId: $dataIsolation->getCurrentUserId(),
            organizationCode: $dataIsolation->getCurrentOrganizationCode(),
            chatConversationId: $taskContext->getChatConversationId(),
            chatTopicId: $taskContext->getChatTopicId(),
            instruction: $taskContext->getInstruction()->value,
            sandboxId: $taskContext->getSandboxId(),
            superMagicTaskId: (string) $taskContext->getTask()->getId(),
            userInfo: $userInfo
        );

        return [
            'message_id' => (string) IdGenerator::getSnowId(),
            'user_id' => $dataIsolation->getCurrentUserId(),
            'project_id' => (string) $taskContext->getTask()->getProjectId(),
            'type' => MessageType::Init->value,
            'upload_config' => $stsConfig,
            'message_subscription_config' => [
                'method' => 'POST',
                'url' => config('super-magic.sandbox.callback_host', '') . '/api/v1/super-agent/tasks/deliver-message',
                'headers' => [
                    'token' => config('super-magic.sandbox.token', ''),
                ],
            ],
            'sts_token_refresh' => [
                'method' => 'POST',
                'url' => config('super-magic.sandbox.callback_host', '') . '/api/v1/super-agent/file/refresh-sts-token',
                'headers' => [
                    'token' => config('super-magic.sandbox.token', ''),
                ],
            ],
            'metadata' => $messageMetadata->toArray(),
            'task_mode' => $taskContext->getTask()->getTaskMode(),
            'agent_mode' => $taskContext->getAgentMode(),
            'magic_service_host' => config('super-magic.sandbox.callback_host', ''),
        ];
    }

    /**
     * 构建 mentions 的 JSON 结构数组，只包含 type, file_path, file_url 三个字段.
     *
     * @param DataIsolation $dataIsolation 数据隔离对象
     * @param null|string $mentionsJson mentions 的 JSON 字符串
     * @param null|int $projectId 项目 ID
     * @return array 处理后的 mentions 数组
     */
    private function buildMentionsJsonStruct(DataIsolation $dataIsolation, ?string $mentionsJson, ?int $projectId): array
    {
        $mentionsJsonStruct = [];

        if (empty($mentionsJson)) {
            return $mentionsJsonStruct;
        }

        $mentions = json_decode($mentionsJson, true);
        if (empty($mentions) || ! is_array($mentions)) {
            return $mentionsJsonStruct;
        }

        $fileIds = array_filter(array_column($mentions, 'file_id'));
        if (empty($fileIds)) {
            return $mentionsJsonStruct;
        }

        $filesWithUrl = $this->fileProcessAppService->getFilesWithUrl($dataIsolation, $fileIds, $projectId);

        // 提前处理 file_id，将文件数据转换为以 file_id 为键的关联数组，避免双重循环
        $fileIdToFileMap = [];
        foreach ($filesWithUrl as $file) {
            $fileIdToFileMap[$file['file_id']] = $file;
        }

        // 构建新的数据结构，只包含 type, file_path, file_url
        foreach ($mentions as $mention) {
            $fileId = $mention['file_id'] ?? null;
            if (! $fileId) {
                continue;
            }

            // 从原始 mentions 中获取 type
            $type = $mention['type'] ?? MentionType::PROJECT_FILE->value;

            // 直接通过 file_id 获取对应的文件信息
            $matchedFile = $fileIdToFileMap[$fileId] ?? null;

            if ($matchedFile) {
                // 从 file_url 中提取 file_path
                $filePath = $mention['file_path'] ?? '';
                if (empty($filePath) && ! empty($matchedFile['file_url'])) {
                    // 使用 FileAssembler::formatPath 从 URL 中提取路径
                    $filePath = FileAssembler::formatPath($matchedFile['file_url']);
                }
                $mentionFile = ['type' => $type, 'file_path' => $filePath, 'file_metadata' => $matchedFile];
                $mentionsJsonStruct[] = $mentionFile;
            }
        }
        return $mentionsJsonStruct;
    }
}
