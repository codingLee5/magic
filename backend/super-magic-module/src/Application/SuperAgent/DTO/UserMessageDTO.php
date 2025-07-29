<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Application\SuperAgent\DTO;

use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\ChatInstruction;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TopicMode;

/**
 * User message DTO for initializing agent task.
 */
class UserMessageDTO
{
    public function __construct(
        private readonly string $agentUserId,
        private readonly string $chatConversationId,
        private readonly string $chatTopicId,
        private readonly int $topicId,
        private readonly string $prompt,
        private readonly ?string $attachments = null,
        private readonly ?string $mentions = null,
        private readonly ChatInstruction $instruction = ChatInstruction::Normal,
        private readonly TopicMode $topicMode = TopicMode::GENERAL,
        // $taskMode 即将废弃，请勿使用
        private readonly string $taskMode = '',
        private readonly ?string $rawContent = null,
        private readonly array $mcpConfig = [],
    ) {
    }

    public function getAgentUserId(): string
    {
        return $this->agentUserId;
    }

    public function getChatConversationId(): string
    {
        return $this->chatConversationId;
    }

    public function getChatTopicId(): string
    {
        return $this->chatTopicId;
    }

    public function getTopicId(): int
    {
        return $this->topicId;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getAttachments(): ?string
    {
        return $this->attachments;
    }

    public function getMentions(): ?string
    {
        return $this->mentions ?? null;
    }

    public function getInstruction(): ChatInstruction
    {
        return $this->instruction;
    }

    public function getTopicMode(): TopicMode
    {
        return $this->topicMode;
    }

    public function getTaskMode(): string
    {
        return $this->taskMode;
    }

    public function getRawContent(): ?string
    {
        return $this->rawContent;
    }

    public function getMcpConfig(): array
    {
        return $this->mcpConfig;
    }

    public function setMcpConfig(array $mcpConfig): void
    {
        $this->mcpConfig = $mcpConfig;
    }

    /**
     * Create DTO from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agentUserId: $data['agent_user_id'] ?? $data['agentUserId'] ?? '',
            chatConversationId: $data['chat_conversation_id'] ?? $data['chatConversationId'] ?? '',
            chatTopicId: $data['chat_topic_id'] ?? $data['chatTopicId'] ?? '',
            topicId: $data['topic_id'] ?? $data['topicId'] ?? 0,
            prompt: $data['prompt'] ?? '',
            attachments: $data['attachments'] ?? null,
            mentions: $data['mentions'] ?? null,
            instruction: isset($data['instruction'])
                ? ChatInstruction::tryFrom($data['instruction']) ?? ChatInstruction::Normal
                : ChatInstruction::Normal,
            topicMode: isset($data['topic_mode']) || isset($data['topicMode'])
                ? TopicMode::tryFrom($data['topic_mode'] ?? $data['topicMode']) ?? TopicMode::GENERAL
                : TopicMode::GENERAL,
            taskMode: $data['task_mode'] ?? $data['taskMode'] ?? '',
            rawContent: $data['raw_content'] ?? $data['rawContent'] ?? null,
            mcpConfig: $data['mcp_config'] ?? $data['mcpConfig'] ?? [],
        );
    }

    /**
     * Convert DTO to array.
     */
    public function toArray(): array
    {
        return [
            'agent_user_id' => $this->agentUserId,
            'chat_conversation_id' => $this->chatConversationId,
            'chat_topic_id' => $this->chatTopicId,
            'topic_id' => $this->topicId,
            'prompt' => $this->prompt,
            'attachments' => $this->attachments,
            'mentions' => $this->mentions,
            'instruction' => $this->instruction->value,
            'topic_mode' => $this->topicMode->value,
            'task_mode' => $this->taskMode,
            'raw_content' => $this->rawContent,
            'mcp_config' => $this->mcpConfig,
        ];
    }
}
