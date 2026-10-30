<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\FileAsset;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowExtrasBundle\Attribute\Transition;

#[AsWorkflow(name: self::WORKFLOW_NAME, supports: [FileAsset::class], description: 'A file asset: probe it, then upload it')]
class FileAssetWFDefinition
{
    public const WORKFLOW_NAME = 'FileAssetWorkflow';

    #[Place(initial: true, description: 'File registered')]
    public const PLACE_NEW = 'new';

    #[Place(description: 'File probed')]
    public const PLACE_PROBED = 'probed';

    #[Place(description: 'File uploaded')]
    public const PLACE_UPLOADED = 'uploaded';

    #[Transition(from: self::PLACE_NEW, to: self::PLACE_PROBED, description: 'Probe the file')]
    public const TRANSITION_PROBE = 'probe';

    #[Transition(from: self::PLACE_PROBED, to: self::PLACE_UPLOADED, description: 'Upload the file')]
    public const TRANSITION_UPLOAD = 'upload';
}
