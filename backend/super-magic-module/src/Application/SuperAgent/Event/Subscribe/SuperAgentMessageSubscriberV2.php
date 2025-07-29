<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Application\SuperAgent\Event\Subscribe;

use App\Application\Chat\Service\MagicAgentEventAppService;
use App\Domain\Chat\DTO\Message\MagicMessageStruct;
use App\Domain\Chat\DTO\Message\TextContentInterface;
use App\Domain\Chat\Event\Agent\UserCallAgentEvent;
use App\Domain\Chat\Service\MagicConversationDomainService;
use App\Domain\Contact\Entity\ValueObject\DataIsolation;
use App\Domain\MCP\Entity\ValueObject\MCPDataIsolation;
use App\Interfaces\Chat\Assembler\SeqAssembler;
use Dtyq\SuperMagic\Application\SuperAgent\DTO\UserMessageDTO;
use Dtyq\SuperMagic\Application\SuperAgent\Service\HandleUserMessageAppService;
use Dtyq\SuperMagic\Application\SuperAgent\Service\TaskAppService;
use Dtyq\SuperMagic\Domain\SuperAgent\Constant\AgentConstant;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\ChatInstruction;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TaskMode;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TopicMode;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Super Agent Service.
 *
 * Responsible for publishing agent messages based on AI code processing
 */
class SuperAgentMessageSubscriberV2 extends MagicAgentEventAppService
{
    protected LoggerInterface $logger;

    public function __construct(
        protected readonly TaskAppService $SuperAgentAppService,
        protected readonly HandleUserMessageAppService $handleUserMessageAppService,
        protected readonly LoggerFactory $loggerFactory,
        MagicConversationDomainService $magicConversationDomainService,
    ) {
        $this->logger = $loggerFactory->get(get_class($this));
        parent::__construct($magicConversationDomainService);
    }

    public function agentExecEvent(UserCallAgentEvent $userCallAgentEvent)
    {
        // Determine if Super Magic needs to be called
        if ($userCallAgentEvent->agentAccountEntity->getAiCode() === AgentConstant::SUPER_MAGIC_CODE) {
            $this->handlerSuperMagicMessage($userCallAgentEvent);
        } else {
            // Process messages through normal agent handling
            parent::agentExecEvent($userCallAgentEvent);
        }
    }

