<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Interfaces\SuperAgent\Facade;

use App\ErrorCode\GenericErrorCode;
use App\Infrastructure\Core\Exception\BusinessException;
use App\Infrastructure\Core\Exception\ExceptionBuilder;
use App\Infrastructure\Util\Context\RequestContext;
use Dtyq\ApiResponse\Annotation\ApiResponse;
use Dtyq\SuperMagic\Application\SuperAgent\Service\TaskAppService;
use Dtyq\SuperMagic\Application\SuperAgent\Service\TopicTaskAppService;
use Dtyq\SuperMagic\Application\SuperAgent\Service\WorkspaceAppService;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Request\CreateTaskApiRequestDTO;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Request\GetFileUrlsRequestDTO;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Request\GetTaskFilesRequestDTO;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\TopicTaskMessageDTO;
use Hyperf\HttpServer\Contract\RequestInterface;
use App\Interfaces\Authorization\Web\MagicUserAuthorization;
use Qbhy\HyperfAuth\AuthManager;
use Dtyq\SuperMagic\Application\SuperAgent\Service\ProjectAppService;
use Dtyq\SuperMagic\Application\SuperAgent\Service\TopicAppService;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Request\SaveWorkspaceRequestDTO;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Request\UserInfoRequestDTO;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Response\InitSandboxResponseDTO;
use Dtyq\SuperMagic\Application\SuperAgent\Service\AgentAppService;
use Throwable;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Request\CreateProjectRequestDTO;
use Dtyq\SuperMagic\Interfaces\SuperAgent\DTO\Request\SaveTopicRequestDTO;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\UserDomainService;
use Dtyq\SuperMagic\Application\SuperAgent\Service\HandleApiMessageAppService;
use App\Domain\Contact\Entity\ValueObject\DataIsolation;
use Dtyq\SuperMagic\Application\SuperAgent\DTO\UserMessageDTO;
use App\Domain\Contact\Entity\ValueObject\UserType;


class SandboxApi extends AbstractApi
{
    public function __construct(
        protected RequestInterface $request,
        protected WorkspaceAppService $workspaceAppService,
        protected TopicTaskAppService $topicTaskAppService,
        protected TaskAppService $taskAppService,
        protected ProjectAppService $projectAppService,
        protected TopicAppService $topicAppService,
        protected UserDomainService $userDomainService,
        protected HandleApiMessageAppService $handleApiMessageAppService,
        protected AgentAppService $agentAppService,
    ) {
        parent::__construct($request);
    }


    #[ApiResponse('low_code')]
    public function getSandboxStatus(RequestContext $requestContext): array
    {
        $topicId = $this->request->input('topic_id', '');

        if (empty($topicId)) {
            ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'topic_id is required');
        }

        $topic = $this->topicAppService->getTopic($requestContext, (int)$topicId);
        $sandboxId = $topic->getSandboxId();

        if (empty($sandboxId)) {
            ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'sandbox_id is required');
        }

        $result = $this->agentAppService->getSandboxStatus($sandboxId);
        return $result->toArray();
    }


    //创建一个任务，支持agent、tool、custom三种模式，鉴权使用api-key进行鉴权
    #[ApiResponse('low_code')]
    public function initSandboxByApiKey(RequestContext $requestContext, CreateTaskApiRequestDTO $requestDTO): array
    {
        // 从请求中创建DTO并验证参数
        $requestDTO = CreateTaskApiRequestDTO::fromRequest($this->request);

        // 从请求中创建DTO
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'The api key of header is required');
        }
        // $userInfoRequestDTO = new UserInfoRequestDTO(['uid' => $apiKey]);

        $userEntity = $this->handleApiMessageAppService->getUserAuthorization($apiKey,"");

        $magicUserAuthorization=MagicUserAuthorization::fromUserEntity($userEntity);

        $requestContext->setUserAuthorization($magicUserAuthorization);

        return $this->initSandbox($requestContext, $requestDTO, $magicUserAuthorization);
    }


    public function initSandboxByAuthorization(RequestContext $requestContext): array
    {
        $topicId = $this->request->input('topic_id', '');

        if (empty($topicId)) {
            ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'topic_id is required');
        }

        $topic = $this->topicAppService->getTopic($requestContext, (int)$topicId);


        $projectId = $topic->getProjectId();

        $project = $this->projectAppService->getProjectNotUserId((int)$projectId);

        $workspaceId = (string) $project->getWorkspaceId();

        $requestDTO = new InitSandboxRequestDTO();
        $requestDTO->setWorkspaceId($workspaceId);
        $requestDTO->setProjectId($projectId);
        $requestDTO->setTopicId($topicId);
        $requestDTO = InitSandboxRequestDTO::fromRequest($this->request);
        $requestContext->setUserAuthorization($this->getAuthorization());
        return $this->initSandbox($requestContext, $requestDTO, $this->getAuthorization());
    }


    public function initSandbox(RequestContext $requestContext, InitSandboxRequestDTO $requestDTO, $magicUserAuthorization): array
    {


        // 判断工作区是否存在，不存在则初始化工作区
        $this->initWorkspace($requestContext, $requestDTO);

        // 判断项目是否存在，不存在则初始化项目
        $this->initProject($requestContext, $requestDTO, $magicUserAuthorization->getId());

        // 判断话题是否存在，不存在则初始化话题
        $this->initTopic($requestContext, $requestDTO);

        $requestDTO->setConversationId($requestDTO->getTopicId());

        $initSandboxResponseDTO = new InitSandboxResponseDTO();

        $initSandboxResponseDTO->setWorkspaceId($requestDTO->getWorkspaceId());
        $initSandboxResponseDTO->setProjectId($requestDTO->getProjectId());
        $initSandboxResponseDTO->setProjectMode($requestDTO->getProjectMode());
        $initSandboxResponseDTO->setTopicId($requestDTO->getTopicId());
        $initSandboxResponseDTO->setConversationId($requestDTO->getTopicId());
        $dataIsolation = new DataIsolation();
        $dataIsolation->setCurrentUserId((string) $magicUserAuthorization->getId());
        $dataIsolation->setThirdPartyOrganizationCode($magicUserAuthorization->getOrganizationCode());
        $dataIsolation->setCurrentOrganizationCode($magicUserAuthorization->getOrganizationCode());
        $dataIsolation->setUserType(UserType::Human);
        //  $dataIsolation = new DataIsolation($userEntity->getId(), $userEntity->getOrganizationCode(), $userEntity->getWorkDir());

        $userMessage = [
            'chat_topic_id' => $requestDTO->getTopicId(),
            'topic_id' => (int) $requestDTO->getTopicId(),
            'chat_conversation_id' => $requestDTO->getConversationId(),
            'prompt' => $requestDTO->getPrompt(),
            'attachments' => null,
            'mentions' => null,
            'agent_user_id' => (string) $magicUserAuthorization->getId(),
            'agent_mode' => '',
            'task_mode' => '',
        ];
        $userMessageDTO = UserMessageDTO::fromArray($userMessage);
        // $this->handleApiMessageAppService->handleApiMessage($dataIsolation, $userMessageDTO);
        // $userMessageDTO->setAgentMode($requestDTO->getProjectMode());
        $result = $this->handleTaskMessageAppService->initSandbox($dataIsolation, $userMessageDTO);
        $initSandboxResponseDTO->setSandboxId($result['sandbox_id']);
        $initSandboxResponseDTO->setTaskId($result['task_id']);

        return $initSandboxResponseDTO->toArray();
    }



    public function initWorkspace(RequestContext $requestContext, InitSandboxRequestDTO &$requestDTO)
    {
           //判断工作区是否存在，不存在则初始化工作区
           $workspaceId = $requestDTO->getWorkspaceId();
           if ($workspaceId > 0) {
               $workspace = $this->workspaceAppService->getWorkspaceDetail(        $requestContext,    (int)$workspaceId);
               if (empty($workspace)) {
                   //抛异常，工作区不存在
                   ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'workspace_not_found');
               }
           }else{
               $saveWorkspaceRequestDTO = new SaveWorkspaceRequestDTO();
               $saveWorkspaceRequestDTO->setWorkspaceName("默认工作区");
               $workspace = $this->workspaceAppService->createWorkspace($requestContext, $saveWorkspaceRequestDTO);
               $workspaceId = $workspace->getId();
           }

           $requestDTO->setWorkspaceId($workspaceId);
    }

    public function initProject(RequestContext $requestContext, InitSandboxRequestDTO &$requestDTO, string $userId): void
    {
         //判断项目是否存在，不存在则初始化项目
        $projectId = $requestDTO->getProjectId();

        if ($projectId > 0) {
            $project = $this->projectAppService->getProject((int)$projectId, $userId);
            if (empty($project)) {
                //抛异常，项目不存在
                ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'project_not_found');
            }
        }else{
            $saveProjectRequestDTO = new CreateProjectRequestDTO();
            $saveProjectRequestDTO->setProjectName("默认项目");
            $saveProjectRequestDTO->setWorkspaceId((string)$requestDTO->getWorkspaceId());
            $saveProjectRequestDTO->setProjectMode($requestDTO->getProjectMode());
            $project = $this->projectAppService->createProject($requestContext, $saveProjectRequestDTO);
            if(!empty($project['project'])){
                $projectId = $project['project']['id'];
            }else{
                ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'project_not_found');
            }
        }

        $requestDTO->setProjectId($projectId);
    }

    public function initTopic(RequestContext $requestContext, CreateTaskApiRequestDTO &$requestDTO): void{

        // 判断话题是否存在，不存在则初始化话题
        $topicId = $requestDTO->getTopicId();
        if ($topicId > 0) {
            try {
                $topic = $this->topicAppService->getTopic($requestContext, (int) $topicId);
            } catch (Throwable $e) {
                // 抛异常，话题不存在
                ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'topic_not_found');
            }
        } else {
            $saveTopicRequestDTO = new SaveTopicRequestDTO();
            $saveTopicRequestDTO->setTopicName('默认话题');
            $saveTopicRequestDTO->setProjectId((string) $requestDTO->getProjectId());
            $saveTopicRequestDTO->setWorkspaceId((string) $requestDTO->getWorkspaceId());
            $topic = $this->topicAppService->createTopic($requestContext, $saveTopicRequestDTO);
            if (! empty($topic->getId())) {
                $topicId = $topic->getId();
            } else {
                ExceptionBuilder::throw(GenericErrorCode::ParameterMissing, 'topic_not_found');
            }
        }

        $requestDTO->setTopicId($topicId);
        $requestDTO->setConversationId($topicId);

        $initSandboxResponseDTO = new InitSandboxResponseDTO();

        $initSandboxResponseDTO->setWorkspaceId($requestDTO->getWorkspaceId());
        $initSandboxResponseDTO->setProjectId($requestDTO->getProjectId());
        $initSandboxResponseDTO->setProjectMode($requestDTO->getProjectMode());
        $initSandboxResponseDTO->setTopicId($requestDTO->getTopicId());
        $initSandboxResponseDTO->setConversationId($requestDTO->getTopicId());
        $dataIsolation = new DataIsolation();
        $dataIsolation->setCurrentUserId((string) $userEntity->getUserId());
        $dataIsolation->setThirdPartyOrganizationCode($userEntity->getOrganizationCode());
        $dataIsolation->setCurrentOrganizationCode($userEntity->getOrganizationCode());
        $dataIsolation->setUserType(UserType::Human);
        //  $dataIsolation = new DataIsolation($userEntity->getId(), $userEntity->getOrganizationCode(), $userEntity->getWorkDir());

        $userMessage = [
            'chat_topic_id' => $requestDTO->getTopicId(),
            'topic_id' => (int) $requestDTO->getTopicId(),
            'chat_conversation_id' => $requestDTO->getConversationId(),
            'prompt' => $requestDTO->getPrompt(),
            'attachments' => null,
            'mentions' => null,
            'agent_user_id' => (string) $userEntity->getId(),
            'agent_mode' => '',
            'task_mode' => '',
        ];
        $userMessageDTO = UserMessageDTO::fromArray($userMessage);
        // $this->handleApiMessageAppService->handleApiMessage($dataIsolation, $userMessageDTO);
        // $userMessageDTO->setAgentMode($requestDTO->getProjectMode());
        $result = $this->handleTaskMessageAppService->initSandbox($dataIsolation, $userMessageDTO);
        $initSandboxResponseDTO->setSandboxId($result['sandbox_id']);
        $initSandboxResponseDTO->setTaskId($result['task_id']);

        return $initSandboxResponseDTO->toArray();
    }
}