    private function handlerSuperMagicMessage(UserCallAgentEvent $userCallAgentEvent): void
    {
        try {
            $this->logger->info(sprintf(
                'Received super agent message, event: %s',
                json_encode($userCallAgentEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
            /** @var null|MagicMessageStruct $messageStruct */
            $messageStruct = $userCallAgentEvent->messageEntity?->getContent();
            if ($messageStruct instanceof TextContentInterface) {
                // 可能是富文本，需要处理 @
                $prompt = $messageStruct->getTextContent();
            } else {
                $prompt = '';
            }
            // 更改附件的定义，附件是用户 @了 文件/mcp/agent 等
            $superAgentExtra = $messageStruct->getExtra()?->getSuperAgent();
            $mentions = $superAgentExtra?->getMentionsJsonStruct();
            // Extract necessary information
            $conversationId = $userCallAgentEvent->seqEntity->getConversationId() ?? '';
            $chatTopicId = $userCallAgentEvent->seqEntity->getExtra()?->getTopicId() ?? '';
            $organizationCode = $userCallAgentEvent->senderUserEntity->getOrganizationCode() ?? '';
            $userId = $userCallAgentEvent->senderUserEntity->getUserId() ?? '';
            $agentUserId = $userCallAgentEvent->agentUserEntity->getUserId() ?? '';
            $attachments = $userCallAgentEvent->messageEntity?->getContent()?->getAttachments() ?? [];
            $instructions = $userCallAgentEvent->messageEntity?->getContent()?->getInstructs() ?? [];

            // Parameter validation
            if (empty($conversationId) || empty($chatTopicId) || empty($organizationCode)
                || empty($userId) || empty($agentUserId)) {
                $this->logger->error(sprintf(
                    'Incomplete message parameters, conversation_id: %s, topic_id: %s, organization_code: %s, user_id: %s, agent_user_id: %s',
                    $conversationId,
                    $chatTopicId,
                    $organizationCode,
                    $userId,
                    $agentUserId
                ));
                return;
            }

            // Create data isolation object
            $dataIsolation = DataIsolation::create($organizationCode, $userId);

            // Convert attachments array to JSON
            $attachmentsJson = ! empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : '';

            // Convert mentions array to JSON if not null
            $mentionsJson = ! empty($mentions) ? json_encode($mentions, JSON_UNESCAPED_UNICODE) : null;

            // Parse instruction information
            [$chatInstructs, $taskMode] = $this->parseInstructions($instructions);

            // Parse topic mode from super agent extra
            $topicModeValue = $superAgentExtra?->getTopicPattern();
            $topicMode = $topicModeValue ? TopicMode::tryFrom($topicModeValue) ?? TopicMode::GENERAL : TopicMode::GENERAL;

            // raw content
            $rawContent = $this->getRawContent($userCallAgentEvent);

            // MCP config
            $mcpDataIsolation = MCPDataIsolation::create(
                $dataIsolation->getCurrentOrganizationCode(),
                $dataIsolation->getCurrentUserId()
            );

            // Create user message DTO
            $userMessageDTO = new UserMessageDTO(
                agentUserId: $agentUserId,
                chatConversationId: $conversationId,
                chatTopicId: $chatTopicId,
                topicId: (int) $chatTopicId,
                prompt: $prompt,
                attachments: $attachmentsJson,
                mentions: $mentionsJson,
                instruction: $chatInstructs,
                topicMode: $topicMode,
                taskMode: $taskMode,
                rawContent: $rawContent,
                mcpConfig: []
            );

            $taskContext = $this->handleUserMessageAppService->getTaskContext($dataIsolation, $userMessageDTO);
            $mcpConfig = $this->supperMagicAgentMCP?->createChatMessageRequestMcpConfig($mcpDataIsolation, $taskContext) ?? [];
            $userMessageDTO->setMcpConfig($mcpConfig);

            // Call handle user message service
            if ($chatInstructs == ChatInstruction::Interrupted) {
                $this->handleUserMessageAppService->handleInternalMessage($dataIsolation, $userMessageDTO);
            } else {
                $this->handleUserMessageAppService->handleChatMessage($dataIsolation, $userMessageDTO);
            }
            $this->logger->info('Super agent message processing completed');

            return;
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Failed to process super agent message: %s,file:%s,line:%s, event: %s,trace:%s',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($userCallAgentEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $e->getTraceAsString()
            ));

            return; // Acknowledge message even on error to avoid message accumulation
        }
    }

    private function getRawContent(UserCallAgentEvent $userCallAgentEvent): string
    {
        $seqObject = SeqAssembler::getClientSeqStruct($userCallAgentEvent->seqEntity, $userCallAgentEvent->messageEntity);
        try {
            $type = $seqObject->getSeq()->getMessage()->getType() ?? 'undefined';
            $data = [
                'type' => $type, $type => $seqObject->getSeq()->getMessage()->getContent(),
            ];
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Parse instructions, extract chat instruction and task mode.
     *
     * @param array $instructions Instruction array
     * @return array Returns [ChatInstruction, string taskMode]
     */
    private function parseInstructions(array $instructions): array
    {
        // Default values
        $chatInstructs = ChatInstruction::Normal;
        $taskMode = '';

        if (empty($instructions)) {
            return [$chatInstructs, $taskMode];
        }

        // Check for matching chat instructions or task modes
        foreach ($instructions as $instruction) {
            $value = $instruction['value'] ?? '';

            // First try to match chat instruction
            $tempChatInstruct = ChatInstruction::tryFrom($value);
            if ($tempChatInstruct !== null) {
                $chatInstructs = $tempChatInstruct;
                continue; // Continue looking for task mode after finding chat instruction
            }

            // Try to match task mode
            $tempTaskMode = TaskMode::tryFrom($value);
            if ($tempTaskMode !== null) {
                $taskMode = $tempTaskMode->value;
                break; // Can end loop after finding task mode
            }
        }
        return [$chatInstructs, $taskMode];
    }
}
